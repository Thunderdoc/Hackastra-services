<?php

namespace Tests\Feature;

use App\Models\ExamDate;
use App\Models\ExamDuty;
use App\Models\MyClass;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\Support\Invigilation;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Exam timetable round 2 (2026-10-28): locks the admit-card union
 * fix and the auto-assign past-sitting skip.
 *
 * (1) A partially dated sheet used to HIDE its undated subjects:
 * one dated paper made the whole admit card list only that paper
 * (exposed by a single stray date row: Prep's card showed only
 * Computer Science). The card must list ALL the class's subjects,
 * with "—" for unannounced dates — the same rule the 0-date case
 * already followed.
 * (2) Auto-assign used to fill sittings whose papers had already
 * happened, and bell their "invigilators" about a past paper.
 * Past sittings are now skipped (manual assignment stays
 * available for deliberate record-keeping).
 */
class OwnerExamDateSheetR2Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function makeClassWithStudent(array $subjects)
    {
        $class = MyClass::create(['name' => 'R2-Class']);
        foreach ($subjects as $i => $name) {
            Subject::create([
                'name' => $name,
                'my_class_id' => $class->id,
                'teacher_id' => 4,
                'slug' => 'r2-' . $class->id . '-' . ($i + 1),
                'periods_per_week' => 6,
            ]);
        }
        $section = \App\Models\Section::create([
            'name' => 'A', 'my_class_id' => $class->id, 'teacher_id' => 4,
        ]);
        $user = User::create([
            'name' => 'R2 Student',
            'email' => 'r2student' . $class->id . '@example.com',
            'password' => bcrypt('student'),
            'user_type' => 'student',
            'code' => 'R2-' . $class->id,
        ]);
        StudentRecord::create([
            'session' => '2026-2027',
            'user_id' => $user->id,
            'my_class_id' => $class->id,
            'section_id' => $section->id,
            'adm_no' => 'R2/' . $class->id,
            'grad' => 0,
        ]);
        return [$class, $user];
    }

    protected function cardFor($class, $user)
    {
        return $this->actingAs($this->admin())
            ->get(route('admit_cards.print', [1, $class->id]) . '?student=' . $user->id);
    }

    public function test_admit_card_unions_dated_and_undated_subjects()
    {
        [$class, $user] = $this->makeClassWithStudent([
            'R2 English', 'R2 Maths', 'R2 Science', 'R2 Social', 'R2 Hindi', 'R2 Computers',
        ]);
        $dated = Subject::where('my_class_id', $class->id)->where('name', 'R2 Computers')->firstOrFail();
        ExamDate::create([
            'exam_id' => 1, 'my_class_id' => $class->id, 'subject_id' => $dated->id,
            'exam_date' => '2026-11-05', 'start_time' => '09:00:00',
        ]);

        $response = $this->cardFor($class, $user);
        $response->assertOk();
        foreach (['R2 English', 'R2 Maths', 'R2 Science', 'R2 Social', 'R2 Hindi', 'R2 Computers'] as $name) {
            $response->assertSee($name);
        }
        $response->assertSee('05 Nov 2026');
        $response->assertSee('—');
    }

    public function test_admit_card_with_no_dates_lists_all_subjects()
    {
        [$class, $user] = $this->makeClassWithStudent([
            'R2 English', 'R2 Maths', 'R2 Science', 'R2 Social', 'R2 Hindi', 'R2 Computers',
        ]);

        $response = $this->cardFor($class, $user);
        $response->assertOk();
        foreach (['R2 English', 'R2 Maths', 'R2 Science', 'R2 Social', 'R2 Hindi', 'R2 Computers'] as $name) {
            $response->assertSee($name);
        }
        $response->assertSee('—');
    }

    public function test_auto_assign_skips_past_sittings()
    {
        $past = ExamDate::create([
            'exam_id' => 1, 'my_class_id' => 1, 'subject_id' => 1,
            'exam_date' => '2026-10-20', 'start_time' => '09:00:00',
        ]);
        $future = ExamDate::create([
            'exam_id' => 1, 'my_class_id' => 1, 'subject_id' => 2,
            'exam_date' => '2026-11-10', 'start_time' => '09:00:00',
        ]);

        $result = Invigilation::autoAssign(1, $this->admin()->id);

        $this->assertGreaterThanOrEqual(1, $result['skipped_past']);
        $this->assertFalse(ExamDuty::where('exam_date_id', $past->id)->exists());
        $this->assertTrue(ExamDuty::where('exam_date_id', $future->id)->exists());
    }
}
