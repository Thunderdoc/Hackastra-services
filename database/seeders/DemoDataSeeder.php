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
        $this->step(function () use ($session) { $this->seedClassTimetables($session); });
        $this->step(function () { $this->seedEligibility(); });
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
        // Batch 2026-10-25 (streams): section stream per id, so a
        // Class 11/12 student gets marks only for the common subjects
        // plus their own section's stream subjects.
        $sectionStreams = DB::table('sections')->pluck('stream', 'id');

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
            $sectionStream = $sectionStreams[$student->section_id] ?? null;
            if ($sectionStream) {
                $subjects = $subjects->filter(function ($s) use ($sectionStream) {
                    return \App\Support\Streams::appliesTo($s, $sectionStream);
                })->values();
            }
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
    /** Build the demo timetables, one coordinated grid per class.
     *
     * Owner change 2026-10-25 (more teachers + more subjects): the old
     * seed was per-teacher and assumed ONE teacher teaches a class's
     * subjects (first class periods 1-4, second 5-8). With six
     * specialist subjects per class that assumption breaks — every
     * subject teacher would claim the same block and all but the
     * first teacher's cells would be swallowed by insertOrIgnore.
     * The new seed is per-class with a global teacher-busy map: for
     * each class it walks days x slots in order, round-robin across
     * the class's subjects, placing a period only where that
     * subject's teacher is free at that (day, slot) and the subject
     * is still under its periods_per_week. Six subjects at six
     * periods = 36 of the 40 weekly cells, so every class lands a
     * full grid and no teacher is ever double-booked — the same
     * guarantee the validator checks. Record + slot creation is
     * unchanged from the old seed (same columns, same strtotime
     * formulas as TimeTableController@store and @store_time_slot).
     * Idempotency: a subject that already has periods in the record
     * is never touched, and cells from an earlier partial run
     * preload the busy map, so a re-run adds nothing and clashes
     * with nothing.
     */
    protected function seedClassTimetables(string $session): void
    {
        if (!Schema::hasTable('time_tables') || !Schema::hasTable('time_slots')) {
            return;
        }

        // Batch 2026-10-26 (streams staffing): staff the senior
        // section grids through per-section overrides BEFORE any
        // grid is placed, so the placer below can resolve teachers
        // override-aware instead of piling every section of a
        // subject onto its one class-level teacher.
        $this->seedSeniorSectionTeachers();

        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        // [hour_from, min_from, meridian_from, hour_to, min_to, meridian_to]
        // Full day (school starts 9:00, owner order 2026-10-25):
        // P1 9:00-9:40, P2 9:40-10:20, break 10:20-10:35,
        // P3 10:35-11:15, P4 11:15-11:55, lunch 11:55-12:40,
        // P5 12:40-1:20, P6 1:20-2:00, P7 2:00-2:40, P8 2:40-3:20.
        $daySlots = [
            ['9', '00', 'AM', '9', '40', 'AM'],
            ['9', '40', 'AM', '10', '20', 'AM'],
            ['10', '35', 'AM', '11', '15', 'AM'],
            ['11', '15', 'AM', '11', '55', 'AM'],
            ['12', '40', 'PM', '1', '20', 'PM'],
            ['1', '20', 'PM', '2', '00', 'PM'],
            ['2', '00', 'PM', '2', '40', 'PM'],
            ['2', '40', 'PM', '3', '20', 'PM'],
        ];

        // Preload the busy map from cells an earlier partial run may
        // already have placed: teacher_id => ['dayNum:slotIndex' => true].
        $busy = [];
        $slotIndexById = [];
        $perRecord = [];
        foreach (DB::table('time_slots')->orderBy('ttr_id')->orderBy('timestamp_from')->get() as $sr) {
            $perRecord[$sr->ttr_id][] = $sr->id;
        }
        foreach ($perRecord as $ids) {
            foreach ($ids as $i => $sid) {
                $slotIndexById[$sid] = $i;
            }
        }
        $subjectTeacher = DB::table('subjects')->pluck('teacher_id', 'id');
        // Section-aware preload: a cell in a section grid is taught
        // by that section's override teacher, not the subject's
        // class-level teacher (same resolution as
        // SectionTeacher::teacherIdFor, inlined here for speed).
        $recordSection = DB::table('time_table_records')->pluck('section_id', 'id');
        $overrideMap = [];
        if (Schema::hasTable('section_subject_teachers')) {
            foreach (DB::table('section_subject_teachers')->get(['section_id', 'subject_id', 'teacher_id']) as $o) {
                $overrideMap[$o->section_id . ':' . $o->subject_id] = $o->teacher_id;
            }
        }
        foreach (DB::table('time_tables')->get(['ttr_id', 'ts_id', 'subject_id', 'day_num']) as $cell) {
            $sec = $recordSection[$cell->ttr_id] ?? null;
            $tid = ($sec && isset($overrideMap[$sec . ':' . $cell->subject_id]))
                ? $overrideMap[$sec . ':' . $cell->subject_id]
                : ($subjectTeacher[$cell->subject_id] ?? null);
            if ($tid && isset($slotIndexById[$cell->ts_id])) {
                $busy[$tid][$cell->day_num . ':' . $slotIndexById[$cell->ts_id]] = true;
            }
        }

        foreach (DB::table('my_classes')->orderBy('id')->get() as $class) {
            // Batch 2026-10-25 (streams): a class with streamed
            // sections gets one grid per section (stream-filtered
            // subjects) and no class grid — the section grid is what
            // the timetable views prefer for that section's students.
            $streamedSections = DB::table('sections')
                ->where('my_class_id', $class->id)->whereNotNull('stream')->orderBy('id')->get();
            if ($streamedSections->isNotEmpty()) {
                foreach ($streamedSections as $section) {
                    $subjects = DB::table('subjects')
                        ->where('my_class_id', $class->id)->orderBy('id')->get()
                        ->filter(function ($s) use ($section) {
                            return \App\Support\Streams::appliesTo($s, $section->stream);
                        })->values();
                    if ($subjects->isEmpty()) {
                        continue;
                    }
                    $this->seedClassTimetableFor($class, $subjects, $session, $days, $daySlots, $busy, $section);
                }
                continue;
            }
            $subjects = DB::table('subjects')
                ->where('my_class_id', $class->id)->orderBy('id')->get();
            if ($subjects->isEmpty()) {
                continue;
            }
            $this->seedClassTimetableFor($class, $subjects, $session, $days, $daySlots, $busy);
        }
    }

    /** Staff streamed sections through per-section subject-teacher
     * overrides, balanced by current load (batch 2026-10-26).
     *
     * Why: a streamed class's 22 sections each need 6 periods of
     * every stream subject, but the class-level subject has ONE
     * teacher — Ananya alone would owe 78 English periods a week
     * across Prep–Class 12, and the seed placer (which respects the
     * busy map) silently starved later sections down to zero cells.
     * The fix rotates each subject's EXTENDED family (the class
     * rotation's three specialists plus the batch 2026-10-26
     * additions) across the sections, greedy least-loaded first,
     * so every teacher lands near the family average and well under
     * the 40-slot week. Classes with streamed sections contribute
     * NO class-level load — their teaching comes only via these
     * overrides — and the class-level rotation in
     * SubjectsTableSeeder deliberately stays at three specialists
     * so Prep–Class 10 assignments never move.
     */
    protected function seedSeniorSectionTeachers(): void
    {
        if (!Schema::hasTable('section_subject_teachers')) {
            return;
        }
        $eng = ['teacher@hackstra.app', 'teacher1@teacher.com', 'teacher2@teacher.com',
            'teacher20@teacher.com', 'teacher21@teacher.com', 'teacher22@teacher.com'];
        $sci = ['teacher6@teacher.com', 'teacher7@teacher.com', 'teacher8@teacher.com',
            'teacher26@teacher.com', 'teacher27@teacher.com', 'teacher28@teacher.com',
            'teacher29@teacher.com'];
        $soc = ['teacher9@teacher.com', 'teacher10@teacher.com', 'teacher11@teacher.com',
            'teacher30@teacher.com', 'teacher31@teacher.com', 'teacher32@teacher.com'];
        $hin = ['teacher12@teacher.com', 'teacher13@teacher.com', 'teacher14@teacher.com',
            'teacher23@teacher.com', 'teacher24@teacher.com', 'teacher25@teacher.com'];
        $math = ['teacher3@teacher.com', 'teacher4@teacher.com', 'teacher5@teacher.com'];
        $cs = ['teacher15@teacher.com', 'teacher16@teacher.com', 'teacher17@teacher.com'];
        $families = [
            'English Language' => $eng, 'Hindi' => $hin, 'Mathematics' => $math,
            'Science' => $sci, 'Physics' => $sci, 'Chemistry' => $sci, 'Biology' => $sci,
            'Social Science' => $soc, 'Economics' => $soc, 'History' => $soc,
            'Political Science' => $soc, 'Geography' => $soc,
            'Accountancy' => ['teacher18@teacher.com'],
            'Business Studies' => ['teacher19@teacher.com'],
            'Computer Science' => $cs,
        ];
        $teacherIds = DB::table('users')->where('user_type', 'teacher')->pluck('id', 'email');

        // Starting load: class-level periods for NON-streamed
        // classes only (Prep–Class 10), so the greedy balance below
        // starts from what each teacher already owes.
        $streamedClassIds = DB::table('sections')->whereNotNull('stream')
            ->distinct()->pluck('my_class_id')->all();
        $load = [];
        foreach (DB::table('subjects')->get() as $s) {
            if (in_array($s->my_class_id, $streamedClassIds, true)) {
                continue;
            }
            if ($s->teacher_id) {
                $load[$s->teacher_id] = ($load[$s->teacher_id] ?? 0) + (int) $s->periods_per_week;
            }
        }

        foreach (DB::table('my_classes')->orderBy('id')->get() as $class) {
            $sections = DB::table('sections')
                ->where('my_class_id', $class->id)->whereNotNull('stream')->orderBy('id')->get();
            if ($sections->isEmpty()) {
                continue;
            }
            $subjects = DB::table('subjects')->where('my_class_id', $class->id)->orderBy('id')->get();
            foreach ($sections as $section) {
                foreach ($subjects as $subject) {
                    if (!\App\Support\Streams::appliesTo($subject, $section->stream)) {
                        continue;
                    }
                    $family = $families[$subject->name] ?? null;
                    if (!$family) {
                        continue;
                    }
                    $best = null;
                    $bestLoad = PHP_INT_MAX;
                    foreach ($family as $email) {
                        $tid = $teacherIds[$email] ?? null;
                        if (!$tid) {
                            continue;
                        }
                        $l = $load[$tid] ?? 0;
                        if ($l < $bestLoad) {
                            $bestLoad = $l;
                            $best = $tid;
                        }
                    }
                    if (!$best) {
                        continue;
                    }
                    DB::table('section_subject_teachers')->updateOrInsert(
                        ['section_id' => $section->id, 'subject_id' => $subject->id],
                        ['teacher_id' => $best]
                    );
                    $load[$best] = $bestLoad + (int) $subject->periods_per_week;
                }
            }
        }
    }

    protected function seedClassTimetableFor($class, $subjects, string $session, array $days, array $daySlots, array &$busy, $section = null): void
    {
        $classId = $class->id;

        $ttrQuery = DB::table('time_table_records')
            ->where('my_class_id', $classId)
            ->whereNull('exam_id')
            ->where('year', $session);
        if ($section) {
            $ttrQuery->where('section_id', $section->id);
        } else {
            $ttrQuery->whereNull('section_id');
        }
        $ttr = $ttrQuery->first();

        if ($ttr) {
            $ttrId = $ttr->id;
        } else {
            $className = $class->name ?: 'Class';
            $name = $section ? $className . ' ' . $section->name . ' Timetable' : $className . ' Timetable';
            if (DB::table('time_table_records')->where('name', $name)->exists()) {
                $name .= ' (' . $session . ')';
            }
            $ttrId = DB::table('time_table_records')->insertGetId([
                'name' => $name,
                'my_class_id' => $classId,
                'section_id' => $section ? $section->id : null,
                'exam_id' => null,
                'year' => $session,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Idempotency guard (Batch 6, D17): skip subjects that already
        // have periods in this record; the rest still seed.
        $placedIds = DB::table('time_tables')->where('ttr_id', $ttrId)
            ->distinct()->pluck('subject_id')->all();
        $pending = $subjects->reject(function ($s) use ($placedIds) {
            return in_array($s->id, $placedIds, true);
        })->values();
        if ($pending->isEmpty()) {
            return;
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

        // Round-robin placement with caps + the busy map: each subject
        // stops at its periods_per_week, each teacher never lands two
        // classes on the same (day, slot). Slots where every pending
        // subject is capped or its teacher is busy stay free periods.
        // Batch 2026-10-26: a section grid's teacher is the section
        // override (seedSeniorSectionTeachers), never the subject's
        // class-level teacher — otherwise one specialist starves
        // every later section of the same subject.
        $teacherOf = [];
        if ($section && Schema::hasTable('section_subject_teachers')) {
            $teacherOf = DB::table('section_subject_teachers')
                ->where('section_id', $section->id)->pluck('teacher_id', 'subject_id')->all();
        }
        $subjectList = $pending->all();
        // Most-constrained-first (batch 2026-10-26): sort subjects
        // by their teacher's remaining free slots, scarcest first.
        // Round-robin in id order starved the last subjects: 12-M
        // Physics/Chemistry stuck at 3 of 6 while 16 grid slots sat
        // empty, because English/Hindi/Math (abundant teachers) had
        // already taken the scarce teachers' only free slots.
        usort($subjectList, function ($a, $b) use ($teacherOf, $busy) {
            $ta = $teacherOf[$a->id] ?? $a->teacher_id;
            $tb = $teacherOf[$b->id] ?? $b->teacher_id;
            $fa = 40 - count($busy[$ta] ?? []);
            $fb = 40 - count($busy[$tb] ?? []);
            return $fa <=> $fb ?: $a->id <=> $b->id;
        });
        $demand = [];
        $placed = [];
        foreach ($subjectList as $s) {
            $demand[$s->id] = (int) $s->periods_per_week;
            $placed[$s->id] = 0;
        }
        $ptr = 0;
        $rows = [];
        foreach ($days as $dayNum => $day) {
            foreach ($slots as $slotIndex => $slot) {
                for ($attempt = 0; $attempt < count($subjectList); $attempt++) {
                    $subject = $subjectList[($ptr + $attempt) % count($subjectList)];
                    if ($placed[$subject->id] >= $demand[$subject->id]) {
                        continue;
                    }
                    $key = ($dayNum + 1) . ':' . $slotIndex;
                    $tid = $teacherOf[$subject->id] ?? $subject->teacher_id;
                    if (!empty($busy[$tid][$key])) {
                        continue;
                    }
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
                    $busy[$tid][$key] = true;
                    $placed[$subject->id]++;
                    $ptr = ($ptr + $attempt + 1) % count($subjectList);
                    break;
                }
            }
        }
        // insertOrIgnore: a (ttr, slot, day) cell holds ONE subject.
        // If a real timetable entry already occupies a cell, that row
        // is skipped instead of aborting the whole seed with a unique
        // constraint violation; every free cell still gets its period.
        DB::table('time_tables')->insertOrIgnore($rows);
    }

    /** Seed teacher eligibility from the subjects teachers actually teach.
     *
     * Eligibility is keyed by subject NAME (a teacher's capability,
     * independent of any one class): one row per teacher per distinct
     * subject name they teach. Specialists therefore seed exactly one
     * row each, and the eligibility page stops reading "Not recorded"
     * for every teacher. Guarded on the table being empty, like the
     * other demo domains.
     */
    protected function seedEligibility(): void
    {
        if (!Schema::hasTable('teacher_eligibilities')) {
            return;
        }
        if (DB::table('teacher_eligibilities')->count() > 0) {
            return;
        }
        $now = now();
        $insert = [];
        foreach (DB::table('subjects')->select('teacher_id', 'name')
            ->whereNotNull('teacher_id')->distinct()
            ->orderBy('teacher_id')->orderBy('name')->get() as $r) {
            $insert[] = [
                'teacher_id' => $r->teacher_id,
                'subject_name' => $r->name,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($insert, 200) as $chunk) {
            DB::table('teacher_eligibilities')->insert($chunk);
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
