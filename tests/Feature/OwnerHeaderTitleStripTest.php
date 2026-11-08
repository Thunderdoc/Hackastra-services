<?php

namespace Tests\Feature;

use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Header Design B — Title Strip (owner pick, 2026-11-08, with his two
 * conditions). Every sidebar page renders ONE slim site-card strip
 * under the utility topbar: pastel icon circle, title, subtitle,
 * page actions, and a breadcrumb chain whose ancestor levels are
 * links (one tap jumps to that level). The single Back control goes
 * exactly ONE level up — to the last link of the page's own chain
 * (its parent page), or to the dashboard when the page has no parent
 * link — never browser history, never repeated back-pressing. Pages
 * that render their OWN Back (own_page_back) keep it and the strip
 * adds none; role homes keep their banner/hero only.
 */
class OwnerHeaderTitleStripTest extends TestCase
{
    use DatabaseTransactions;

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    /** The strip's Back anchor tag, or null. */
    private function backTag(string $html): ?string
    {
        return preg_match('/<a[^>]*hk-global-back[^>]*>/', $html, $m) ? $m[0] : null;
    }

    public function test_strip_renders_for_a_page_of_every_role()
    {
        $cases = [
            ['admin@hackstra.app', 'students.list', [1]],
            ['teacher@hackstra.app', 'assignments.index', []],
            ['student@hackstra.app', 'courses.index', []],
            ['parent@hackstra.app', 'my_children', []],
            ['principal@hackstra.app', 'exams.index', []],
            ['accountant@hackstra.app', 'payments.index', []],
            ['librarian@hackstra.app', 'books.index', []],
            ['nurse@hackstra.app', 'health_records.index', []],
            ['superadmin@hackstra.app', 'audit_logs.index', []],
        ];
        foreach ($cases as [$email, $route, $params]) {
            $html = $this->actingAs($this->user($email))->get(route($route, $params))
                ->assertOk()->getContent();
            $this->assertStringContainsString('hk-strip-wrap', $html, "$route ($email): no Title Strip");
            $this->assertStringContainsString('hk-strip-ic', $html, "$route ($email): no pastel icon circle");
            $this->assertStringContainsString('hk-strip-title', $html, "$route ($email): no strip title");
        }
    }

    public function test_deep_page_chain_levels_are_links_and_back_is_parent()
    {
        $html = $this->actingAs($this->user('admin@hackstra.app'))
            ->get(route('assignments.show', 1))->assertOk()->getContent();

        // The chain renders inside the strip nav, ancestors as links.
        $this->assertMatchesRegularExpression(
            '/<nav class="hk-strip-crumbs"[^>]*>.*?<a href="' . preg_quote(route('dashboard'), '/') . '">Dashboard<\/a>.*?<a href="' . preg_quote(route('assignments.index'), '/') . '">Assignments<\/a>/s',
            $html,
            'Breadcrumb ancestors must be links with the real hrefs'
        );
        $this->assertStringContainsString('hk-crumb-sep', $html, 'Chain separators must be the strip chevrons');

        // Exactly ONE Back control, aimed one level up at the parent.
        $this->assertSame(1, substr_count($html, 'hk-global-back'), 'Exactly one Back control');
        $tag = $this->backTag($html);
        $this->assertNotNull($tag);
        $this->assertStringContainsString('href="' . route('assignments.index') . '"', $tag, 'Back must target the parent page');
        $this->assertStringContainsString('aria-label="Back to Assignments"', $tag);
        $this->assertStringNotContainsString('history.back', $tag);
    }

    public function test_index_page_back_falls_back_to_dashboard()
    {
        $html = $this->actingAs($this->user('admin@hackstra.app'))
            ->get(route('assignments.index'))->assertOk()->getContent();
        $tag = $this->backTag($html);
        $this->assertNotNull($tag, 'Index pages keep a Back control');
        $this->assertStringContainsString('href="' . route('dashboard') . '"', $tag, 'A top-level page goes one level up: the dashboard');
    }

