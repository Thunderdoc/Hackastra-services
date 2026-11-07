<?php

namespace Tests\Feature;

use App\Models\Dorm;
use App\Models\HealthRecord;
use App\Models\HostelRoom;
use App\Models\InventoryItem;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\Models\TransportAssignment;
use App\Models\TransportRoute;
use App\Models\TransportVehicle;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Beauty Wave 3 + G6 allocation clarity (2026-11-06, 50/50 phase):
 * (1) the stray #2e6be6 blue is restated onto the canonical pair
 *     (#3478f6 fills / #2867dc border accents) in an appended
 *     qs.css block with the winning selectors' own shapes;
 * (2) the plain cluster — Hostel dorms, Transport, Inventory,
 *     Health Records — opens with the slim module banner, its
 *     figures always the page's own data;
 * (3) the Settings anchor nav becomes a sticky rail on desktop
 *     and every section header gains a pastel icon tile — no
 *     field, name or save behaviour changes;
 * (4) G6: the generate picker's "— no teacher" flag judges the
 *     EFFECTIVE teacher (class teacher OR a section override),
 *     and the Allocation page names each subject's override
 *     count so a class-teacher move never reads as moving the
 *     overridden sections;
 * (5) pat-empty gains an opt-in illustration plate, rolled onto
 *     exactly three empty states (timetables list, leave queue,
 *     library search) — every other caller is byte-identical.
 * Presentation + additive view data only; POST contracts,
 * menus and routes are untouched.
 */
class OwnerBeautyWave3Test extends TestCase
{
    use DatabaseTransactions;

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function admin(): User
    {
        return $this->user('admin@hackstra.app');
    }

    private function warden(): User
    {
        // Same pattern as OwnerBeautyWave2Test: a plain users row
        // with user_type 'hostel' (no user_types seed row exists).
        if (!User::where('email', 'warden@hackstra.app')->exists()) {
            DB::table('users')->insert([
                'name' => 'Warden Test',
                'email' => 'warden@hackstra.app',
                'code' => 'WARDEN1',
                'username' => 'warden',
                'password' => bcrypt('hackstra'),
                'user_type' => 'hostel',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $this->user('warden@hackstra.app');
    }

    /** The banner segment of a page (banner partial .. next card). */
    private function bannerSegment(string $html): string
    {
        $pos = strpos($html, 'hk-mod-banner');
        $this->assertNotFalse($pos, 'module banner missing from page');

        return substr($html, $pos, 1600);
    }

    /* ---------- (1) blue-token reconciliation ---------- */

    public function test_stray_blue_is_restated_onto_the_canonical_pair()
    {
        $css = file_get_contents(public_path('assets/css/qs.css'));
        $pos = strpos($css, 'Blue-token reconciliation');
        $this->assertNotFalse($pos, 'wave-3 block missing from qs.css');
        $block = substr($css, $pos);

        // Solid fills land on #3478f6, border accents on #2867dc,
        // each under the winning rule's own selector shape.
        $this->assertStringContainsString('.ttc-grid.tt3-teacher .ttc-chip{background:#3478f6;border-color:#3478f6}', $block);
        $this->assertStringContainsString('.gen-step i{background:#3478f6}', $block);
        $this->assertStringContainsString('.gen-genbtn{background:#3478f6}', $block);
        $this->assertStringContainsString('.gen-radio.on{border-color:#2867dc}', $block);
        $this->assertStringContainsString('.sd-monthchip:hover{border-color:#2867dc}', $block);
        $this->assertStringContainsString('.set-nav a:hover{border-color:#2867dc}', $block);
        // No declaration in the restated block uses the stray
        // blue (the block's own comment names it — declarations
        // are what count, matched as ":#2e6be6").
        $this->assertStringNotContainsString(':#2e6be6', $block);
    }

    /* ---------- (2) stat-tile headbars for the plain cluster ---------- */

    public function test_hostel_dorms_index_carries_the_banner_with_ledger_counts()
    {
        $html = $this->actingAs($this->warden())
            ->get(route('hostel.dorms.index'))->assertOk()->getContent();

        $seg = $this->bannerSegment($html);
        // Beauty Batch 3 re-expression: the banner no longer
        // repeats the page title (the header owns it — audit B1),
        // so the segment is identified by its context line instead
        // of the removed 'Hostel Dorms' h2. The ledger counts below
        // are the test's real subject and are unchanged.
        $this->assertStringContainsString('Dorms, rooms and the students allocated to them.', $seg);
        $this->assertStringContainsString('<b>' . number_format(Dorm::count()) . '</b>', $seg);
        $this->assertStringContainsString('<b>' . number_format(HostelRoom::count()) . '</b>', $seg);
        $this->assertStringContainsString(
            '<b>' . number_format(StudentRecord::whereNotNull('dorm_id')->count()) . '</b>', $seg);
    }

    public function test_transport_index_carries_the_banner_with_list_counts()
    {
        $html = $this->actingAs($this->admin())
            ->get(route('transport.index'))->assertOk()->getContent();

        $seg = $this->bannerSegment($html);
        $this->assertStringContainsString('Bus routes, vehicles and student assignments.', $seg);
        $this->assertStringContainsString('<b>' . number_format(TransportRoute::count()) . '</b>', $seg);
        $this->assertStringContainsString('<b>' . number_format(TransportVehicle::count()) . '</b>', $seg);
        $this->assertStringContainsString('<b>' . number_format(TransportAssignment::count()) . '</b>', $seg);
        // One home for the subtitle sentence — the toolbar no
        // longer repeats it.
        $this->assertSame(1, substr_count($html, 'Bus routes, vehicles and student assignments.'));
    }

    public function test_inventory_index_carries_the_banner_with_stock_figures()
    {
        $lowStock = InventoryItem::all()->filter(function (InventoryItem $item) {
            return $item->currentStock() <= (int) $item->low_stock_threshold;
        })->count();

        $html = $this->actingAs($this->admin())
            ->get(route('inventory.index'))->assertOk()->getContent();

        $seg = $this->bannerSegment($html);
        // Beauty Batch 3 re-expression: the banner no longer
        // repeats the page title (the header owns it — audit B1),
        // so the segment is identified by its context line instead
        // of the removed 'Inventory' h2. The stock figures below
        // are the test's real subject and are unchanged.
        $this->assertStringContainsString('Store items, stock levels', $seg);
        $this->assertStringContainsString('<b>' . number_format(InventoryItem::count()) . '</b>', $seg);
        $this->assertStringContainsString('<b>' . number_format($lowStock) . '</b>', $seg);
        $this->assertStringContainsString('Low Stock', $seg);
    }

    public function test_health_index_banner_counts_only_the_viewers_scope()
    {
        $student = User::where('user_type', 'student')->firstOrFail();
        $nurse = $this->user('nurse@hackstra.app');
        foreach ([false, true] as $referred) {
            HealthRecord::create([
                'student_id' => $student->id,
                'visit_date' => '2026-10-29',
                'complaint' => 'Probe complaint',
                'treatment' => 'Probe treatment',
                'is_referred' => $referred,
                'referred_to' => $referred ? 'City Hospital' : null,
                'recorded_by' => $nurse->id,
            ]);
        }

        $html = $this->actingAs($nurse)
            ->get(route('health_records.index'))->assertOk()->getContent();

        $seg = $this->bannerSegment($html);
        // Beauty Batch 3 re-expression: the banner no longer
        // repeats the page title (the header owns it — audit B1),
        // so the segment is identified by its context line instead
        // of the removed 'Health Records' h2. The scope counts
        // below are the test's real subject and are unchanged.
        $this->assertStringContainsString('Clinic visit log', $seg);
        $this->assertStringContainsString('<b>2</b>', $seg);
        $this->assertStringContainsString('Referred', $seg);
        $this->assertStringContainsString('<b>1</b>', $seg);
    }

    /* ---------- (3) settings sticky rail + section tiles ---------- */

    public function test_settings_nav_is_a_sticky_rail_and_sections_carry_icon_tiles()
    {
        $css = file_get_contents(public_path('assets/css/qs.css'));
        $this->assertStringContainsString('.set-nav{position:sticky;top:76px', $css);
        $this->assertStringContainsString('.pat-form-section-head{', $css);
        $this->assertStringContainsString('.set-sec-ic{', $css);

        $html = $this->actingAs($this->admin())
            ->get(route('settings'))->assertOk()->getContent();

        // The school sections (Platform is Super-Admin-only).
        // Was 8; Round 4 added two owner-ordered sections on this
        // page — F5's "Fee Reminders" and F1's "WhatsApp" cards —
        // so the count is now 10. The thesis (every section
        // carries the shared section-head + icon-tile markup) is
        // unchanged.
        $this->assertSame(10, substr_count($html, 'pat-form-section-head'));
        $this->assertStringContainsString('set-sec-ic set-sec-ic-blue', $html);
        $this->assertStringContainsString('icon-office', $html);
        $this->assertStringContainsString('icon-barcode2', $html);
        $this->assertStringContainsString('icon-file-text2', $html);
    }

    /* ---------- (4) G6 allocation clarity ---------- */

    private function probeSubject(string $name, string $slug): Subject
    {
        return Subject::create([
            'name' => $name,
            'my_class_id' => 1,
            'teacher_id' => null,
            'slug' => $slug,
            'periods_per_week' => 6,
        ]);
    }

    private function addOverride(int $sectionId, int $subjectId, int $teacherId): void
    {
        DB::table('section_subject_teachers')->insert([
            'section_id' => $sectionId,
            'subject_id' => $subjectId,
            'teacher_id' => $teacherId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** The <label>…</label> segment carrying the given text. */
    private function labelSegment(string $html, string $needle): string
    {
        preg_match_all('/<label class="gen-subj.*?<\/label>/s', $html, $m);
        foreach ($m[0] as $label) {
            if (strpos($label, $needle) !== false) {
                return $label;
            }
        }
        $this->fail('No picker label carries ' . $needle);
    }

    public function test_generate_picker_flags_only_subjects_with_no_effective_teacher()
    {
        $ananya = $this->user('teacher@hackstra.app');
        $withOverride = $this->probeSubject('Probe W3 Override Subject', 'probe-w3-override-subject');
        $this->addOverride(1, $withOverride->id, $ananya->id);
        $bare = $this->probeSubject('Probe W3 Bare Subject', 'probe-w3-bare-subject');

        $html = $this->actingAs($this->admin())
            ->get(route('tt.generate.form'))->assertOk()->getContent();

        // Overrides but no class teacher: NOT flagged teacher-less.
        $labelA = $this->labelSegment($html, 'Probe W3 Override Subject');
        $this->assertStringNotContainsString('— no teacher', $labelA);
        $this->assertStringNotContainsString(' warn', $labelA);

        // Neither class teacher nor any override: still flagged,
        // with the flag's original wording.
        $labelB = $this->labelSegment($html, 'Probe W3 Bare Subject');
        $this->assertStringContainsString('— no teacher', $labelB);
        $this->assertStringContainsString(' warn', $labelB);
    }

    /** The <tr>…</tr> segment carrying the given text. */
    private function rowSegment(string $html, string $needle): string
    {
        preg_match_all('/<tr>.*?<\/tr>/s', $html, $m);
        foreach ($m[0] as $row) {
            if (strpos($row, $needle) !== false) {
                return $row;
            }
        }
        $this->fail('No table row carries ' . $needle);
    }

    public function test_allocation_page_names_section_overrides_per_subject()
    {
        $ananya = $this->user('teacher@hackstra.app');
        $withOverride = $this->probeSubject('Probe W3 Alloc Subject', 'probe-w3-alloc-subject');
        $this->addOverride(1, $withOverride->id, $ananya->id);
        $this->addOverride(2, $withOverride->id, $ananya->id);
        $this->probeSubject('Probe W3 Plain Subject', 'probe-w3-plain-subject');

        $html = $this->actingAs($this->admin())
            ->get(route('planning.allocation'))->assertOk()->getContent();

        $rowA = $this->rowSegment($html, 'Probe W3 Alloc Subject');
        $this->assertStringContainsString('2 section overrides keep their own teachers', $rowA);

        $rowB = $this->rowSegment($html, 'Probe W3 Plain Subject');
        $this->assertStringNotContainsString('section override', $rowB);
    }

    /* ---------- (5) pat-empty illustration plate ---------- */

    public function test_pat_empty_art_renders_only_where_opted_in()
    {
        // Leave queue (TestCase baseline empties the queue):
        // opted in.
        $leave = $this->actingAs($this->admin())
            ->get(route('leave.index'))->assertOk()->getContent();
        $this->assertStringContainsString('pat-empty-art', $leave);
        $this->assertStringContainsString('No leave requests yet.', $leave);

        // Library search with no matches: opted in.
        $librarian = $this->user('librarian@hackstra.app');
        $search = $this->actingAs($librarian)
            ->get(route('books.index', ['q' => 'zzz-no-such-book-w3']))
            ->assertOk()->getContent();
        $this->assertStringContainsString('pat-empty-art', $search);
        $this->assertStringContainsString('No books match your search or filters.', $search);

        // The catalogue-empty state on the same page did NOT opt
        // in — unreachable while the seed holds books, so pin the
        // contract at the partial level instead: without the flag
        // the icon circle renders and no plate does.
        $plain = view('partials.patterns.empty_state', [
            'icon' => 'icon-books', 'message' => 'Probe empty.',
        ])->render();
        $this->assertStringContainsString('pat-empty-icon', $plain);
        $this->assertStringNotContainsString('pat-empty-art', $plain);

        $art = view('partials.patterns.empty_state', [
            'icon' => 'icon-books', 'message' => 'Probe empty.', 'hkEmptyArt' => true,
        ])->render();
        $this->assertStringContainsString('pat-empty-art', $art);
        $this->assertStringNotContainsString('pat-empty-icon', $art);
    }

    public function test_timetables_list_empty_state_carries_the_art()
    {
        $html = $this->actingAs($this->admin())
            ->get(route('tt.index', ['class_id' => 1, 'year' => '1999-2000']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('No timetable yet for this class.', $html);
        $this->assertStringContainsString('pat-empty-art', $html);
    }
}
