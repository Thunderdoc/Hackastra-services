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
 *  - attendances: migration 2026_10_21_000002, Attendance model status
 *    constants, AttendanceController@store (student_record_id + date
 *    unique, status + recorded_by written).
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
            ->where('type', 'current_session')->value('description') ?: '2018-2019');

        $students = DB::table('student_records')
            ->where('grad', 0)
            ->orderBy('id')
            ->get();

        // Demo parent linkage: the seeded demo parent account
        // (parent@parent.com) ships with no children linked, which
        // makes every parent page (children, fees, attendance, admit
        // cards) render its empty state in the sales demo. Link the
        // first two demo students to it so the parent portal shows
        // real data. Deterministic and idempotent.
        $demoParentId = DB::table('users')
            ->where('email', 'parent@parent.com')->value('id');
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

        $this->seedPayments($students, $session);
        $this->seedAttendance($students);
        $this->seedLibrary($students);
        $this->seedEvents();
        $this->seedExamAndMarks($students, $session);
        $this->seedCirculars();
        $this->seedTeacherTimetables($session);
        $this->seedLibrarianUser();
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

        $recordRows = [];
        $receiptRows = [];
        foreach ($students as $student) {
            $bucket = $bucketByUserId[$student->user_id];
            foreach (($paymentsByClass[$student->my_class_id] ?? []) as $j => $payment) {
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
                    'created_at' => now(),
                    'updated_at' => now(),
                    // Stash the record key so receipts can find their
                    // payment_record id after the bulk insert.
                    '_key' => $payment->id . ':' . $student->user_id,
                ];

                if ($amtPaid) {
                    $receiptRows[] = [
                        'amt_paid' => $amtPaid,
                        'balance' => $balance,
                        'year' => $session,
                        'created_at' => now(),
                        'updated_at' => now(),
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
            $recordIds = DB::table('payment_records')
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
     * Attendance for the last 10 school days (Sundays skipped), ending
     * today: ~85% present, ~10% absent, ~5% late per student per day,
     * assigned by a stable hash so the demo never changes shape.
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

        $exam = DB::table('exams')->where('year', $session)->orderBy('id')->first();
        $examId = $exam ? $exam->id : DB::table('exams')->insertGetId([
            'name' => 'Term 1 Examination',
            'term' => 1,
            'year' => $session,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $subjectsByClass = DB::table('subjects')->orderBy('id')->get()->groupBy('my_class_id');
        $grades = DB::table('grades')->whereNull('class_type_id')->get();

        $markRows = [];
        $totals = []; // student user_id => [total, count, student row]
        foreach ($students as $student) {
            $subjects = $subjectsByClass->get($student->my_class_id) ?? collect();
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
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            $totals[$student->user_id] = [$total, max(1, $subjects->count()), $student];
        }

        foreach (array_chunk($markRows, 200) as $chunk) {
            DB::table('marks')->insert($chunk);
        }

        // Exam records: totals per student, position within their
        // class + section (the grouping MarkRepo::getPos uses), and the
        // class average across the whole class.
        $groups = [];
        foreach ($totals as $userId => [$total, $count, $student]) {
            $groups[$student->my_class_id . ':' . $student->section_id][] = $userId;
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
        $classAves = [];
        foreach ($totals as [$total, $count, $student]) {
            $classAves[$student->my_class_id][] = $total / $count;
        }
        foreach ($classAves as $classId => $aves) {
            $classAves[$classId] = number_format(array_sum($aves) / count($aves), 2);
        }

        $recordRows = [];
        foreach ($totals as $userId => [$total, $count, $student]) {
            $recordRows[] = [
                'exam_id' => $examId,
                'student_id' => $userId,
                'my_class_id' => $student->my_class_id,
                'section_id' => $student->section_id,
                'total' => $total,
                'ave' => number_format($total / $count, 2),
                'class_ave' => $classAves[$student->my_class_id],
                'pos' => $positions[$userId],
                'af' => null,
                'ps' => null,
                'p_comment' => null,
                't_comment' => null,
                'year' => $session,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($recordRows, 200) as $chunk) {
            DB::table('exam_records')->insert($chunk);
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

        $publisher = DB::table('users')->where('email', 'cj@cj.com')->value('id')
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
     * Loop 11 — class timetables for the demo teacher so the Teacher
     * Dashboard's "Today's Periods" panel shows real rows on any
     * weekday. Two of the teacher's subject assignments (different
     * classes where the seed provides them) each get a timetable
     * record, three time slots, and one entry per slot for Monday to
     * Friday — written exactly the way TimeTableController@store and
     * @store_time_slot build them (same columns, same strtotime
     * formulas). Guarded per timetable: a record that already has
     * entries is never touched.
     */
    protected function seedTeacherTimetables(string $session): void
    {
        if (!Schema::hasTable('time_tables') || !Schema::hasTable('time_slots')) {
            return;
        }

        $teacherId = DB::table('users')->where('email', 'teacher@teacher.com')->value('id')
            ?: DB::table('users')->where('user_type', 'teacher')->value('id');
        if (!$teacherId) {
            return;
        }

        $subjects = DB::table('subjects')
            ->where('teacher_id', $teacherId)->orderBy('id')->get();
        if ($subjects->isEmpty()) {
            return;
        }

        $picks = collect([$subjects->first()]);
        $second = $subjects->first(function ($s) use ($subjects) {
            return $s->my_class_id !== $subjects->first()->my_class_id;
        }) ?: ($subjects->count() > 1 ? $subjects->get(1) : null);
        if ($second) {
            $picks->push($second);
        }

        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        // [hour_from, min_from, meridian_from, hour_to, min_to, meridian_to]
        $slotSets = [
            [['8', '00', 'AM', '8', '40', 'AM'], ['8', '40', 'AM', '9', '20', 'AM'], ['10', '00', 'AM', '10', '40', 'AM']],
            [['9', '20', 'AM', '10', '00', 'AM'], ['10', '40', 'AM', '11', '20', 'AM'], ['11', '20', 'AM', '12', '00', 'PM']],
        ];

        foreach ($picks as $i => $subject) {
            $ttr = DB::table('time_table_records')
                ->where('my_class_id', $subject->my_class_id)
                ->whereNull('exam_id')
                ->where('year', $session)
                ->first();

            if ($ttr) {
                $ttrId = $ttr->id;
            } else {
                $className = DB::table('my_classes')->where('id', $subject->my_class_id)->value('name') ?: 'Class';
                $name = $className . ' Timetable';
                if (DB::table('time_table_records')->where('name', $name)->exists()) {
                    $name .= ' (' . $session . ')';
                }
                $ttrId = DB::table('time_table_records')->insertGetId([
                    'name' => $name,
                    'my_class_id' => $subject->my_class_id,
                    'exam_id' => null,
                    'year' => $session,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Idempotency guard: never add periods to a timetable that
            // already has entries.
            if (DB::table('time_tables')->where('ttr_id', $ttrId)->count() > 0) {
                continue;
            }

            $slots = [];
            foreach ($slotSets[$i % count($slotSets)] as [$hf, $mf, $merf, $ht, $mt, $mert]) {
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

            $rows = [];
            foreach ($days as $dayNum => $day) {
                foreach ($slots as $slot) {
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
            DB::table('time_tables')->insert($rows);
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
        if (DB::table('users')->where('email', 'librarian@librarian.com')->exists()) {
            return;
        }
        DB::table('users')->insert([
            'name' => 'Demo Librarian',
            'email' => 'librarian@librarian.com',
            'username' => 'librarian',
            'password' => bcrypt('cj'),
            'user_type' => 'librarian',
            'code' => 'LIB' . strtoupper(substr(md5('librarian@librarian.com'), 0, 6)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
