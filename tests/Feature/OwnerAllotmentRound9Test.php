<?php

namespace Tests\Feature;

use App\Support\Bulk\StaffBulkModule;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Agent F round 9 — print-gate check after the round-8 widening.
 *
 * Round 8 widened the tabulation SHEET view + picker for
 * override-only teachers (MarkController::teacherSectionIds now
 * counts direct subjects OR any override in the class). This test
 * locks the settled rule that the widening did NOT extend to the
 * print/bulk documents: those routes stay teamOrPrincipal:SA, so
 * a teacher — override teacher included — is refused at the route
 * gate (redirect to login) before any controller code runs.
 * The sheet itself stays 200 for her own override section, and
 * the principal's school-wide monitoring read stays open.
 *
 * Anchors: Ritu Sharma (teacher20@teacher.com, id 44) holds ONLY
 * overrides — (55,67), (58,67), (61,67) in Class 11 (my_class_id
 * 12) and (65,79), (71,79) in Class 12. Exam 1 is the global Term
 * 1 exam; Class 11-A (section 55) has seeded marks, so the sheet
 * and print document render once the gate passes.
 */
class OwnerAllotmentRound9Test extends TestCase
{
    use DatabaseTransactions;

    protected function ritu()
    {
        return User::where('email', 'teacher20@teacher.com')->firstOrFail();
    }

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function principal()
    {
        return User::where('email', 'principal@hackstra.app')->firstOrFail();
    }

    public function test_override_teacher_tabulation_print_refused()
    {
        // Route gate teamOrPrincipal:SA fails for a teacher, so the
        // request never reaches print_tabulation's own teacher
        // check — it redirects to login.
        $this->actingAs($this->ritu())
            ->get(route('marks.print_tabulation', [1, 12, 55]))
            ->assertRedirect(route('login'));
    }

    public function test_override_teacher_bulk_documents_refused()
    {
        $ritu = $this->ritu();

        $this->actingAs($ritu)
            ->get(route('marks.bulk'))
            ->assertRedirect(route('login'));

        $this->actingAs($ritu)
            ->post(route('marks.bulk_select'), ['my_class_id' => 12, 'section_id' => 55])
            ->assertRedirect(route('login'));

        $this->actingAs($ritu)
            ->get(route('marks.bulk_download', ['exam_id' => 1, 'my_class_id' => 12, 'section_id' => 55]))
            ->assertRedirect(route('login'));
    }

    public function test_override_teacher_sheet_view_still_open()
    {
        // Contrast control: the round-8 widening itself still holds
        // — the sheet view is 200 for her own override section.
        $this->actingAs($this->ritu())
            ->get(route('marks.tabulation', [1, 12, 55]))
            ->assertOk();
    }

    public function test_admin_and_principal_print_still_open()
    {
        // Admin keeps generation; principal keeps the school-wide
        // monitoring read (settled limited-role rule).
        $this->actingAs($this->admin())
            ->get(route('marks.print_tabulation', [1, 12, 55]))
            ->assertOk();

        $this->actingAs($this->principal())
            ->get(route('marks.print_tabulation', [1, 12, 55]))
            ->assertOk();
    }

    /**
     * F-R9-1: bulk ADD rollback deleted a teacher who held ONLY
     * section overrides. Its guards checked direct subjects and led
     * sections but not section_subject_teachers — and that table
     * has NO foreign keys, so the delete orphaned the override rows
     * (dead teacher ids that still "look staffed" to displays).
     * This is the bulk-path cousin of the single-delete guard
     * (UserController@destroy refuses override holders since
     * round 8's parent fix): the two delete paths must agree.
     */
    public function test_bulk_rollback_refuses_override_holder()
    {
        $teacher = User::create([
            'name' => 'Probe Teacher R9',
            'username' => 'TEST/R9/' . strtoupper(Str::random(6)),
            'email' => 'probe-r9-override@example.com',
            'password' => 'secret',
            'code' => 'TEST/R9/' . strtoupper(Str::random(6)),
            'user_type' => 'teacher',
        ]);
        // Free (section, subject) pair, found dynamically: section
        // 55 already holds overrides for several Class 11 subjects,
        // so take the lowest Class 11 subject id with no row there.
        $subjectId = DB::table('subjects')->where('my_class_id', 12)
            ->whereNotIn('id', DB::table('section_subject_teachers')
                ->where('section_id', 55)->pluck('subject_id'))
            ->orderBy('id')->value('id');
        $this->assertNotNull($subjectId, 'No free override pair in section 55.');
        DB::table('section_subject_teachers')->insert([
            'section_id' => 55,
            'subject_id' => $subjectId,
            'teacher_id' => $teacher->id,
        ]);

        $module = new StaffBulkModule(new \App\Repositories\UserRepo);

        // Flag, not fail(), inside the try: PHPUnit's fail() throws
        // an AssertionFailedError that extends RuntimeException,
        // so a fail() here would be swallowed by the catch below.
        $refused = false;
        try {
            $module->rollbackAdd($teacher->id);
        } catch (\RuntimeException $e) {
            $this->assertMatchesRegularExpression('/override/', $e->getMessage());
            $refused = true;
        }
        $this->assertTrue($refused, 'rollbackAdd should have refused an override holder.');

        // Post-condition the fix must satisfy: user AND override
        // row survive the refused rollback.
        $this->assertDatabaseHas('users', ['id' => $teacher->id]);
        $this->assertDatabaseHas('section_subject_teachers', [
            'section_id' => 55, 'subject_id' => $subjectId, 'teacher_id' => $teacher->id,
        ]);
    }

    public function test_bulk_rollback_still_deletes_unattached_teacher()
    {
        $teacher = User::create([
            'name' => 'Probe Teacher R9B',
            'username' => 'TEST/R9/' . strtoupper(Str::random(6)),
            'email' => 'probe-r9-plain@example.com',
            'password' => 'secret',
            'code' => 'TEST/R9/' . strtoupper(Str::random(6)),
            'user_type' => 'teacher',
        ]);

        (new StaffBulkModule(new \App\Repositories\UserRepo))->rollbackAdd($teacher->id);

        $this->assertDatabaseMissing('users', ['id' => $teacher->id]);
    }
}
