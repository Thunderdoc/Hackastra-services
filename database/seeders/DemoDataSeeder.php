<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DemoDataSeeder — enriches the demo database so a buyer evaluating
 * Hackstra sees a living school, not empty pages.
 *
 * The base seeders create users, classes, sections, subjects and student
 * records, but no payments, no attendance, no library and no exam data —
 * so the Super Admin dashboard's fee donut and attendance donut render
 * 0% and several modules look dead. This seeder fills exactly those
 * gaps using ONLY rows the base seeders already created.
 *
 * Safety: every domain is guarded by a row count on its own table, and
 * the whole seeder no-ops when both payment_records and attendances
 * already have rows. It is therefore safe to run on any database,
 * although the entrypoints only seed empty databases anyway.
 *
 * Field provenance (every column below was read from the migration /
 * model / repository named beside it — nothing is invented):
 *  - payments / payment_records / receipts: migrations
 *    2018_11_03_210758, 2018_11_03_210817, 2018_11_06_083707 and
 *    PaymentRepo + PaymentController@pay_now (balance = amount - paid,
 *    paid = 1 when balance < 1, one Receipt per payment transaction).
 *  - attendances: RETIRED (Workstream AR) — the day-wise demo rows
 *    are no longer seeded; attendance demo data is period registers
 *    from PeriodAttendanceSeeder. The provenance note for the legacy
 *    rows: migration 2026_10_21_000002, Attendance model status
 *    constants, the retired AttendanceController@store shape
 *    (student_record_id + date unique, status + recorded_by).
 *  - books / book_requests: migrations 2018_10_31_191358 /
 *    2018_10_31_192540, Book::types(), BookRequestController@store
 *    (status 'issued', returned 0, issued_copies incremented).
 *  - school_events: migration 2026_10_21_000001, SchoolEvent::types(),
 *    SchoolEventController@store (created_by set).
 *  - exams / marks / exam_records: migrations 2018_10_04_224910,
 *    2018_10_06_224846, 2018_10_18_205842 and the exact field pattern
 *    DashboardDemoDataSeeder already uses (t1, t2, tca, exm, tex1 for
 *    a term-1 exam; grade resolved from the grades table ranges).
 */
class DemoDataSeeder extends Seeder
{
    public function run()
    {
        // Global idempotency guard: a database that already has payment
        // records AND attendance is a working school, not a fresh demo.
        if (DB::table('payment_records')->count() > 0
            && DB::table('attendances')->count() > 0) {
            return;
        }

        $session = (string) (DB::table('settings')
            ->where('type', 'current_session')->value('description') ?: '2026-2027');

        $students = DB::table('student_records')
            ->where('grad', 0)
            ->orderBy('id')
            ->get();

        // Demo parent linkage: the seeded demo parent account
        // (parent@hackstra.app) ships with no children linked, which
        // makes every parent page (children, fees, attendance, admit
        // cards) render its empty state in the sales demo. Link the
        // first two demo students to it so the parent portal shows
        // real data. Deterministic and idempotent.
        $demoParentId = DB::table('users')
            ->where('email', 'parent@hackstra.app')->value('id');
        if ($demoParentId) {
            $firstTwo = DB::table('student_records')
                ->where('grad', 0)->orderBy('id')->limit(2)->pluck('id');
            DB::table('student_records')
                ->whereIn('id', $firstTwo)
                ->update(['my_parent_id' => $demoParentId]);
        }

        if ($students->isEmpty()) {
            return;
        }

        // Batch 6 (D17): every step runs in isolation, and the teacher
        // timetables run FIRST. They used to run last, so any upstream
        // throw (the entrypoint only warns and keeps serving) left the
        // teacher home showing "No periods are scheduled" while the
        // rest of the demo looked complete. Nothing else in this seeder
        // feeds the timetables — their inputs (users, subjects, classes)
        // are seeded before DemoDataSeeder ever runs.
        $this->step(function () use ($session) { $this->seedTeacherTimetables($session); });
        $this->step(function () use ($students, $session) { $this->seedPayments($students, $session); });
        // Workstream AR (period-only unification): the day-wise
        // seedAttendance() step is NO LONGER CALLED — attendance demo
        // data comes from PeriodAttendanceSeeder alone, so a fresh
        // demo tells one story (period registers) instead of two.
        // The method itself is kept below, un-called, as the record
        // of the retired day-wise demo.
        $this->step(function () use ($students) { $this->seedLibrary($students); });
        $this->step(function () { $this->seedEvents(); });
        $this->step(function () use ($students, $session) { $this->seedExamAndMarks($students, $session); });
        $this->step(function () { $this->seedCirculars(); });
        $this->step(function () { $this->seedLibrarianUser(); });
        // Workstream CS1: LMS demo content (assignments, submissions,
        // study materials) lives in its own seeder class.
        $this->step(function () { $this->call(LmsDemoSeeder::class); });
        $this->step(function () { $this->seedFeedback(); });
    }

