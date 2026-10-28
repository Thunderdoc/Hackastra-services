<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Admission;
use App\Models\PaymentRecord;
use App\Models\Receipt;
use App\Models\StudentRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Roles audit round 31 — admissions END-TO-END workflow.
 *
 * Owner expansion directive: a covered section expands into its full
 * interaction workflow. Wave 12 locked admissions as pieces (public
 * form, board move, convert pre-fill, teacher bounce); this suite
 * runs ONE continuous chain through the real routes with role gates
 * at each step, and locks what the pieces never proved:
 *
 * Shapes locked here (all read from the controllers before writing):
 *  - public store validates name min:3 / phone min:6 (string only,
 *    NO phone-format rule) / my_class_id exists / message max:1000;
 *    status is always 'new', session is the current session.
 *  - updateStatus validates status in STATUSES but enforces NO
 *    order: jumps (new -> approved) are allowed, and 'converted'
 *    can be asserted by hand with no student behind it.
 *  - convert pre-fills students.create (name/phone/my_class_id)
 *    and marks NOTHING: the enquiry stays 'approved' after convert
 *    AND after a completed admit — the Admin marks 'converted'
 *    manually on the board. That manual step is the ONLY duplicate
 *    protection: admitting twice from one still-'approved' enquiry
 *    creates TWO students (locked in test_double_admit below).
 *  - FIX R31-1 (AdmissionController::convert, NOT parent-owned):
 *    convert now requires status 'approved'. Pre-fix it accepted
 *    ANY status, so a REJECTED enquiry could be converted and
 *    admitted, and a 'converted' enquiry could be re-converted.
 *  - admit (StudentRecordController::store, teamSAWrite) creates
 *    user + student record in one transaction and NOTHING else:
 *    zero PaymentRecords, zero Receipts — fees are created per
 *    class by PaymentController@select_class, never by admission.
 *  - chain friction (NOT fixed — policy call, flagged in report):
 *    the public form accepts 3-5-char names but the admit form
 *    demands min:6, so a converted short name FAILS admit
 *    validation. The office must lengthen the name by hand.
 *
 * Patterns applied (audit-patterns.md): P5 list-vs-detail (board
 * list vs filtered list vs roster page), P6 role-array-vs-middleware
 * (real HTTP for SA/teacher/principal, never array reads), P13
 * empty-state honesty (empty board names itself), P7 flash honesty
 * (status flash names the new state).
 */
