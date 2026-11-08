<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Support\AuditLabels;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Audit Log portability + poison-row pins (2026-11-07 night fix).
 *
 * The live 500: AuditLogController cloned its latest()-ordered
 * scoped query and added select()->distinct()->pluck(), emitting
 * SELECT DISTINCT action … ORDER BY created_at — MySQL/TiDB
 * error 3065 (ORDER BY column outside the SELECT list is
 * incompatible with DISTINCT). SQLite accepted it, so the suite
 * stayed green while TiDB 500'd. These pins hold (a) the SQL
 * shape — no DISTINCT query may carry an ORDER BY — and (b) the
 * renderer: rows with unknown actions, ghost/non-model subjects,
 * deleted users or null timestamps must never 500 the page.
 */
class OwnerAuditLogPortabilityTest extends TestCase
{
    use DatabaseTransactions;

    private function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    private function superAdmin()
    {
        return User::where('email', 'superadmin@hackstra.app')->firstOrFail();
    }

    private function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    private function poisonRows(): void
    {
        $t = $this->teacher()->id;
        AuditLog::create(['user_id' => $t, 'action' => 'martian.landed',
            'description' => 'POISON unknown action', 'ip_address' => '10.9.9.1']);
        AuditLog::create(['user_id' => $t, 'action' => 'ghost.did',
            'subject_type' => 'App\\Nope\\Ghost', 'subject_id' => 999,
            'description' => 'POISON missing subject class', 'ip_address' => '10.9.9.2']);
        AuditLog::create(['user_id' => $t, 'action' => 'nonmodel.did',
            'subject_type' => 'App\\Support\\Audit', 'subject_id' => 5,
            'description' => 'POISON non-model subject class', 'ip_address' => '10.9.9.3']);
        AuditLog::create(['user_id' => 999999, 'action' => 'deleted.user_acted',
            'description' => 'POISON deleted user row', 'ip_address' => '10.9.9.4']);
        DB::table('audit_logs')->insert([
            'user_id' => null, 'action' => 'timestampless.row', 'subject_type' => null,
            'subject_id' => null, 'description' => 'POISON null created_at',
            'ip_address' => '10.9.9.5', 'created_at' => null, 'updated_at' => null,
        ]);
    }

    public function test_no_distinct_query_carries_an_order_by()
    {
        $this->poisonRows();
        $sql = [];
        DB::listen(function ($e) use (&$sql) {
            $sql[] = strtolower($e->sql);
        });
        $this->actingAs($this->admin())->get(route('audit_logs.index'))->assertOk();
        $this->actingAs($this->admin())->get(route('audit_logs.index', ['group' => 'other']))->assertOk();
        $this->actingAs($this->superAdmin())->get(route('audit_logs.index'))->assertOk();

        $bad = array_filter($sql, function ($s) {
            return strpos($s, 'distinct') !== false && strpos($s, 'order by') !== false;
        });
        $this->assertSame([], array_values($bad),
            'A DISTINCT query carried an ORDER BY (TiDB error 3065 shape): ' . implode(' || ', $bad));
    }

    public function test_index_renders_poison_rows_for_sa_and_admin()
    {
        $this->poisonRows();

        // Unfiltered: the page must simply not 500 for either role.
        $this->actingAs($this->superAdmin())->get(route('audit_logs.index'))->assertOk();
        $this->actingAs($this->admin())->get(route('audit_logs.index'))->assertOk();

        // Content assertions run through the page's own search
        // filter (descriptions all start "POISON") so they do not
        // depend on how many rows the shared seed holds or on
        // pagination position — the null-timestamp row sorts last
        // under latest() and can fall off page 1 in a busy DB.
        $sa = $this->actingAs($this->superAdmin())->get(route('audit_logs.index', ['q' => 'POISON']))->assertOk()->getContent();
        $this->assertStringContainsString('Martian landed', $sa);
        $this->assertStringContainsString('Ghost #999', $sa);
        $this->assertStringContainsString('Audit #5', $sa);
        $this->assertStringContainsString('POISON null created_at', $sa);
        // The deleted-user row shows as System for the SA.
        $this->assertStringContainsString('POISON deleted user row', $sa);

        $admin = $this->actingAs($this->admin())->get(route('audit_logs.index', ['q' => 'POISON']))->assertOk()->getContent();
        $this->assertStringContainsString('Martian landed', $admin);
        $this->assertStringContainsString('Ghost #999', $admin);
    }

    public function test_export_renders_poison_rows()
    {
        $this->poisonRows();
        $csv = $this->actingAs($this->superAdmin())->get(route('audit_logs.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('Martian landed', $csv);
        $this->assertStringContainsString('Ghost #999', $csv);
    }

    public function test_labels_are_null_and_unknown_safe()
    {
        $this->assertSame('—', AuditLabels::label(null));
        $this->assertSame('—', AuditLabels::label(''));
        $this->assertSame('other', AuditLabels::groupOf(null));
        $this->assertSame('login', AuditLabels::tone(null));
        $this->assertSame('Martian landed', AuditLabels::label('martian.landed'));
        $this->assertSame('other', AuditLabels::groupOf('martian.landed'));
    }
}
