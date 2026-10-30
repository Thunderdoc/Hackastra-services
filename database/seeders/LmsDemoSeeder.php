<?php

namespace Database\Seeders;

use App\Support\DemoToday;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Workstream CS1 — LMS demo content so the sales demo shows the
 * assignments + study-materials module with real data on first boot:
 *
 *  - The teacher bound to the seeded English Language subjects owns
 *    both assignments (resolved from the subject rows at seed time —
 *    no identity is hardcoded here).
 *  - One OPEN assignment in Nursery 1 (English Language) — the demo
 *    parent's linked child has already submitted, so the parent
 *    portal shows a live submission status.
 *  - One PAST-DUE assignment in Primary 1 (English Language) with
 *    three submissions, two of them graded (8/10 and 6/10) — this is
 *    the data the Internal-marks bridge averages: 80% and 60% scale
 *    onto the English scheme's Internal Assessment max of 30 as
 *    24 and 18.
 *  - Two study materials (link-based, no fake files).
 *
 * Idempotent: no-ops when assignments already exist.
 */
class LmsDemoSeeder extends Seeder
{
    public function run()
    {
        if (DB::table('assignments')->count() > 0) {
            return;
        }

        $now = DemoToday::now();

        // Open assignment — Prep English Language (subject is the
        // class-bound English row; its teacher owns the demo content).
        $openSubject = DB::table('subjects')
            ->where('my_class_id', 1)->where('name', 'English Language')->first();

        $teacherId = $openSubject->teacher_id ?? null;
        if (!$teacherId) {
            return;
        }
        $openId = null;
        if ($openSubject) {
            $openId = DB::table('assignments')->insertGetId([
                'teacher_id' => $teacherId,
                'my_class_id' => 1,
                'section_id' => null,
                'subject_id' => $openSubject->id,
                'title' => 'English Language - Reading Comprehension',
                'description' => 'Read the short passage in your English reader (pages 12-14) and answer the questions at the end in your own words. You may type your answers here or upload a photo/scan of your notebook page.',
                'attachment_path' => null,
                'max_marks' => 10,
                'due_at' => $now->copy()->addDays(5),
                'allow_late' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // The demo parent's first child has submitted already.
            $childId = DB::table('student_records')
                ->where('my_class_id', 1)->where('grad', 0)->orderBy('id')->value('user_id');
            if ($childId) {
                DB::table('assignment_submissions')->insert([
                    'assignment_id' => $openId,
                    'student_id' => $childId,
                    'attachment_path' => null,
                    'text_answer' => 'The passage is about a little bird who learns to fly. The bird was scared at first, but its mother helped it and it flew over the garden.',
                    'submitted_at' => $now->copy()->subHours(6),
                    'is_late' => false,
                    'marks_awarded' => null,
                    'feedback' => null,
                    'graded_by' => null,
                    'graded_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        // Past-due assignment — Class 3 English Language, with a
        // graded submission set for the Internal-marks bridge demo.
        $pastSubject = DB::table('subjects')
            ->where('my_class_id', 4)->where('name', 'English Language')->first();
        if ($pastSubject) {
            $pastId = DB::table('assignments')->insertGetId([
                'teacher_id' => $teacherId,
                'my_class_id' => 4,
                'section_id' => null,
                'subject_id' => $pastSubject->id,
                'title' => 'English Language - Essay: My Favourite Season',
                'description' => 'Write a short essay (150-200 words) about your favourite season. Say what you like about it, what you wear, and one activity you enjoy in that season.',
                'attachment_path' => null,
                'max_marks' => 10,
                'due_at' => $now->copy()->subDays(3),
                'allow_late' => false,
                'created_at' => $now->copy()->subDays(7),
                'updated_at' => $now->copy()->subDays(7),
            ]);

            $students = DB::table('student_records')
                ->where('my_class_id', 4)->where('grad', 0)
                ->orderBy('id')->pluck('user_id')->all();

            $graded = [
                // user order in class => [marks, feedback]
                0 => [8, 'Well structured essay with good vocabulary. Watch your punctuation in long sentences.'],
                1 => [6, 'Good ideas, but the essay is too short. Add one more paragraph about activities.'],
            ];
            foreach ($students as $i => $studentId) {
                $row = [
                    'assignment_id' => $pastId,
                    'student_id' => $studentId,
                    'attachment_path' => null,
                    'text_answer' => 'My favourite season is winter. I like the cool air and wearing my warm jacket. We drink hot soup at home and I play outside with my friends in the afternoon.',
                    'submitted_at' => $now->copy()->subDays(4),
                    'is_late' => false,
                    'created_at' => $now->copy()->subDays(4),
                    'updated_at' => $now->copy()->subDays(4),
                ];
                if (isset($graded[$i])) {
                    $row['marks_awarded'] = $graded[$i][0];
                    $row['feedback'] = $graded[$i][1];
                    $row['graded_by'] = $teacherId;
                    $row['graded_at'] = $now->copy()->subDays(2);
                }
                DB::table('assignment_submissions')->insert($row);
            }
        }

        // Study materials (links — no fabricated files).
        $materials = [];
        if ($pastSubject) {
            $materials[] = [
                'teacher_id' => $teacherId,
                'my_class_id' => 4,
                'section_id' => null,
                'subject_id' => $pastSubject->id,
                'title' => 'Grammar Notes - Unit 3: Nouns and Pronouns',
                'description' => 'Class notes for Unit 3. Read before the next English period; the examples are the ones we practised in class.',
                'file_path' => null,
                'link_url' => 'https://example.org/hackstra/primary1/grammar-unit-3',
            ];
        }
        if ($openSubject) {
            $materials[] = [
                'teacher_id' => $teacherId,
                'my_class_id' => 1,
                'section_id' => null,
                'subject_id' => $openSubject->id,
                'title' => 'Alphabet Phonics Chart',
                'description' => 'The phonics chart we use in class - practise the letter sounds at home with your parents.',
                'file_path' => null,
                'link_url' => 'https://example.org/hackstra/nursery1/phonics-chart',
            ];
        }
        foreach ($materials as $material) {
            DB::table('study_materials')->insert($material + [
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
