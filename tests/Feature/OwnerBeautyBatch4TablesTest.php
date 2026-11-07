<?php

namespace Tests\Feature;

use App\Models\MyClass;
use App\Support\Audit;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Beauty Batch 4 (2026-11-07, audit D group) — the table standard
 * the owner praised on the Substitutions board (fixed layout,
 * rational column widths summing to 100%, nowrap on short fields,
 * a sensible min-width inside the .table-responsive scrollport)
 * applied to the five tables that lacked it:
 *   D1 Library Books (LOCATION wrapped mid-value, COPIES wrapped
 *      into four fragments — no colgroup at all),
 *   D2 Accountant home Recent Payments (DATE & TIME wrapped to
 *      four lines, names and the Balance Due badge wrapped),
 *   Students list, Users index, Audit Log — visually passable at
 *   1280 but with no fixed layout/colgroup in code, so any narrow
 *   viewport squeezed them by auto-layout rules.
 * The shared rules live in ONE appended qs.css block (.hk-std);
 * each table carries a colgroup in its blade. Presentation only:
 * no column, content, order, data or behaviour changes. Leave and
 * Substitutions tables are the CONTROLS and stay untouched.
 */
class OwnerBeautyBatch4TablesTest extends TestCase
{
    use DatabaseTransactions;

    private function css(): string
    {
        return file_get_contents(public_path('assets/css/qs.css'));
    }

    private function bladeFile(string $path): string
    {
        return file_get_contents(resource_path('views/' . $path));
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    /* ---------- the shared standard in qs.css ---------- */

    public function test_stylesheet_defines_the_table_standard()
    {
        $css = $this->css();

        $this->assertStringContainsString('.hk-std{table-layout:fixed;width:100%}', $css);
        $this->assertStringContainsString('.hk-std .hk-nw{white-space:nowrap}', $css);
        $this->assertStringContainsString('.hk-std .hk-ellip{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}', $css);
        // Screen-only min-widths (paper keeps the proportional layout).
        $this->assertStringContainsString('.hk-std-books{min-width:920px}', $css);
        $this->assertStringContainsString('.hk-std-users{min-width:920px}', $css);
        $this->assertStringContainsString('.hk-std-students{min-width:940px}', $css);
        $this->assertStringContainsString('.table-responsive>.hk-std-acct{min-width:960px}', $css);
        $this->assertStringContainsString('.hk-std-audit{min-width:720px}', $css);
    }

    /* ---------- D1: Library Books ---------- */

    public function test_library_books_table_follows_the_standard()
    {
        $html = $this->actingAs($this->user('librarian@hackstra.app'))
            ->get(route('books.index'))->assertOk()->getContent();

        $this->assertStringContainsString('table hk-std hk-std-books', $html);
        $this->assertStringContainsString(
            '<colgroup><col style="width:24%"><col style="width:8%"><col style="width:8%"><col style="width:11%"><col style="width:13%"><col style="width:12%"><col style="width:24%"></colgroup>',
            $html
        );
        // Location + copies cells are nowrap (seed has books, so rows render).
        $this->assertStringContainsString('hk-nw', $html);
    }

    /* ---------- D2: Accountant home — Recent Payments ---------- */

    public function test_accountant_recent_payments_table_follows_the_standard()
    {
        $html = $this->actingAs($this->user('accountant@hackstra.app'))
            ->get('/home')->assertOk()->getContent();

        $this->assertStringContainsString('hk-std hk-std-acct', $html);
        $this->assertStringContainsString(
            '<colgroup><col style="width:5%"><col style="width:17%"><col style="width:17%"><col style="width:13%"><col style="width:9%"><col style="width:10%"><col style="width:12%"><col style="width:12%"><col style="width:5%"></colgroup>',
            $html
        );
    }

    /* ---------- Students list ---------- */

    public function test_students_list_table_follows_the_standard()
    {
        $classId = MyClass::query()->orderBy('id')->firstOrFail()->id;

        $html = $this->actingAs($this->user('admin@hackstra.app'))
            ->get(route('students.list', $classId))->assertOk()->getContent();

        $this->assertStringContainsString('table hk-std hk-std-students', $html);
        // Admin writes, so the 9-column (checkbox) colgroup variant renders.
        $this->assertStringContainsString(
            '<colgroup><col style="width:4%"><col style="width:5%"><col style="width:7%"><col style="width:22%"><col style="width:13%"><col style="width:9%"><col style="width:13%"><col style="width:19%"><col style="width:8%"></colgroup>',
            $html
        );
        // The print contract is preserved alongside the screen standard.
        $this->assertStringContainsString('print-nowrap hk-nw', $html);
    }

    /* ---------- Users index ---------- */

    public function test_users_table_follows_the_standard()
    {
        $html = $this->actingAs($this->user('admin@hackstra.app'))
            ->get(route('users.index'))->assertOk()->getContent();

        $this->assertStringContainsString('table hk-std hk-std-users', $html);
        $this->assertStringContainsString(
            '<colgroup><col style="width:5%"><col style="width:7%"><col style="width:19%"><col style="width:14%"><col style="width:13%"><col style="width:24%"><col style="width:12%"><col style="width:6%"></colgroup>',
            $html
        );
    }

    /* ---------- Audit Log ---------- */

    public function test_audit_log_table_follows_the_standard()
    {
        $sa = $this->user('superadmin@hackstra.app');
        $this->actingAs($sa);
        Audit::log('user.login', null, 'Batch 4 pin entry');

        $html = $this->get(route('audit_logs.index'))->assertOk()->getContent();

        $this->assertStringContainsString('au-table hk-std hk-std-audit', $html);
        $this->assertStringContainsString(
            '<colgroup><col style="width:18%"><col style="width:23%"><col style="width:17%"><col style="width:29%"><col style="width:13%"></colgroup>',
            $html
        );
    }

    /* ---------- Controls stay untouched ---------- */

    public function test_substitutions_control_table_keeps_its_own_standard()
    {
        $blade = $this->bladeFile('pages/support_team/substitutions/index.blade.php');

        // The praised control was NOT swept into .hk-std; its own
        // page-local fixed-layout rules and colgroups stand as-is.
        $this->assertStringContainsString('.sub-table { table-layout: fixed;', $blade);
        $this->assertStringNotContainsString('hk-std', $blade);
    }
}
