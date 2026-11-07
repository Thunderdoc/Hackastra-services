<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Book;
use App\Models\Payment;
use App\Models\PaymentRecord;
use App\Models\StudentRecord;
use App\Models\UserNotification;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Round 5 Slice 3 (P10) — shared bulk-actions bar + list selection.
 *
 *  - One partial (partials/patterns/bulk_bar) + one JS module (the
 *    P10 section of hackstra-ux.js): rows carry .hk-bulk-check with
 *    NO name; the module appends hidden ids[] to the action's own
 *    hidden form on submit and substitutes {count} into the P19
 *    window.hackstraConfirm prompt. Selection is page-scoped and
 *    the bar says so.
 *  - Users: bulk Suspend / Activate re-checks EVERY id against
 *    the single-row rules (self, head SA, mayWriteUserTarget,
 *    last active SA, already-in-state) — ineligible ids are
 *    skipped and reported, never fatal. Export-selected is the
 *    P12 column set intersected with the viewer's scoped list,
 *    so tampered ids cannot leak out-of-scope rows.
 *  - Books: Export selected only — no bulk delete (loans).
 *  - Students: Export selected joins the existing bulk-delete
 *    bar; every other behaviour of that bar is unchanged.
 *  - Defaulters: the page's OPTIONAL selection narrows the two
 *    existing set actions (remind / export) to checked ids ∩ the
 *    filtered set; figures come from FeeDefaulter only.
 *
 * Seeded users are fetched by email, never by numeric id.
 * NOTE for the gate: the four new route names (users.bulk_status,
 * users.export_selected, books.export_selected,
 * students.export_selected) land via the coordinator's web.php
 * snippets — this file pins their names and contracts.
 */
class OwnerRound5BulkActionsTest extends TestCase
{
    use DatabaseTransactions;