    public function test_own_page_back_pages_carry_no_global_back()
    {
        $html = $this->actingAs($this->user('admin@hackstra.app'))
            ->get(route('circulars.create'))->assertOk()->getContent();
        $this->assertStringContainsString('hk-strip-wrap', $html, 'Strip still renders');
        $this->assertSame(0, substr_count($html, 'hk-global-back'), 'The strip must not add a second Back');
        $this->assertStringContainsString('Back to Circulars', $html, 'The page keeps its own Back');

        $html = $this->actingAs($this->user('student@hackstra.app'))
            ->get(route('courses.show', 1))->assertOk()->getContent();
        $this->assertStringContainsString('hk-strip-wrap', $html, 'Strip still renders on the course page');
        $this->assertSame(0, substr_count($html, 'hk-global-back'), 'Course page keeps only its own Back');
    }

    public function test_role_homes_keep_banner_only_no_strip()
    {
        foreach (['principal@hackstra.app', 'admin@hackstra.app', 'superadmin@hackstra.app'] as $email) {
            $html = $this->actingAs($this->user($email))->get(route('dashboard'))->assertOk()->getContent();
            $this->assertStringNotContainsString('hk-strip-wrap', $html, "$email home must not stack a strip above its hero");
            $this->assertSame(0, substr_count($html, 'hk-global-back'), "$email home has no Back");
        }
    }

    public function test_page_icon_override_and_subtitle_still_render()
    {
        // payments.defaulters declares its own icon tile (page_icon).
        $html = $this->actingAs($this->user('admin@hackstra.app'))
            ->get(route('payments.defaulters'))->assertOk()->getContent();
        $this->assertStringContainsString('hk-strip-wrap', $html);
        $this->assertStringContainsString('l118-page-icon', $html, 'A page_icon override renders inside the strip');

        // exams.publication declares a subtitle.
        $html = $this->actingAs($this->user('principal@hackstra.app'))
            ->get(route('exams.publication', 1))->assertOk()->getContent();
        $this->assertMatchesRegularExpression(
            '/<div class="hk-strip-sub">Publish results only when every teacher has submitted their marks\.<\/div>/',
            $html,
            'The declared subtitle renders in the strip'
        );
    }

    public function test_sa_platform_page_back_stays_on_dashboard()
    {
        // School settings 302 the SA away (PlatformOnly); on the SA's
        // own platform pages the parent level IS the Platform
        // Overview, served by the dashboard route.
        $html = $this->actingAs($this->user('superadmin@hackstra.app'))
            ->get(route('audit_logs.index'))->assertOk()->getContent();
        $this->assertStringContainsString('hk-strip-wrap', $html);
        $tag = $this->backTag($html);
        $this->assertNotNull($tag);
        $this->assertStringContainsString('href="' . route('dashboard') . '"', $tag);
    }

    public function test_strip_css_contract_phone_collapse_and_print()
    {
        $css = file_get_contents(public_path('assets/css/qs.css'));
        $this->assertStringContainsString('.hk-strip{display:flex', $css, 'Strip is one slim flex card');
        $this->assertStringContainsString('.hk-strip-crumbs a:not(:last-of-type)', $css, 'Phone: chain collapses to the parent link');
        $this->assertMatchesRegularExpression('/@media print\{\s*\.hk-strip-wrap\{display:none !important\}/', $css, 'Strip never prints');
        $this->assertStringContainsString('html.hk-dark .hk-strip{', $css, 'Dark variant exists');

        $partial = file_get_contents(resource_path('views/partials/header.blade.php'));
        $this->assertStringNotContainsString('history.back', $partial, 'Back is a real link, never browser history');
        $this->assertStringContainsString('own_page_back', $partial, 'The own-Back opt-out doctrine survives');
    }
}
