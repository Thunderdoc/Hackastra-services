<?php

namespace Tests\Feature;

use App\Models\ExamDate;
use App\Models\MyClass;
use App\Models\Section;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Batch 2026-10-28 (exam-timetable round 37) — ZERO-SCHEDULE
 * ADMIT RENDERING: what does a card look like when the student
 * has NO schedule at all (no exam_dates rows for her class AND
 * no timetable exam grid for her class)? This closes the
 * round-23 "zero-subject admit wording variant" observation
 * with evidence instead of carrying it as a guess.
 *
 * Code read first (AdmitCardController::buildSchedule):
 * the schedule NEVER comes back empty for a class that HAS
 * subjects — the fully-dateless case returns the class's
 * subject list with null dates + fallback=true. The blade
 * then renders, ON THE PRINTED CARD ITSELF (not just the
 * screen-only toolbar), the note "Exam dates & times for
 * the subjects above will be announced by the school office."
 * The truly empty row ("No subjects found for this class.")
 * fires only when the class has ZERO subjects at all.
 * This suite proves all three shapes end to end through
 * the real myCard + printSheet routes, plus the dated
 * control and the empty-roster branch.
 *
 * Fixture rules: fresh classes/subjects/sections/students
 * (all in-transaction, rolled back); exam 1 is the global
 * Term 1 exam; fixture students carry NO payment records,
 * so FeeDefaulter::outstandingFor returns 0 and the fee
 * block never masks the schedule question. myCard takes a
 * RAW user id (route param {student_id}); printSheet takes
 * RAW exam/class ids (route params {exam}/{class}).
 */
class OwnerExamDateSheetR37Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function makeStudent($name, $classId, $sectionId)
    {
        $user = User::create([
            'name' => $name, 'email' => null, 'phone' => null,
            'dob' => null, 'gender' => 'Female', 'address' => 'Test Address',
            'user_type' => 'student', 'code' => strtoupper(Str::random(10)),
            'password' => Hash::make('student'), 'username' => 'TEST/R37/' . strtoupper(Str::random(6)),
        ]);
        StudentRecord::create([
            'user_id' => $user->id, 'my_class_id' => $classId, 'section_id' => $sectionId,
            'adm_no' => 'TEST/R37/' . $user->id, 'session' => '2026-2027',
            'year_admitted' => '2026', 'grad' => 0,
        ]);
        return $user;
    }

    protected function makeClass($name, array $subjects, $slugPrefix)
    {
        $class = MyClass::create(['name' => $name, 'class_type_id' => 4]);
        $section = Section::create(['name' => 'R37-A', 'my_class_id' => $class->id, 'teacher_id' => 4]);
        $created = [];
        foreach ($subjects as $i => $subjectName) {
            $created[] = Subject::create([
                'name' => $subjectName, 'my_class_id' => $class->id,
                'teacher_id' => 4, 'slug' => $slugPrefix . '-' . $class->id . '-' . ($i + 1),
                'periods_per_week' => 6,
            ]);
        }
        return [$class, $section, $created];
    }

    public function test_mycard_dateless_subjects_show_fallback_note_and_subjects()
    {
        [$class, $section] = $this->makeClass('R37 Dateless Class', ['R37-Algebra', 'R37-Biology'], 'r37-date');
        $student = $this->makeStudent('R37 Dateless Pupil', $class->id, $section->id);

        $this->assertSame(0, ExamDate::where('exam_id', 1)->where('my_class_id', $class->id)->count(),
            'Fixture truth: no date rows exist for this class');

        $resp = $this->actingAs($student)->get(route('admit_cards.my_card', ['exam' => 1, 'student_id' => $student->id]));
        $resp->assertOk();
        // The honest named empty state, ON THE PRINTED CARD itself.
        $resp->assertSee('Exam dates & times for the subjects above will be announced by the school office.');
        // Screen-only toolbar alert names the same state for staff.
        $resp->assertSee('No exam timetable or date sheet has been built for');
        // Subjects still list, dateless (no date string can appear).
        $resp->assertSee('R37-Algebra');
        $resp->assertSee('R37-Biology');
        $resp->assertDontSee('Tue, 01 Dec 2026');
        $resp->assertSee('R37 Dateless Pupil');
    }

    public function test_bulk_print_dateless_section_shows_same_fallback()
    {
        [$class, $section] = $this->makeClass('R37 Bulk Class', ['R37-Chemistry', 'R37-Drama'], 'r37-bulk');
        $student = $this->makeStudent('R37 Bulk Pupil', $class->id, $section->id);

        $resp = $this->actingAs($this->admin())
            ->get(route('admit_cards.print', ['exam' => 1, 'class' => $class->id]) . '?section_id=' . $section->id);
        $resp->assertOk();
        $resp->assertSee('Exam dates & times for the subjects above will be announced by the school office.');
        $resp->assertSee('No exam timetable or date sheet has been built for');
        $resp->assertSee('R37-Chemistry');
        $resp->assertSee('R37-Drama');
        $resp->assertSee('R37 Bulk Pupil');
        $resp->assertDontSee('Tue, 01 Dec 2026');
    }

    public function test_zero_subject_class_card_renders_no_subjects_row()
    {
        // A class with NO subjects at all: buildSchedule returns
        // rows=[] + fallback=true, so the card's @empty row fires.
        [$class, $section] = $this->makeClass('R37 Empty Class', [], 'r37-empty');
        $student = $this->makeStudent('R37 Empty Pupil', $class->id, $section->id);

        $resp = $this->actingAs($student)->get(route('admit_cards.my_card', ['exam' => 1, 'student_id' => $student->id]));
        $resp->assertOk();
        $resp->assertSee('No subjects found for this class.');
        $resp->assertSee('ADMIT CARD');
        $resp->assertSee('R37 Empty Pupil');
        // Fallback note still present: dates are (vacuously) unannounced.
        $resp->assertSee('Exam dates & times for the subjects above will be announced by the school office.');
    }

    public function test_one_dated_paper_renders_date_and_no_fallback_note()
    {
        [$class, $section, $subjects] = $this->makeClass('R37 Dated Class', ['R37-Economics', 'R37-French'], 'r37-dated');
        $student = $this->makeStudent('R37 Dated Pupil', $class->id, $section->id);
        ExamDate::create([
            'exam_id' => 1, 'my_class_id' => $class->id, 'subject_id' => $subjects[0]->id,
            'exam_date' => '2026-12-01', 'start_time' => '09:00', 'note' => null,
        ]);

        $resp = $this->actingAs($student)->get(route('admit_cards.my_card', ['exam' => 1, 'student_id' => $student->id]));
        $resp->assertOk();
        $resp->assertSee('Tue, 01 Dec 2026');
        $resp->assertSee('9:00 AM');
        // Dated sheet: no fallback note, no toolbar alert.
        $resp->assertDontSee('will be announced by the school office');
        $resp->assertDontSee('No exam timetable or date sheet has been built for');
        // Union rule (round 2): the undated subject still lists.
        $resp->assertSee('R37-Economics');
        $resp->assertSee('R37-French');
    }

    public function test_empty_roster_bulk_print_shows_no_students_state()
    {
        [$class, $section] = $this->makeClass('R37 No Pupils Class', ['R37-Geography'], 'r37-nopup');

        $resp = $this->actingAs($this->admin())
            ->get(route('admit_cards.print', ['exam' => 1, 'class' => $class->id]) . '?section_id=' . $section->id);
        $resp->assertOk();
        $resp->assertSee('No students found for this selection, so there are no admit cards to print.');
        $resp->assertDontSee('ADMIT CARD');
    }
}
