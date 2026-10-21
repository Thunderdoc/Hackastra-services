<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DashboardDemoDataSeeder extends Seeder
{
    public function run()
    {
        $year = (string) (DB::table('settings')->where('type', 'current_session')->value('description') ?: '2018-2019');
        $exam = DB::table('exams')->first();
        $students = DB::table('student_records')
            ->where('grad', 0)
            ->orderBy('id')
            ->take(5)
            ->get();

        if (!$exam || $students->isEmpty()) {
            return;
        }

        $payment = DB::table('payments')->where('ref_no', 'DEMO-TUITION-2018')->first();
        $paymentId = $payment ? $payment->id : DB::table('payments')->insertGetId([
            'title' => 'Demo Tuition Fee',
            'amount' => 50000,
            'ref_no' => 'DEMO-TUITION-2018',
            'method' => 'cash',
            'my_class_id' => $students->first()->my_class_id,
            'description' => 'Demo data for dashboard and payment workflow testing.',
            'year' => $year,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($students as $index => $student) {
            $amountPaid = $index === 0 ? 50000 : ($index === 1 ? 25000 : 35000);
            $balance = max(0, 50000 - $amountPaid);
            $record = DB::table('payment_records')->updateOrInsert(
                ['student_id' => $student->user_id, 'payment_id' => $paymentId],
                [
                    'ref_no' => 'DEMO-PAY-' . $student->user_id,
                    'amt_paid' => $amountPaid,
                    'balance' => $balance,
                    'paid' => $balance === 0 ? 1 : 0,
                    'year' => $year,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            $paymentRecord = DB::table('payment_records')
                ->where(['student_id' => $student->user_id, 'payment_id' => $paymentId])
                ->first();

            DB::table('receipts')->updateOrInsert(
                ['pr_id' => $paymentRecord->id, 'year' => $year],
                [
                    'amt_paid' => $amountPaid,
                    'balance' => $balance,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            $subjects = DB::table('subjects')
                ->where('my_class_id', $student->my_class_id)
                ->orderBy('id')
                ->take(2)
                ->get();

            $total = 0;
            foreach ($subjects as $subjectIndex => $subject) {
                $t1 = 15 + (($index + $subjectIndex) % 5);
                $t2 = 16 + (($index + $subjectIndex) % 4);
                $exm = 52 + (($index * 2 + $subjectIndex) % 9);
                $subjectTotal = $t1 + $t2 + $exm;
                $total += $subjectTotal;
                $grade = DB::table('grades')
                    ->where('mark_from', '<=', $subjectTotal)
                    ->where('mark_to', '>=', $subjectTotal)
                    ->first();

                DB::table('marks')->updateOrInsert(
                    [
                        'student_id' => $student->user_id,
                        'subject_id' => $subject->id,
                        'exam_id' => $exam->id,
                        'year' => $year,
                    ],
                    [
                        'my_class_id' => $student->my_class_id,
                        'section_id' => $student->section_id,
                        't1' => $t1,
                        't2' => $t2,
                        'tca' => $t1 + $t2,
                        'exm' => $exm,
                        'tex1' => $subjectTotal,
                        'cum' => $subjectTotal,
                        'cum_ave' => (string) $subjectTotal,
                        'grade_id' => optional($grade)->id,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }

            DB::table('exam_records')->updateOrInsert(
                ['exam_id' => $exam->id, 'student_id' => $student->user_id, 'year' => $year],
                [
                    'my_class_id' => $student->my_class_id,
                    'section_id' => $student->section_id,
                    'total' => $total,
                    'ave' => number_format($total / max(1, $subjects->count()), 2),
                    'class_ave' => number_format($total / max(1, $subjects->count()), 2),
                    'pos' => $index + 1,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }
}