    protected function userByEmail(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    protected function admin(): User
    {
        return $this->userByEmail('admin@hackstra.app');
    }

    protected function superAdmin(): User
    {
        return $this->userByEmail('superadmin@hackstra.app');
    }

    protected function teacher(): User
    {
        return $this->userByEmail('teacher@hackstra.app');
    }

    protected function librarian(): User
    {
        return $this->userByEmail('librarian@hackstra.app');
    }

    protected function file(string $rel): string
    {
        return file_get_contents(base_path($rel));
    }

    protected function makeTeacher(string $suffix): User
    {
        return User::create([
            'name' => 'P10 Probe ' . $suffix,
            'username' => 'p10probe' . strtolower($suffix),
            'email' => 'p10probe' . strtolower($suffix) . '@example.test',
            'phone' => null,
            'dob' => null,
            'gender' => 'Female',
            'address' => 'Test Address',
            'user_type' => 'teacher',
            'code' => strtoupper(Str::random(10)),
            'password' => Hash::make('teacher'),
        ]);
    }

    /* ---------------- (a) shared bar + module source pins ---------------- */

    public function test_bulk_bar_partial_and_js_module_carry_the_contract()
    {
        $bar = $this->file('resources/views/partials/patterns/bulk_bar.blade.php');
        $this->assertStringContainsString('data-hk-bulk-bar', $bar);
        $this->assertStringContainsString('data-hk-bulk-select-all', $bar);
        $this->assertStringContainsString('data-hk-bulk-count', $bar);
        $this->assertStringContainsString('data-hk-bulk-clear', $bar);
        $this->assertStringContainsString('data-hk-bulk-action', $bar);
        // Page scope is stated honestly, never implied cross-page.
        $this->assertStringContainsString('Selection covers this page only.', $bar);
        // The row contract: hk-bulk-check, no name attribute.
        $this->assertStringContainsString('hk-bulk-check', $bar);

        $ux = $this->file('public/assets/js/hackstra-ux.js');
        $this->assertStringContainsString('P10: hk-bulk', $ux);
        $this->assertStringContainsString("document.querySelectorAll('.hk-bulk-check')", $ux);
        $this->assertStringContainsString("h.name = 'ids[]'", $ux);
        // {count} is substituted at click time, confirmed via P19.
        $this->assertStringContainsString("split('{count}')", $ux);
        $this->assertStringContainsString('window.hackstraConfirm(message, opts)', $ux);
    }

    public function test_lists_render_the_bar_and_checkboxes()
    {
        $users = $this->file('resources/views/pages/support_team/users/index.blade.php');
        $this->assertStringContainsString('hk-bulk-check', $users);
        $this->assertStringContainsString('partials.patterns.bulk_bar', $users);
        $this->assertStringContainsString("route('users.bulk_status')", $users);
        $this->assertStringContainsString("route('users.export_selected')", $users);

        $books = $this->file('resources/views/pages/support_team/books/index.blade.php');
        $this->assertStringContainsString('hk-bulk-check', $books);
        $this->assertStringContainsString("route('books.export_selected')", $books);
        // Books get NO bulk delete — neither in the blade nor the controller.
        $this->assertStringNotContainsString('bulk_delete', $books);
        $this->assertStringNotContainsString('function bulkDelete',
            $this->file('app/Http/Controllers/SupportTeam/BookController.php'));

        $students = $this->file('resources/views/pages/support_team/students/list.blade.php');
        $this->assertStringContainsString("route('students.export_selected')", $students);
        $this->assertStringContainsString('bulk-export-btn', $students);
        // The pre-existing delete bar is untouched.
        $this->assertStringContainsString("route('students.bulk_delete')", $students);
        $this->assertStringContainsString('bulk-delete-btn', $students);

        $defaulters = $this->file('resources/views/pages/support_team/payments/defaulters.blade.php');
        $this->assertStringContainsString('hk-bulk-check', $defaulters);
        $this->assertStringContainsString('def-bulk-remind-form', $defaulters);
        $this->assertStringContainsString('def-bulk-export-form', $defaulters);
    }

    /* ---------------- (b) users bulk suspend: skip + apply ---------------- */

    public function test_users_bulk_suspend_skips_ineligible_and_applies_to_eligible()
    {
        $admin = $this->admin();
        $a = $this->makeTeacher('BulkA');
        $b = $this->makeTeacher('BulkB');

        $this->actingAs($admin)
            ->from(route('users.index'))
            ->post(route('users.bulk_status'), [
                // Two eligible probes + the Super Admin (outside an
                // Admin's write domain) + the Admin themself (self).
                'ids' => [$a->id, $b->id, $this->superAdmin()->id, $admin->id],
                'status' => 'suspended',
            ])
            ->assertRedirect(route('users.index'));

        $flash = session('flash_success');
        $this->assertNotNull($flash);
        $this->assertStringContainsString('2 updated, 2 skipped', $flash);

        $this->assertSame('suspended', User::find($a->id)->getAttribute('status'));
        $this->assertSame('suspended', User::find($b->id)->getAttribute('status'));
        // The ineligible rows are untouched.
        $this->assertNotSame('suspended', $this->superAdmin()->fresh()->getAttribute('status') ?: 'active');
        $this->assertNotSame('suspended', $admin->fresh()->getAttribute('status') ?: 'active');

        $this->assertDatabaseHas('audit_logs', ['action' => 'user.bulk_suspended']);
    }

    public function test_users_bulk_activate_reverses_and_reports_already_active_skip()
    {
        $admin = $this->admin();
        $a = $this->makeTeacher('BulkC');
        $a->update(['status' => 'suspended']);

        $this->actingAs($admin)
            ->from(route('users.index'))
            ->post(route('users.bulk_status'), ['ids' => [$a->id], 'status' => 'active'])
            ->assertRedirect(route('users.index'));
        $this->assertStringContainsString('1 updated, 0 skipped', session('flash_success'));
        $this->assertSame('active', User::find($a->id)->getAttribute('status'));

        // Re-activating an already-active account is a reported skip.
        $this->actingAs($admin)
            ->from(route('users.index'))
            ->post(route('users.bulk_status'), ['ids' => [$a->id], 'status' => 'active'])
            ->assertRedirect(route('users.index'));
        $this->assertStringContainsString('0 updated, 1 skipped', session('flash_danger'));
        $this->assertStringContainsString('already active', session('flash_danger'));
    }

    public function test_users_bulk_endpoints_reject_an_empty_selection()
    {
        $this->actingAs($this->admin())
            ->from(route('users.index'))
            ->post(route('users.bulk_status'), ['ids' => [], 'status' => 'suspended'])
            ->assertRedirect(route('users.index'));
        $this->assertStringContainsString('Nothing selected', session('flash_danger'));

        $this->actingAs($this->admin())
            ->from(route('users.index'))
            ->post(route('users.export_selected'), ['ids' => []])
            ->assertRedirect(route('users.index'));
        $this->assertStringContainsString('Nothing selected', session('flash_danger'));
    }

    /* ---------------- (c) users export-selected honours scope ---------------- */

    public function test_users_export_selected_returns_only_requested_in_scope_rows()
    {
        $a = $this->makeTeacher('BulkExpA');
        $b = $this->makeTeacher('BulkExpB');

        // A is requested; B is an in-scope row that was NOT ticked;
        // the Super Admin id is tampered in — an Admin's scoped
        // list never contains it, so it must not leak into the CSV.
        $response = $this->actingAs($this->admin())
            ->post(route('users.export_selected'), [
                'ids' => [$a->id, $this->superAdmin()->id],
            ])
            ->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString($a->email, $csv);
        $this->assertStringNotContainsString($b->email, $csv);
        $this->assertStringNotContainsString($this->superAdmin()->email, $csv);
    }

    /* ---------------- (d) role gate ---------------- */

    public function test_teacher_is_denied_the_users_bulk_endpoints()
    {
        // teamSA middleware (the single-row suspend/activate gate)
        // bounces anyone outside the team to the login route.
        $this->actingAs($this->teacher())
            ->post(route('users.bulk_status'), ['ids' => [1], 'status' => 'suspended'])
            ->assertRedirect(route('login'));
        $this->actingAs($this->teacher())
            ->post(route('users.export_selected'), ['ids' => [1]])
            ->assertRedirect(route('login'));
    }

    /* ---------------- (e) books export-selected ---------------- */

    public function test_books_export_selected_honours_ids()
    {
        $x = Book::create([
            'name' => 'P10Probe Chosen Title', 'author' => 'P10', 'book_type' => 'Book',
            'total_copies' => 3, 'issued_copies' => 0,
        ]);
        $y = Book::create([
            'name' => 'P10Probe Unticked Title', 'author' => 'P10', 'book_type' => 'Book',
            'total_copies' => 2, 'issued_copies' => 0,
        ]);

        $response = $this->actingAs($this->librarian())
            ->post(route('books.export_selected'), ['ids' => [$x->id]])
            ->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString($x->name, $csv);
        $this->assertStringNotContainsString($y->name, $csv);

        $this->actingAs($this->librarian())
            ->from(route('books.index'))
            ->post(route('books.export_selected'), ['ids' => []])
            ->assertRedirect(route('books.index'));
        $this->assertStringContainsString('Nothing selected', session('flash_danger'));
    }

    /* ---------------- (f) students export-selected ---------------- */

    public function test_students_export_selected_contains_only_the_chosen_students()
    {
        $byClass = StudentRecord::all()->groupBy('my_class_id')
            ->filter(function ($group) {
                return $group->count() >= 2;
            });
        $this->assertTrue($byClass->isNotEmpty(), 'seed must hold a class with at least two students');
        $group = $byClass->first();
        $chosen = $group->first();
        $other = $group->skip(1)->first();

        $response = $this->actingAs($this->admin())
            ->post(route('students.export_selected'), ['student_ids' => [$chosen->id]])
            ->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString($chosen->adm_no, $csv);
        $this->assertStringNotContainsString($other->adm_no, $csv);
    }

    /* ---------------- (g) defaulter selection narrows remind + export ---------------- */

    protected function makeDefaulter(string $tag, int $amount): User
    {
        $student = User::create([
            'name' => 'P10 ' . ucfirst($tag),
            'email' => 'p10_' . $tag . '@example.test',
            'username' => 'p10_' . $tag,
            'password' => Hash::make('p10-pass'),
            'user_type' => 'student',
            'code' => 'P10-' . strtoupper($tag),
        ]);
        StudentRecord::create([
            'user_id' => $student->id,
            'my_class_id' => 1,
            'section_id' => 1,
            'my_parent_id' => null,
            'adm_no' => 'P10/' . strtoupper($tag),
            'session' => Qs::getCurrentSession(),
            'year_admitted' => '2026',
        ]);
        $payment = Payment::create([
            'title' => 'P10 Fee ' . $tag,
            'amount' => $amount,
            'my_class_id' => 1,
            'description' => 'P10 fixture',
            'year' => Qs::getCurrentSession(),
            'ref_no' => 'P10-' . strtoupper($tag) . '-' . Str::random(6),
            'method' => 'Cash',
        ]);
        PaymentRecord::create([
            'payment_id' => $payment->id,
            'student_id' => $student->id,
            'amt_paid' => 0,
            'balance' => $amount,
            'paid' => 0,
            'year' => Qs::getCurrentSession(),
            'ref_no' => 'P10R-' . strtoupper($tag) . '-' . Str::random(6),
        ]);

        return $student;
    }

    public function test_defaulter_selection_narrows_export_and_remind()
    {
        $a = $this->makeDefaulter('selalpha', 5000);
        $b = $this->makeDefaulter('selbeta', 6000);

        // Export narrowed to A only (checked ids ∩ filtered set).
        $response = $this->actingAs($this->admin())
            ->get(route('payments.defaulters.export', ['ids' => [$a->id]]))
            ->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('P10/SELALPHA', $csv);
        $this->assertStringNotContainsString('P10/SELBETA', $csv);

        // Remind narrowed to A only: A is belled, B is not.
        $this->actingAs($this->admin())
            ->post(route('payments.defaulters.remind'), ['ids' => [$a->id]])
            ->assertRedirect()
            ->assertSessionHas('flash_success');
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $a->id,
            'type' => 'fee_reminder',
        ]);
        $this->assertSame(0, UserNotification::where('user_id', $b->id)
            ->where('type', 'fee_reminder')->count());
    }
}