class OwnerAdmissionsWorkflowRound31Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->first();
    }

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->first();
    }

    protected function student()
    {
        return User::where('email', 'student@hackstra.app')->first();
    }

    protected function parent()
    {
        return User::where('email', 'parent@hackstra.app')->first();
    }

    protected function principal()
    {
        return User::where('email', 'principal@hackstra.app')->first();
    }

    protected function superAdmin()
    {
        return User::where('email', 'superadmin@hackstra.app')->first();
    }

    protected function makeEnquiry(array $over = []): Admission
    {
        return Admission::create(array_merge([
            'name' => 'Chain Parent',
            'phone' => '08031110000',
            'my_class_id' => 1,
            'message' => 'Seat for my child.',
            'status' => 'new',
            'session' => '2026-2027',
        ], $over));
    }

    protected function admitForm(array $over = []): array
    {
        return array_merge([
            'name' => 'Chain Admitted Child',
            'gender' => 'Male',
            'year_admitted' => '2026',
            'address' => '12 School Road',
            'state_id' => 1,
            'lga_id' => 1,
            'nal_id' => 1,
            'my_class_id' => 1,
            'section_id' => 1,
            'phone' => '08031110000',
        ], $over);
    }

    public function test_public_valid_enquiry_creates_new_row()
    {
        $this->post(route('admission_enquiry.store'), [
            'name' => 'prospective parent',
            'phone' => '08031112222',
            'my_class_id' => 1,
            'message' => 'Seat for my son, Prep.',
        ])->assertRedirect(route('admission_enquiry.form'));

        $row = Admission::where('phone', '08031112222')->first();
        $this->assertNotNull($row);
        $this->assertSame('new', $row->status);
        $this->assertSame(1, (int) $row->my_class_id);
        $this->assertSame('2026-2027', $row->session);
        $this->assertSame('Prospective Parent', $row->name);
    }

    public function test_public_garbage_refused_with_zero_rows()
    {
        $this->post(route('admission_enquiry.store'), [
            'name' => '', 'phone' => '',
        ])->assertSessionHasErrors(['name', 'phone']);

        $this->post(route('admission_enquiry.store'), [
            'name' => 'Al', 'phone' => '08031',
        ])->assertSessionHasErrors(['name', 'phone']);

        $this->post(route('admission_enquiry.store'), [
            'name' => 'Junk Class Parent', 'phone' => '08031113333',
            'my_class_id' => 99999,
        ])->assertSessionHasErrors(['my_class_id']);

        $this->post(route('admission_enquiry.store'), [
            'name' => 'Long Message Parent', 'phone' => '08031114444',
            'message' => str_repeat('x', 1001),
        ])->assertSessionHasErrors(['message']);

        $this->assertSame(0, Admission::count());
    }

    public function test_public_phone_has_no_format_rule_observation()
    {
        // String-length only: a letters-only "phone" passes. Locked
        // as observed behaviour, NOT fixed — phone formats vary and
        // a format rule could reject valid numbers (owner call).
        $this->post(route('admission_enquiry.store'), [
            'name' => 'Letters Phone Parent', 'phone' => 'abcdef',
        ])->assertRedirect(route('admission_enquiry.form'));
        $this->assertNotNull(Admission::where('phone', 'abcdef')->first());
    }

    public function test_empty_board_names_itself_and_filters_match_lists()
    {
        $this->actingAs($this->admin());
        $this->get(route('admissions.index'))->assertOk()
            ->assertSee('No enquiries yet');

        $this->makeEnquiry(['name' => 'New Parent', 'phone' => '08031120001', 'status' => 'new']);
        $this->makeEnquiry(['name' => 'Contacted Parent', 'phone' => '08031120002', 'status' => 'contacted']);
        $this->makeEnquiry(['name' => 'Approved Parent', 'phone' => '08031120003', 'status' => 'approved']);

        $this->get(route('admissions.index'))->assertOk()
            ->assertSee('New Parent')->assertSee('Contacted Parent')->assertSee('Approved Parent');

        $this->get(route('admissions.index', ['status' => 'contacted']))->assertOk()
            ->assertSee('Contacted Parent')
            ->assertDontSee('New Parent')
            ->assertDontSee('Approved Parent');

        $counts = $this->get(route('admissions.index'))->viewData('counts');
        $this->assertSame(1, (int) $counts['new']);
        $this->assertSame(1, (int) $counts['contacted']);
        $this->assertSame(1, (int) $counts['approved']);
    }

    public function test_admin_status_chain_moves_and_jump_allowed()
    {
        $a = $this->makeEnquiry();
        $this->actingAs($this->admin());

        foreach (['contacted', 'test_scheduled', 'approved'] as $next) {
            $this->post(route('admissions.status', $a->id), ['status' => $next])
                ->assertRedirect();
            $this->assertSame($next, $a->fresh()->status);
        }

        // No order enforcement: a fresh row jumps new -> approved.
        $b = $this->makeEnquiry(['phone' => '08031120009']);
        $this->post(route('admissions.status', $b->id), ['status' => 'approved'])
            ->assertRedirect();
        $this->assertSame('approved', $b->fresh()->status);

        // And 'converted' can be asserted by hand with no student
        // behind it — the label is admin-asserted, not system-derived.
        $this->post(route('admissions.status', $b->id), ['status' => 'converted'])
            ->assertRedirect();
        $this->assertSame('converted', $b->fresh()->status);

        // Invalid status still refused, row untouched.
        $this->post(route('admissions.status', $a->id), ['status' => 'enrolled'])
            ->assertSessionHasErrors(['status']);
        $this->assertSame('approved', $a->fresh()->status);
    }

    public function test_admin_status_move_saves_notes_and_names_new_state()
    {
        $a = $this->makeEnquiry();
        $this->actingAs($this->admin());
        $this->from(route('admissions.index'))
            ->post(route('admissions.status', $a->id), [
                'status' => 'contacted', 'notes' => 'Called, test on Monday.',
            ])->assertRedirect(route('admissions.index'))
            ->assertSessionHas('flash_success');
        $this->assertSame('contacted', $a->fresh()->status);
        $this->assertSame('Called, test on Monday.', $a->fresh()->notes);
    }

    public function test_convert_prefills_and_leaves_status_approved()
    {
        $a = $this->makeEnquiry(['status' => 'approved']);
        $this->actingAs($this->admin());

        $response = $this->get(route('admissions.convert', $a->id));
        $response->assertRedirect(route('students.create'));
        $response->assertSessionHasInput('name', 'Chain Parent');
        $response->assertSessionHasInput('phone', '08031110000');
        $response->assertSessionHasInput('my_class_id', 1);

        $this->assertSame('approved', $a->fresh()->status);
        $this->assertNull(User::where('phone', '08031110000')->where('user_type', 'student')->first());
    }

    public function test_full_chain_admit_creates_student_roster_no_fees()
    {
        // Step 1: public enquiry.
        $this->post(route('admission_enquiry.store'), [
            'name' => 'Chain Parent', 'phone' => '08031110000', 'my_class_id' => 1,
        ])->assertRedirect(route('admission_enquiry.form'));
        $a = Admission::where('phone', '08031110000')->first();
        $this->assertSame('new', $a->status);

        // Step 2: board moves to approved.
        $this->actingAs($this->admin());
        foreach (['contacted', 'test_scheduled', 'approved'] as $next) {
            $this->post(route('admissions.status', $a->id), ['status' => $next])->assertRedirect();
        }
        $this->assertSame('approved', $a->fresh()->status);

        // Step 3: convert pre-fills, marks nothing.
        $this->get(route('admissions.convert', $a->id))->assertRedirect(route('students.create'));
        $this->assertSame('approved', $a->fresh()->status);

        // Step 4: admit with the full form.
        $this->post(route('students.store'), $this->admitForm())
            ->assertRedirect(route('students.list', 1));

        $user = User::where('phone', '08031110000')->where('user_type', 'student')->first();
        $this->assertNotNull($user);
        $this->assertSame('Chain Admitted Child', $user->name);
        $record = StudentRecord::where('user_id', $user->id)->first();
        $this->assertNotNull($record);
        $this->assertSame(1, (int) $record->my_class_id);
        $this->assertSame(1, (int) $record->section_id);
        $this->assertSame(Qs::getCurrentSession(), $record->session);
        $this->assertNotEmpty($record->adm_no);

        // Roster page (P5: list shows what admit created).
        $this->get(route('students.list', 1))->assertOk()->assertSee('Chain Admitted Child');

        // Admission creates NO fee artefacts (Receipt has no
        // paymentRecord() relation, so the check goes via pr_id).
        $this->assertSame(0, PaymentRecord::where('student_id', $user->id)->count());
        $this->assertSame(0, Receipt::whereIn('pr_id', PaymentRecord::where('student_id', $user->id)->pluck('id'))->count());

        // Step 5: the enquiry is STILL 'approved' after a completed
        // admit — nothing auto-marks it. Only the manual board move
        // closes the loop.
        $this->assertSame('approved', $a->fresh()->status);
        $this->post(route('admissions.status', $a->id), ['status' => 'converted'])->assertRedirect();
        $this->assertSame('converted', $a->fresh()->status);
    }

    public function test_short_enquiry_name_fails_admit_validation_friction()
    {
        // Public form accepts min:3; admit form demands min:6. A
        // converted 4-char name fails admit validation — the office
        // must lengthen it by hand. Locked as observed friction;
        // aligning the minima is a policy call (report), not a
        // silent change to either form's rule.
        $a = $this->makeEnquiry(['name' => 'Amit', 'phone' => '08031130000', 'status' => 'approved']);
        $this->actingAs($this->admin());
        $this->get(route('admissions.convert', $a->id))->assertRedirect(route('students.create'));
        $this->post(route('students.store'), $this->admitForm([
            'name' => 'Amit', 'phone' => '08031130000',
        ]))->assertSessionHasErrors(['name']);
        $this->assertNull(User::where('phone', '08031130000')->where('user_type', 'student')->first());
    }

    public function test_rejected_convert_is_refused()
    {
        $a = $this->makeEnquiry(['status' => 'rejected']);
        $this->actingAs($this->admin());
        $this->from(route('admissions.index'))
            ->get(route('admissions.convert', $a->id))
            ->assertRedirect(route('admissions.index'))
            ->assertSessionHas('flash_danger');
        $this->assertSame('rejected', $a->fresh()->status);
    }

    public function test_converted_convert_is_refused()
    {
        $a = $this->makeEnquiry(['status' => 'converted']);
        $this->actingAs($this->admin());
        $this->from(route('admissions.index'))
            ->get(route('admissions.convert', $a->id))
            ->assertRedirect(route('admissions.index'))
            ->assertSessionHas('flash_danger');
        $this->assertSame('converted', $a->fresh()->status);
    }

    public function test_premature_convert_is_refused()
    {
        $a = $this->makeEnquiry(['status' => 'contacted']);
        $this->actingAs($this->admin());
        $this->from(route('admissions.index'))
            ->get(route('admissions.convert', $a->id))
            ->assertRedirect(route('admissions.index'))
            ->assertSessionHas('flash_danger');
        $this->assertSame('contacted', $a->fresh()->status);
    }

    public function test_double_admit_before_marking_creates_duplicate()
    {
        // Residual gap, locked honestly: until the Admin marks the
        // enquiry 'converted', the same approved enquiry converts
        // and admits AGAIN — two students, same name/phone, distinct
        // admission numbers. The R31-1 guard closes re-convert AFTER
        // marking; it cannot close this leg without enquiry->student
        // linkage (a feature, not a guard — owner call in report).
        $a = $this->makeEnquiry(['status' => 'approved']);
        $this->actingAs($this->admin());

        $this->get(route('admissions.convert', $a->id))->assertRedirect(route('students.create'));
        $this->post(route('students.store'), $this->admitForm())->assertRedirect(route('students.list', 1));
        $this->assertSame('approved', $a->fresh()->status);

        $this->get(route('admissions.convert', $a->id))->assertRedirect(route('students.create'));
        $this->post(route('students.store'), $this->admitForm())->assertRedirect(route('students.list', 1));

        $users = User::where('phone', '08031110000')->where('user_type', 'student')->get();
        $this->assertCount(2, $users);
        $this->assertCount(2, StudentRecord::whereIn('user_id', $users->pluck('id'))->get());
        $this->assertCount(2, $users->pluck('username')->unique());
    }

    public function test_role_negatives_across_the_chain()
    {
        $a = $this->makeEnquiry(['status' => 'approved']);
        $before = Admission::count();

        // Teacher: every step bounces to login, nothing written.
        $this->actingAs($this->teacher());
        $this->get(route('admissions.index'))->assertRedirect();
        $this->post(route('admissions.status', $a->id), ['status' => 'contacted'])->assertRedirect();
        $this->get(route('admissions.convert', $a->id))->assertRedirect();
        $this->post(route('students.store'), $this->admitForm())->assertRedirect();
        $this->assertSame('approved', $a->fresh()->status);

        // Principal: same shape — teamSA/teamSAWrite exclude principal.
        $this->actingAs($this->principal());
        $this->get(route('admissions.index'))->assertRedirect();
        $this->post(route('admissions.status', $a->id), ['status' => 'contacted'])->assertRedirect();
        $this->get(route('admissions.convert', $a->id))->assertRedirect();

        // Student + parent: board closed.
        $this->actingAs($this->student());
        $this->get(route('admissions.index'))->assertRedirect();
        $this->actingAs($this->parent());
        $this->get(route('admissions.index'))->assertRedirect();

        // Super Admin: PlatformOnly — school GETs bounce to dashboard,
        // school POSTs are 403, even though teamSA names super_admin.
        $this->actingAs($this->superAdmin());
        $this->get(route('admissions.index'))->assertRedirect('/dashboard');
        $this->get(route('admissions.convert', $a->id))->assertRedirect('/dashboard');
        $this->post(route('admissions.status', $a->id), ['status' => 'contacted'])->assertForbidden();
        $this->post(route('students.store'), $this->admitForm())->assertForbidden();

        $this->assertSame($before, Admission::count());
        $this->assertSame('approved', $a->fresh()->status);
        $this->assertNull(User::where('phone', '08031110000')->where('user_type', 'student')->first());
    }
}