    /** Run one demo step in isolation; log the failure and continue. */
    protected function step(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            error_log('DemoDataSeeder step failed: ' . $e->getMessage());
            /* 2026-10-24: error_log alone is invisible when the seed
               PHP runs with log_errors off (the static PHP used
               locally, and a swallowed step once left a half-seeded
               demo with no trace). Surface it on the console too. */
            if ($this->command) {
                $this->command->warn('DemoDataSeeder step failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Fee definitions + a payment record (and receipt, where money moved)
     * for every demo student.
     *
     * Definitions: three per class — Term 1 Tuition Fee, Transport Fee,
     * Exam Fee — with the tuition level banded by the class's type
     * (Nursery / Primary / Junior Secondary / Senior Secondary).
     * Records: ~70% of students fully paid, ~20% partially paid,
     * ~10% unpaid, assigned deterministically by student order so a
     * re-seed always produces the same demo.
     */
    protected function seedPayments($students, string $session): void
    {
        if (DB::table('payment_records')->count() > 0) {
            return;
        }

        $classes = DB::table('my_classes')
            ->leftJoin('class_types', 'class_types.id', '=', 'my_classes.class_type_id')
            ->orderBy('my_classes.id')
            ->get(['my_classes.id', 'my_classes.name', 'class_types.code as type_code']);

        $paymentsByClass = [];
        foreach ($classes as $class) {
            $existing = DB::table('payments')
                ->where('my_class_id', $class->id)
                ->where('year', $session)
                ->orderBy('id')
                ->get();

            if ($existing->isNotEmpty()) {
                $paymentsByClass[$class->id] = $existing;
                continue;
            }

            $ids = [];
            foreach ($this->feeSchedule($class->type_code, $class->name) as $i => $fee) {
                $ids[] = DB::table('payments')->insertGetId([
                    'title' => $fee[0],
                    'amount' => $fee[1],
                    'ref_no' => sprintf('DEMO-PAY-%02d-%d', $class->id, $i + 1),
                    'method' => 'cash',
                    'my_class_id' => $class->id,
                    'description' => 'Demo fee for ' . $class->name . ' (' . $session . ').',
                    'year' => $session,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $paymentsByClass[$class->id] = DB::table('payments')
                ->whereIn('id', $ids)->orderBy('id')->get();
        }

        // Payment buckets: rank students by a stable hash so the split
        // lands at ~70% fully paid / ~20% partially / ~10% unpaid for
        // any class size, while staying scattered through every class
        // list instead of appearing in paid/unpaid blocks.
        $ranked = $students->sortBy(function ($s) {
            return $this->hashOf('fees|' . $s->user_id);
        })->values();
        $fullCount = (int) round($ranked->count() * 0.7);
        $partialCount = (int) round($ranked->count() * 0.2);
        $bucketByUserId = [];
        foreach ($ranked as $rank => $s) {
            $bucketByUserId[$s->user_id] = $rank < $fullCount
                ? 'full'
                : ($rank < $fullCount + $partialCount ? 'partial' : 'unpaid');
        }

        /* Per-class inserts (2026-10-24): the old version built ALL
           9,123 payment_record rows + 8,211 receipt rows in memory
           first — each with its own now() Carbon objects — which
           jumped the seed process from 44MB to the 128MB limit and
           killed it mid-seed, silently. One class at a time, with ONE
           shared timestamp, keeps the peak near a single class; the
           rows, buckets and receipt linkage are identical. */
        $now = now();
        foreach ($students->groupBy('my_class_id') as $classId => $classStudents) {
            $recordRows = [];
            $receiptRows = [];
            foreach ($classStudents as $student) {
                $bucket = $bucketByUserId[$student->user_id];
                foreach (($paymentsByClass[$classId] ?? []) as $j => $payment) {
                    if ($bucket === 'full') {
                        $amtPaid = (int) $payment->amount;
                        $balance = 0;
                        $paid = 1;
                    } elseif ($bucket === 'partial') {
                        $amtPaid = (int) round($payment->amount * [0.4, 0.5, 0.6][$j % 3]);
                        $balance = (int) $payment->amount - $amtPaid;
                        $paid = 0;
                    } else {
                        $amtPaid = null;
                        $balance = (int) $payment->amount;
                        $paid = 0;
                    }

                    $recordRows[] = [
                        'payment_id' => $payment->id,
                        'student_id' => $student->user_id,
                        'ref_no' => sprintf('DEMO-RCPT-%02d-%03d', $payment->id, $student->user_id),
                        'amt_paid' => $amtPaid,
                        'balance' => $balance,
                        'paid' => $paid,
                        'year' => $session,
                        'created_at' => $now,
                        'updated_at' => $now,
                        // Stash the record key so receipts can find
                        // their payment_record id after the bulk insert.
                        '_key' => $payment->id . ':' . $student->user_id,
                    ];

                    if ($amtPaid) {
                        $receiptRows[] = [
                            'amt_paid' => $amtPaid,
                            'balance' => $balance,
                            'year' => $session,
                            'created_at' => $now,
                            'updated_at' => $now,
                            '_key' => $payment->id . ':' . $student->user_id,
                        ];
                    }
                }
            }

            foreach (array_chunk($recordRows, 200) as $chunk) {
                DB::table('payment_records')->insert(array_map(function ($row) {
                    unset($row['_key']);
                    return $row;
                }, $chunk));
            }

            if ($receiptRows) {
                $classPaymentIds = collect($paymentsByClass[$classId] ?? [])->pluck('id')->all();
                $classStudentIds = $classStudents->pluck('user_id')->all();
                $recordIds = DB::table('payment_records')
                    ->whereIn('payment_id', $classPaymentIds)
                    ->whereIn('student_id', $classStudentIds)
                    ->get(['id', 'payment_id', 'student_id'])
                    ->mapWithKeys(function ($row) {
                        return [$row->payment_id . ':' . $row->student_id => $row->id];
                    });

                $receipts = [];
                foreach ($receiptRows as $row) {
                    $key = $row['_key'];
                    unset($row['_key']);
                    if (isset($recordIds[$key])) {
                        $row['pr_id'] = $recordIds[$key];
                        $receipts[] = $row;
                    }
                }

                foreach (array_chunk($receipts, 200) as $chunk) {
                    DB::table('receipts')->insert($chunk);
                }
            }
        }
    }

    /** [title, amount] pairs for one class, banded by class type. */
    protected function feeSchedule(?string $typeCode, string $className): array
    {
        $bands = [
            'nursery' => [18000, 6000, 1500],
            'primary' => [24000, 7000, 2000],
            'junior' => [32000, 8000, 2500],
            'senior' => [38000, 9000, 3000],
        ];

        $band = 'primary';
        if (in_array($typeCode, ['C', 'PN', 'N'], true)
            || stripos($className, 'nursery') !== false || stripos($className, 'creche') !== false) {
            $band = 'nursery';
        } elseif ($typeCode === 'J' || stripos($className, 'JSS') !== false) {
            $band = 'junior';
        } elseif ($typeCode === 'S' || stripos($className, 'SSS') !== false) {
            $band = 'senior';
        }

        [$tuition, $transport, $exam] = $bands[$band];

        return [
            ['Term 1 Tuition Fee', $tuition],
            ['Transport Fee', $transport],
            ['Exam Fee', $exam],
        ];
    }

    /**
     * RETIRED (Workstream AR) — no longer called from run(). Day-wise
     * attendance demo for the last 10 school days (Sundays skipped),
     * ending today: ~85% present, ~10% absent, ~5% late per student
     * per day, assigned by a stable hash. Kept only as the record of
     * the retired day-wise demo; PeriodAttendanceSeeder seeds the
     * live demo now.
     */
    protected function seedAttendance($students): void
    {
        if (!Schema::hasTable('attendances') || DB::table('attendances')->count() > 0) {
            return;
        }

        $recorder = DB::table('users')->where('user_type', 'teacher')->value('id')
            ?: DB::table('users')->where('user_type', 'super_admin')->value('id');

        $days = [];
        $cursor = Carbon::today();
        while (count($days) < 10) {
            if (!$cursor->isSunday()) {
                $days[] = $cursor->copy();
            }
            $cursor->subDay();
        }

        $rows = [];
        foreach ($days as $day) {
            $date = $day->toDateString();
            foreach ($students as $student) {
                $roll = $this->hashOf($student->id . '|' . $date) % 100;
                $rows[] = [
                    'student_record_id' => $student->id,
                    'date' => $date,
                    'status' => $roll < 85 ? 'present' : ($roll < 95 ? 'absent' : 'late'),
                    'remarks' => null,
                    'recorded_by' => $recorder,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('attendances')->insert($chunk);
        }
    }

    /** A small believable library: 7 titles plus one copy out on loan. */
    protected function seedLibrary($students): void
    {
        if (!Schema::hasTable('books') || DB::table('books')->count() > 0) {
            return;
        }

        $classIds = DB::table('my_classes')->orderBy('id')->pluck('id')->values();
        $seniorClass = $classIds->get(9) ?: $classIds->last();
        $primaryClass = $classIds->get(3) ?: $classIds->first();

        // name, author, book_type, total_copies, location, my_class_id
        $catalogue = [
            ['English Grammar and Composition', 'Wren & Martin', 'Book', 4, 'Shelf A1', null],
            ['Mathematics Textbook', 'NCERT', 'Textbook', 5, 'Shelf B2', $seniorClass],
            ['General Science Textbook', 'NCERT', 'Textbook', 4, 'Shelf B3', $primaryClass],
            ['Atlas of India and the World', 'Oxford University Press', 'Book', 3, 'Shelf C1', null],
            ['Panchatantra: Classic Tales for Children', 'Traditional Tales', 'Book', 3, 'Shelf A4', null],
            ['Young Scientist: Journal for Schools', 'Science Association', 'Journal', 2, 'Shelf D1', null],
            ['Education Today: Monthly Magazine', 'Education Media Group', 'Magazine', 2, 'Shelf D2', null],
        ];

        $loanedBookId = null;
        foreach ($catalogue as $i => [$name, $author, $type, $copies, $location, $classId]) {
            $id = DB::table('books')->insertGetId([
                'name' => $name,
                'my_class_id' => $classId,
                'description' => 'Demo library title.',
                'author' => $author,
                'book_type' => $type,
                'url' => null,
                'location' => $location,
                'total_copies' => $copies,
                // The loaned title starts with one copy out, exactly as
                // BookRequestController@store leaves it after issuing.
                'issued_copies' => $i === 0 ? 1 : 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($i === 0) {
                $loanedBookId = $id;
            }
        }

        // One live loan so the Loans screen is not empty: the first demo
        // student borrowed the grammar book three days ago for two weeks.
        if ($loanedBookId && Schema::hasTable('book_requests')
            && DB::table('book_requests')->count() === 0) {
            DB::table('book_requests')->insert([
                'book_id' => $loanedBookId,
                'user_id' => $students->first()->user_id,
                'start_date' => Carbon::today()->subDays(3)->toDateString(),
                'end_date' => Carbon::today()->addDays(11)->toDateString(),
                'returned' => 0,
                'status' => 'issued',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** Two upcoming events and one past event for this term. */
    protected function seedEvents(): void
    {
        if (!Schema::hasTable('school_events') || DB::table('school_events')->count() > 0) {
            return;
        }

        $creator = DB::table('users')->where('user_type', 'super_admin')->value('id');

        // title, type, days from today, description
        $events = [
            ['Parent-Teacher Meeting', 'meeting', 3, 'Term 1 progress discussion with parents.'],
            ['Annual Sports Day', 'event', 6, 'Inter-house athletics and march-past on the school ground.'],
            ['Term 1 Orientation Day', 'event', -12, 'Welcome and orientation for the new term.'],
        ];

        $rows = [];
        foreach ($events as [$title, $type, $offset, $description]) {
            $date = Carbon::today()->addDays($offset)->toDateString();
            $rows[] = [
                'title' => $title,
                'type' => $type,
                'description' => $description,
                'starts_at' => $date,
                'ends_at' => $date,
                'all_day' => 1,
                'created_by' => $creator,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('school_events')->insert($rows);
    }

    /**
     * One term-1 exam with marks for every demo student in each of their
     * class's seeded subjects (English Language + Mathematics), plus the
     * matching exam_records totals — the same shape MarkController and
     * DashboardDemoDataSeeder write, so a marksheet renders end to end.
     */
    protected function seedExamAndMarks($students, string $session): void
    {
        if (DB::table('marks')->count() > 0) {
            return;
        }
        $now = now(); // one shared timestamp: per-row now() Carbon objects were part of the old memory peak

        $exam = DB::table('exams')->where('year', $session)->orderBy('id')->first();
        $examId = $exam ? $exam->id : DB::table('exams')->insertGetId([
            'name' => 'Term 1 Examination',
            'term' => 1,
            'year' => $session,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $subjectsByClass = DB::table('subjects')->orderBy('id')->get()->groupBy('my_class_id');
        $grades = DB::table('grades')->whereNull('class_type_id')->get();

        /* Per-class processing (2026-10-24): the old version built ALL
           marks + records rows for every student in memory before
           inserting anything — at ~3,000 students that peaks near
           100MB and kills a 128MB seed process mid-step, silently
           (step() only error_logs). One class at a time keeps the
           peak near a single class; totals, positions and class
           averages are identical because every grouping was already
           per class + section. */
        foreach ($students->groupBy('my_class_id') as $classId => $classStudents) {
        $markRows = [];
        $totals = []; // student user_id => [total, count, section_id]
        foreach ($classStudents as $student) {
            $subjects = $subjectsByClass->get($classId) ?? collect();
            $total = 0;
            foreach ($subjects as $subject) {
                $seed = $this->hashOf($student->user_id . '|' . $subject->id . '|' . $examId);
                $t1 = 8 + ($seed % 13);          // 8–20 of 20
                $t2 = 8 + (intdiv($seed, 13) % 13); // 8–20 of 20
                $exm = 25 + (intdiv($seed, 169) % 36); // 25–60 of 60
                $subjectTotal = $t1 + $t2 + $exm;   // ≤ 100
                $total += $subjectTotal;

                $grade = $grades->first(function ($g) use ($subjectTotal) {
                    return $g->mark_from <= $subjectTotal && $g->mark_to >= $subjectTotal;
                });

                $markRows[] = [
                    'student_id' => $student->user_id,
                    'subject_id' => $subject->id,
                    'my_class_id' => $student->my_class_id,
                    'section_id' => $student->section_id,
                    'exam_id' => $examId,
                    't1' => $t1,
                    't2' => $t2,
                    't3' => null,
                    't4' => null,
                    'tca' => $t1 + $t2,
                    'exm' => $exm,
                    'tex1' => $subjectTotal,
                    'tex2' => null,
                    'tex3' => null,
                    'sub_pos' => null,
                    'cum' => $subjectTotal,
                    'cum_ave' => (string) $subjectTotal,
                    'grade_id' => $grade ? $grade->id : null,
                    'year' => $session,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            $totals[$student->user_id] = [$total, max(1, $subjects->count()), $student->section_id];
        }

        foreach (array_chunk($markRows, 200) as $chunk) {
            DB::table('marks')->insert($chunk);
        }

        // Exam records: totals per student, position within their
        // section (the grouping MarkRepo::getPos uses), and the class
        // average across this class — same values as before, since
        // every grouping was already per class + section.
        $groups = [];
        foreach ($totals as $userId => [$total, $count, $sectionId]) {
            $groups[$sectionId][] = $userId;
        }
        $positions = [];
        foreach ($groups as $members) {
            usort($members, function ($a, $b) use ($totals) {
                return $totals[$b][0] <=> $totals[$a][0];
            });
            foreach ($members as $rank => $userId) {
                $positions[$userId] = $rank + 1;
            }
        }
        $aves = [];
        foreach ($totals as [$total, $count]) {
            $aves[] = $total / $count;
        }
        $classAve = number_format(array_sum($aves) / max(1, count($aves)), 2);

        $recordRows = [];
        foreach ($totals as $userId => [$total, $count, $sectionId]) {
            $recordRows[] = [
                'exam_id' => $examId,
                'student_id' => $userId,
                'my_class_id' => $classId,
                'section_id' => $sectionId,
                'total' => $total,
                'ave' => number_format($total / $count, 2),
                'class_ave' => $classAve,
                'pos' => $positions[$userId],
                'af' => null,
                'ps' => null,
                'p_comment' => null,
                't_comment' => null,
                'year' => $session,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($recordRows, 200) as $chunk) {
            DB::table('exam_records')->insert($chunk);
        }
        } // end per-class loop

        $this->seedDemoMarkingScheme((int) $examId, $subjectsByClass);
    }

    /**
     * Batch M4 — give the demo exam ONE configured marking scheme so
     * the sales demo shows the M1–M3 feature end to end: every class's
     * English Language subject is configured Theory 60 + Internal
     * Assessment 30 + Viva 10, while Mathematics stays on the legacy
     * CA scheme — the M3 card therefore renders a mixed-scheme class.
     *
     * The component scores are a deterministic split of each English
     * mark's EXISTING total (tex1): Theory ≈ 60%, Internal ≈ 30%,
     * Viva the remainder, clamped to each component's max — so the
     * three components sum EXACTLY to the stored tex1 and every demo
     * total, grade, average and position computed above is unchanged
     * (the dual-write contract of design §3.1, applied in the
     * seeder as design §5-M4 prescribes). The legacy t1/t2/tca/exm
     * columns are left filled alongside, exactly as seeded.
     *
     * Guarded like the rest of this seeder: runs only when the demo
     * exam has no components yet (its caller, seedExamAndMarks,
     * itself no-ops once marks exist).
     */
    protected function seedDemoMarkingScheme(int $examId, $subjectsByClass): void
    {
        if (!Schema::hasTable('exam_subject_components') || !Schema::hasTable('mark_components')) {
            return;
        }
        if (DB::table('exam_subject_components')->where('exam_id', $examId)->count() > 0) {
            return;
        }

        $now = now();
        // name, short_code, max_marks, sort — the preset of design §3.2.
        $scheme = [
            ['Theory', 'TH', 60, 1],
            ['Internal Assessment', 'INT', 30, 2],
            ['Viva', 'VIVA', 10, 3],
        ];

        foreach ($subjectsByClass as $classSubjects) {
            $english = $classSubjects->firstWhere('name', 'English Language');
            if (!$english) {
                continue;
            }

            $componentIds = [];
            foreach ($scheme as [$name, $code, $max, $sort]) {
                $componentIds[$code] = DB::table('exam_subject_components')->insertGetId([
                    'exam_id' => $examId,
                    'subject_id' => $english->id,
                    'name' => $name,
                    'short_code' => $code,
                    'max_marks' => $max,
                    'sort' => $sort,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $englishMarks = DB::table('marks')
                ->where(['exam_id' => $examId, 'subject_id' => $english->id])
                ->get(['id', 'tex1']);

            $rows = [];
            foreach ($englishMarks as $mk) {
                $total = (int) $mk->tex1;
                $theory = (int) round($total * 0.6);
                $internal = (int) round($total * 0.3);
                $viva = $total - $theory - $internal;
                // Defensive clamps (unreachable for totals ≤ 100,
                // which the demo generator guarantees): keep every
                // component inside its max without changing the sum.
                if ($viva > 10) {
                    $theory += $viva - 10;
                    $viva = 10;
                } elseif ($viva < 0) {
                    $internal += $viva;
                    $viva = 0;
                }

                foreach (['TH' => $theory, 'INT' => $internal, 'VIVA' => $viva] as $code => $score) {
                    $rows[] = [
                        'mark_id' => $mk->id,
                        'component_id' => $componentIds[$code],
                        'score' => $score,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('mark_components')->insert($chunk);
            }
        }
    }

    /**
     * Loop 11 — a small set of realistic circulars so the dashboards'
     * Recent Circulars panels and the circulars feed are alive in the
     * demo. Published by the Super Admin, live from two days ago until
     * ten days from now, one per audience that matters. Guarded on the
     * circulars table being empty, like every other domain here.
     */
    protected function seedCirculars(): void
    {
        if (!Schema::hasTable('circulars') || DB::table('circulars')->count() > 0) {
            return;
        }

        $publisher = DB::table('users')->where('email', 'superadmin@hackstra.app')->value('id')
            ?: DB::table('users')->where('user_type', 'super_admin')->value('id');

        // title, body, audience, priority
        $circulars = [
            ['School closed for the Diwali break',
                'The school will remain closed for the Diwali break. Classes resume on the next working day; the school office stays open for urgent enquiries.',
                'all', 'normal'],
            ['Term 1 marks entry deadline',
                'All teachers are requested to complete Term 1 marks entry for their subjects before the deadline so that report cards can be prepared on time.',
                'teachers', 'important'],
            ['Parent-Teacher Meeting this Saturday',
                'A Parent-Teacher Meeting will be held this Saturday from 9:00 AM. Parents are requested to meet the class teacher of their ward to discuss Term 1 progress.',
                'parents', 'normal'],
            ['Term 1 fee reminder',
                'Parents who are yet to pay the Term 1 fees are requested to complete the payment at the school office at the earliest. Please ignore this notice if you have already paid.',
                'parents', 'important'],
        ];

        $rows = [];
        foreach ($circulars as [$title, $body, $audience, $priority]) {
            $rows[] = [
                'title' => $title,
                'body' => $body,
                'audience' => $audience,
                'priority' => $priority,
                'published_by' => $publisher,
                'starts_at' => Carbon::today()->subDays(2)->toDateString(),
                'expires_at' => Carbon::today()->addDays(10)->toDateString(),
                'is_published' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('circulars')->insert($rows);
    }

    /**
     * Loop 11 — class timetables for the seeded teachers so each
     * Teacher Dashboard's "Today's Periods" panel shows real rows on
     * any weekday. Each class gets a timetable record on a full
     * 8-period day with ALL its subjects (English + Mathematics),
     * break after period 2 and lunch after period 4 — written exactly
     * the way TimeTableController@store and @store_time_slot build
     * them (same columns, same strtotime formulas). Guarded per
     * subject: a subject that already has periods is never touched.
     *
     * Workstream AT: this used to build timetables for the demo
     * teacher only — correct when he taught every class. Since the
     * seed rebalance he teaches three classes like everyone else, so
     * every seeded teacher now gets their own timetables (the demo
     * teacher first, then the rest by user id — fully deterministic).
     */
    protected function seedTeacherTimetables(string $session): void
    {
        if (!Schema::hasTable('time_tables') || !Schema::hasTable('time_slots')) {
            return;
        }

        $teacherIds = DB::table('users')
            ->where('user_type', 'teacher')->orderBy('id')->pluck('id');
        if ($teacherIds->isEmpty()) {
            return;
        }

        foreach ($teacherIds as $teacherId) {
            $this->seedTeacherTimetableFor((int) $teacherId, $session);
        }
    }

    /** Build the demo timetables for one teacher's classes.
     *
     * Owner fix 2026-10-25 — the old seed picked ONE subject per
     * class (always English first, Mathematics never picked), so
     * every class card read "15 periods · 1 subjects" and the day
     * ended at noon with no lunch. The new seed gives every class
     * ALL its subjects on a full generator-default day (40-min
     * periods, break after 2, lunch after 4): 8 slots, 8:00 AM to
     * 2:20 PM. A teacher's first class uses periods 1-4, the second
     * uses periods 5-8, so the teacher is never double-booked; the
     * break/lunch gaps are implicit in the slot start times and the
     * grid view renders them as Break/Lunch rows. Demo-seed
     * assumption: one teacher teaches a class's subjects — a class
     * split across teachers would need block coordination beyond
     * this seed. Written exactly the way TimeTableController@store
     * and @store_time_slot build records (same columns, same
     * strtotime formulas). Guarded per subject: a subject that
     * already has periods in the record is never touched.
     */
    protected function seedTeacherTimetableFor(int $teacherId, string $session): void
    {
        $subjects = DB::table('subjects')
            ->where('teacher_id', $teacherId)->orderBy('id')->get();
        if ($subjects->isEmpty()) {
            return;
        }

        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        // [hour_from, min_from, meridian_from, hour_to, min_to, meridian_to]
        // Full day: P1 8:00-8:40, P2 8:40-9:20, break 9:20-9:35,
        // P3 9:35-10:15, P4 10:15-10:55, lunch 10:55-11:40,
        // P5 11:40-12:20, P6 12:20-1:00, P7 1:00-1:40, P8 1:40-2:20.
        $daySlots = [
            ['8', '00', 'AM', '8', '40', 'AM'],
            ['8', '40', 'AM', '9', '20', 'AM'],
            ['9', '35', 'AM', '10', '15', 'AM'],
            ['10', '15', 'AM', '10', '55', 'AM'],
            ['11', '40', 'AM', '12', '20', 'PM'],
            ['12', '20', 'PM', '1', '00', 'PM'],
            ['1', '00', 'PM', '1', '40', 'PM'],
            ['1', '40', 'PM', '2', '20', 'PM'],
        ];
        // A teacher's first class takes periods 1-4, the second 5-8.
        // The second slice MUST preserve keys — without `true` it
        // reindexes to 0-3 and both classes would land on periods 1-4.
        $blocks = [array_slice($daySlots, 0, 4), array_slice($daySlots, 4, 4, true)];

        $classIds = $subjects->pluck('my_class_id')->unique()->values();
        foreach ($classIds as $classIndex => $classId) {
            $classSubjects = $subjects->where('my_class_id', $classId)->values();
            $block = $blocks[$classIndex % count($blocks)];

            $ttr = DB::table('time_table_records')
                ->where('my_class_id', $classId)
                ->whereNull('exam_id')
                ->where('year', $session)
                ->first();

            if ($ttr) {
                $ttrId = $ttr->id;
            } else {
                $className = DB::table('my_classes')->where('id', $classId)->value('name') ?: 'Class';
                $name = $className . ' Timetable';
                if (DB::table('time_table_records')->where('name', $name)->exists()) {
                    $name .= ' (' . $session . ')';
                }
                $ttrId = DB::table('time_table_records')->insertGetId([
                    'name' => $name,
                    'my_class_id' => $classId,
                    'exam_id' => null,
                    'year' => $session,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Idempotency guard (Batch 6, D17): skip subjects that
            // already have periods in this record; the rest still seed.
            $placedIds = DB::table('time_tables')->where('ttr_id', $ttrId)
                ->whereIn('subject_id', $classSubjects->pluck('id')->all())
                ->distinct()->pluck('subject_id')->all();
            $pending = $classSubjects->reject(function ($s) use ($placedIds) {
                return in_array($s->id, $placedIds, true);
            })->values();
            if ($pending->isEmpty()) {
                continue;
            }

            $slots = [];
            foreach ($daySlots as [$hf, $mf, $merf, $ht, $mt, $mert]) {
                $timeFrom = $hf . ':' . $mf . ' ' . $merf;
                $timeTo = $ht . ':' . $mt . ' ' . $mert;
                $existing = DB::table('time_slots')
                    ->where('ttr_id', $ttrId)
                    ->where('timestamp_from', (string) strtotime($timeFrom))
                    ->where('timestamp_to', (string) strtotime($timeTo))
                    ->first();
                if ($existing) {
                    $slots[] = $existing;
                    continue;
                }
                $slotId = DB::table('time_slots')->insertGetId([
                    'ttr_id' => $ttrId,
                    'hour_from' => (int) $hf,
                    'min_from' => $mf,
                    'meridian_from' => $merf,
                    'hour_to' => (int) $ht,
                    'min_to' => $mt,
                    'meridian_to' => $mert,
                    'time_from' => $timeFrom,
                    'time_to' => $timeTo,
                    'timestamp_from' => (string) strtotime($timeFrom),
                    'timestamp_to' => (string) strtotime($timeTo),
                    'full' => $timeFrom . ' - ' . $timeTo,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $slots[] = DB::table('time_slots')->where('id', $slotId)->first();
            }

            // Block slots, round-robin across the class's pending
            // subjects: two subjects get two periods each per day.
            $blockSlots = array_values(array_intersect_key($slots, array_flip(array_keys($block))));
            $rows = [];
            foreach ($days as $dayNum => $day) {
                foreach ($blockSlots as $slotIndex => $slot) {
                    $subject = $pending[$slotIndex % $pending->count()];
                    $rows[] = [
                        'ttr_id' => $ttrId,
                        'ts_id' => $slot->id,
                        'subject_id' => $subject->id,
                        'exam_date' => null,
                        'timestamp_from' => (string) strtotime($day . ' ' . $slot->time_from),
                        'timestamp_to' => (string) strtotime($day . ' ' . $slot->time_to),
                        'day' => $day,
                        'day_num' => $dayNum + 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }
            // insertOrIgnore: a (ttr, slot, day) cell holds ONE subject.
            // If a real timetable entry already occupies a cell, that row
            // is skipped instead of aborting the whole seed with a unique
            // constraint violation; every free cell still gets its period.
            DB::table('time_tables')->insertOrIgnore($rows);
        }
    }

    /** Unsigned crc32 so hash-derived values are identical on any platform. */
    protected function hashOf(string $key): int
    {
        return (int) sprintf('%u', crc32($key));
    }

    /**
     * Demo librarian login so the librarian portal can be tried on the demo
     * (UsersTableSeeder ships no librarian). Email-guarded, runs once.
     */
    protected function seedLibrarianUser(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }
        if (DB::table('users')->where('email', 'librarian@hackstra.app')->exists()) {
            return;
        }
        DB::table('users')->insert([
            'name' => 'Revathi Subramanian',
            'email' => 'librarian@hackstra.app',
            'phone' => '+91 98110 00006',
            'username' => 'librarian',
            'password' => bcrypt('hackstra'),
            'user_type' => 'librarian',
            'code' => 'LIB' . strtoupper(substr(md5('librarian@hackstra.app'), 0, 6)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Demo feedback chain (Workstream CW) so the whole workflow is
     * visible on first login: one canonical report already forwarded
     * and answered by the Super Admin, its duplicate merged in by the
     * Admin, one fresh report waiting in the Admin inbox, and one
     * acknowledged report the Admin has NOT forwarded (the Super
     * Admin must not see it).
     */
    protected function seedFeedback(): void
    {
        if (!Schema::hasTable('feedback_items') || DB::table('feedback_items')->count() > 0) {
            return;
        }

        $byEmail = function ($email) {
            return DB::table('users')->where('email', $email)->first();
        };
        $parent = $byEmail('parent@hackstra.app');
        $teacher = $byEmail('teacher@hackstra.app');
        $admin = $byEmail('admin@hackstra.app');
        $accountant = $byEmail('accountant@hackstra.app');
        $studentUserId = DB::table('student_records')->where('grad', 0)->orderBy('id')->value('user_id');
        $student = $studentUserId ? DB::table('users')->where('id', $studentUserId)->first() : null;

        if (!$parent || !$teacher || !$admin) {
            return;
        }

        $androidUA = 'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Mobile Safari/537.36';
        $windowsUA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

        $canonicalId = DB::table('feedback_items')->insertGetId([
            'reporter_id' => $parent->id,
            'reporter_name' => $parent->name,
            'reporter_role' => 'parent',
            'type' => 'problem',
            'category' => 'facility',
            'priority' => 'high',
            'title' => 'Drinking water cooler not working near Block B',
            'description' => 'The drinking water cooler near Block B has not been cooling for three days. Children are carrying bottles from home; please repair or replace it at the earliest.',
            'context' => 'Ground floor, Block B, near the library',
            'page_url' => '/payments/manage',
            'user_agent' => $androidUA,
            'status' => 'in_progress',
            'duplicate_of_id' => null,
            'admin_note' => 'Plumber visit scheduled; cooler replacement approved by the office.',
            'forwarded_at' => now()->subDays(1),
            'forwarded_by' => $admin->id,
            'sa_status' => 'fixed',
            'sa_reply' => 'Fixed — the cooler was replaced and the water line flushed. Thank you for reporting it.',
            'reporter_seen_at' => null,
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subHours(5),
        ]);

        DB::table('feedback_items')->insert([
            'reporter_id' => $teacher->id,
            'reporter_name' => $teacher->name,
            'reporter_role' => 'teacher',
            'type' => 'problem',
            'category' => 'facility',
            'priority' => 'medium',
            'title' => 'Water cooler in Block B is warm',
            'description' => 'Students are complaining that the Block B water cooler only dispenses warm water since Monday.',
            'context' => 'Block B, ground floor',
            'page_url' => '/attendance/periods',
            'user_agent' => $windowsUA,
            'status' => 'duplicate',
            'duplicate_of_id' => $canonicalId,
            'admin_note' => null,
            'forwarded_at' => null,
            'forwarded_by' => null,
            'sa_status' => null,
            'sa_reply' => null,
            'reporter_seen_at' => null,
            'created_at' => now()->subDays(3)->addHours(2),
            'updated_at' => now()->subDays(2),
        ]);

        if ($student) {
            DB::table('feedback_items')->insert([
                'reporter_id' => $student->id,
                'reporter_name' => $student->name,
                'reporter_role' => 'student',
                'type' => 'problem',
                'category' => 'facility',
                'priority' => 'medium',
                'title' => 'Projector in Room 12 flickers during class',
                'description' => 'The projector in Room 12 flickers and goes blank for a few seconds every few minutes, which breaks the lesson flow.',
                'context' => 'Room 12, first floor',
                'page_url' => '/home',
                'user_agent' => $androidUA,
                'status' => 'new',
                'duplicate_of_id' => null,
                'admin_note' => null,
                'forwarded_at' => null,
                'forwarded_by' => null,
                'sa_status' => null,
                'sa_reply' => null,
                'reporter_seen_at' => null,
                'created_at' => now()->subHours(6),
                'updated_at' => now()->subHours(6),
            ]);
        }

        if ($accountant) {
            DB::table('feedback_items')->insert([
                'reporter_id' => $accountant->id,
                'reporter_name' => $accountant->name,
                'reporter_role' => 'accountant',
                'type' => 'problem',
                'category' => 'app_bug',
                'priority' => 'medium',
                'title' => 'Receipt print cuts off the school address',
                'description' => 'When printing a fee receipt from the office printer, the school address line at the top is cut off on the right side.',
                'context' => 'School office, accounts desk',
                'page_url' => '/payments/manage',
                'user_agent' => $windowsUA,
                'status' => 'acknowledged',
                'duplicate_of_id' => null,
                'admin_note' => 'Reproduced at the office printer; checking the print layout before escalating.',
                'forwarded_at' => null,
                'forwarded_by' => null,
                'sa_status' => null,
                'sa_reply' => null,
                'reporter_seen_at' => null,
                'created_at' => now()->subDays(1)->subHours(3),
                'updated_at' => now()->subDays(1),
            ]);
        }
    }
}
