<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Exam;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\Support\TeacherScope;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Roles agent C round 8: regression watch after agent E round 8
 * (exam-date null-scope guard, duty-bell cleanup) and agent F round 8
 * (tabulation sheet widened for override teachers, TeacherScope static
 * memo removed). This file proves the widening did NOT leak sideways:
 * students/parents still cannot reach tabulation at all, override
 * teachers still cannot print/bulk, scope reads stay fresh after
 * allotment changes, and the principal empty-scope print/pdf guard
 * redirects instead of 500ing. Findings in forbidden files are
 * reported, not edited.
 */
class OwnerPortalContainmentRound8Test extends TestCase
{
    use DatabaseTransactions;

    protected function byEmail($email)
    {
        return User::where('email', $email)->firstOrFail();
    }

    protected function student() { return $this->byEmail('student@hackstra.app'); }
    protected function parent() { return $this->byEmail('parent@hackstra.app'); }
    protected function principal() { return $this->byEmail('principal@hackstra.app'); }
    protected function ritu() { return $this->byEmail('teacher20@teacher.com'); }

    protected function foreignRecord()
    {
        // Truly foreign for parent@: NULL my_parent_id, NOT user 58
        // (parent@'s own second child — the round-7 trap).
        return StudentRecord::whereNull('my_parent_id')
            ->where('user_id', '!=', $this->student()->id)->firstOrFail();
    }

    // ---------------- F-R8-1 containment: students/parents ----------------

    public function test_student_and_parent_cannot_reach_tabulation_or_print()
    {
        // Tabulation sheet view is teamOrPrincipal:SAT; print/bulk
        // are teamOrPrincipal:SA. Neither students nor parents are
        // in any team, so the team middleware must bounce them to
        // login — never a 200 with another class's sheet.
        $this->actingAs($this->student())
            ->get(route('marks.tabulation', [1, 12, 55]))->assertStatus(302);
        $this->actingAs($this->parent())
            ->get(route('marks.tabulation', [1, 12, 55]))->assertStatus(302);
        $this->actingAs($this->student())
            ->get(route('marks.print_tabulation', [1, 12, 55]))->assertStatus(302);
        $this->actingAs($this->parent())
            ->get(route('marks.print_tabulation', [1, 12, 55]))->assertStatus(302);
        $this->actingAs($this->student())
            ->get(route('marks.bulk', [12, 55]))->assertStatus(302);
        $this->actingAs($this->parent())
            ->get(route('marks.bulk', [12, 55]))->assertStatus(302);
    }

    public function test_student_and_parent_foreign_marksheets_still_redirect()
    {
        $student = $this->student();
        $parent = $this->parent();
        $year = Qs::getCurrentSession();
        $other = User::where('user_type', 'student')
            ->where('email', '!=', 'student@hackstra.app')->firstOrFail();
        $foreignUser = User::findOrFail($this->foreignRecord()->user_id);

        $this->actingAs($student)
            ->get(route('marks.print', [Qs::hash($other->id), 1, $year]))
            ->assertRedirect(route('dashboard'));
        $this->actingAs($parent)
            ->get(route('marks.print', [Qs::hash($foreignUser->id), 1, $year]))
            ->assertRedirect(route('dashboard'));
        $this->actingAs($parent)
            ->get(route('marks.print_annual', [Qs::hash($foreignUser->id), $year]))
            ->assertRedirect(route('dashboard'));
    }

    // ---------------- F-R8-1 containment: override teacher ----------------

    public function test_override_teacher_sheet_open_but_print_and_bulk_closed()
    {
        // Ritu Sharma (teacher20@, id 44): zero direct subjects, zero
        // led sections, overrides in Class 11 (my_class_id 12),
        // section 55. Round 8 opened the SHEET for her; print/bulk
        // stay teamOrPrincipal:SA and must still refuse her.
        $ritu = $this->ritu();
        $this->actingAs($ritu)
            ->get(route('marks.tabulation', [1, 12, 55]))->assertOk();
        $this->actingAs($ritu)
            ->get(route('marks.print_tabulation', [1, 12, 55]))->assertStatus(302);
        $this->actingAs($ritu)
            ->get(route('marks.bulk', [12, 55]))->assertStatus(302);
        $this->actingAs($ritu)
            ->get(route('marks.bulk_download', ['class_id' => 12, 'section_id' => 55]))
            ->assertStatus(302);
    }

    // ---------------- F-R8-2: scope reads fresh, no memo ----------------

    public function test_teacher_scope_follows_allotment_changes_without_reset()
    {
        // Under the old static memo, the FIRST classIds() call for a
        // user id cached forever in-process: a teacher allotted
        // AFTER that call kept the stale scope. No reset step is
        // taken anywhere in this test — fresh reads must show the
        // grant and the revocation in the same process.
        $teacher = User::create([
            'name' => 'Round8 Scope Probe',
            'email' => 'probe-scope-c8-' . uniqid() . '@example.com',
            'password' => Hash::make('probe'),
            'user_type' => 'teacher',
            'code' => 'PRB-C8-' . uniqid(),
        ]);

        $this->assertSame([], TeacherScope::classIds($teacher));
        $this->assertFalse(TeacherScope::canTeachClass($teacher, 1));

        $subject = Subject::create([
            'name' => 'Round8 Probe Subject', 'my_class_id' => 1,
            'teacher_id' => $teacher->id, 'slug' => 'r8-probe-' . uniqid(),
            'periods_per_week' => 6,
        ]);
        $this->assertContains(1, TeacherScope::classIds($teacher));
        $this->assertTrue(TeacherScope::canTeachClass($teacher, 1));
        $this->assertFalse(TeacherScope::canTeachClass($teacher, 2));

        // Revocation must also be visible immediately (stale-allow
        // is the mirror vector of the stale-deny the memo caused).
        $subject->delete();
        $this->assertSame([], TeacherScope::classIds($teacher));
        $this->assertFalse(TeacherScope::canTeachClass($teacher, 1));
    }

    // ---------------- E-R8: principal empty-scope guard ----------------

    public function test_principal_exam_dates_print_pdf_redirect_on_empty_scope()
    {
        // No exams in the current session: the principal's print/pdf
        // must land on the index that explains the empty state —
        // not 500, and not a leaked sheet.
        Exam::query()->delete();
        $this->actingAs($this->principal())
            ->get(route('exam_dates.print'))
            ->assertRedirect(route('exam_dates.index'));
        $this->actingAs($this->principal())
            ->get(route('exam_dates.pdf'))
            ->assertRedirect(route('exam_dates.index'));
    }
}
