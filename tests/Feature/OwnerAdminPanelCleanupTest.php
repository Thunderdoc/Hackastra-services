<?php

namespace Tests\Feature;

use App\Support\Reports\ReportPresets;
use App\Support\Reports\ReportSources;
use App\User;
use Tests\TestCase;

/**
 * Admin panel cleanup (owner, 2026-10-29 evening): "library
 * waha se pura hatao" + "jo bhi extra hai admin ke side usko
 * bhi hatao". The library leaves the Admin's world completely
 * (builder sources + presets included — the Librarian keeps
 * everything, the Principal keeps the admin-mapped builder
 * set), and the Dormitories link leaves the admin sidebar
 * (the hostel is the Warden's; the routes stay).
 */
class OwnerAdminPanelCleanupTest extends TestCase
{
    protected function userByEmail(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    public function test_admin_builder_has_no_library_sources_or_presets()
    {
        $sources = ReportSources::allowedFor('admin');
        foreach (['books', 'issues', 'fines', 'library_summary'] as $lib) {
            $this->assertNotContains($lib, $sources, 'admin must not see the ' . $lib . ' source');
        }
        $this->assertContains('students', $sources);
        $this->assertContains('fees', $sources);

        foreach (ReportPresets::forRole('admin') as $preset) {
            $this->assertFalse(
                ReportSources::isLibrarySource($preset['source']),
                'admin preset ' . $preset['key'] . ' uses a library source'
            );
        }

        // The Librarian keeps the whole library; the Principal
        // keeps the full admin-mapped set.
        foreach (['books', 'issues', 'fines', 'library_summary'] as $lib) {
            $this->assertContains($lib, ReportSources::allowedFor('librarian'));
            $this->assertContains($lib, ReportSources::allowedFor('principal'));
        }
    }

    public function test_admin_cannot_generate_a_library_report()
    {
        $this->actingAs($this->userByEmail('admin@hackstra.app'))
            ->post(route('reports.pdf'), ['config' => [
                'title' => 'Books', 'source' => 'books', 'filters' => [],
                'columns' => [['key' => 'name', 'label' => 'Book Title', 'type' => 'text', 'width' => 28]],
            ]])->assertForbidden();
    }

    public function test_admin_sidebar_has_no_dormitories_or_library()
    {
        $page = $this->actingAs($this->userByEmail('admin@hackstra.app'))->get(route('dashboard'));
        $page->assertOk();
        $page->assertDontSee(route('dorms.index'), false);
        $page->assertDontSee('Library Reservations', false);
    }
}
