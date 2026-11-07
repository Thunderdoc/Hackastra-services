<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\AssessmentPeriod;
use App\Models\Exam;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Exam Patterns Phase 1 (Round 3 R1) — T1–T11: migration
 * backfill + unique swap, pattern configuration (presets,
 * invariants, locks, gates, copy), and exam creation re-keyed
 * to periods. Seeded fixture: exam 1 (Term 1, global), session
 * 2026-2027, 17,366 marks — the parity baseline.
 */
class OwnerExamPatternTest extends TestCase
{
    use DatabaseTransactions;

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function sa(): User
    {
        return User::where('email', 'superadmin@hackstra.app')->firstOrFail();
    }

    protected function principal(): User
    {
        return User::where('email', 'principal@hackstra.app')->firstOrFail();
    }

    protected function teacher(): User
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function year(): string
    {
        return Qs::getSetting('current_session');
    }

    protected function switchSession(string $year): void
    {
        DB::table('settings')->where('type', 'current_session')->update(['description' => $year]);
        $this->assertSame($year, Qs::getSetting('current_session'));
    }

    protected function term1Period(): AssessmentPeriod
    {
        return AssessmentPeriod::where('year', $this->year())->where('legacy_term', 1)->firstOrFail();
    }

    protected function mkPeriod(array $attrs): AssessmentPeriod
    {
        return AssessmentPeriod::create(array_merge([
            'year' => $this->year(), 'name' => 'Probe Period', 'kind' => 'term',
            'feeds_period_id' => null, 'counts_toward_annual' => true,
            'sort_order' => 900, 'legacy_term' => null, 'is_active' => true,
        ], $attrs));
    }

    /* ---------------- T1: backfill ---------------- */

    public function test_t1_migration_backfill_links_exams_and_leaves_marks_untouched()
    {
        $marksCount = DB::table('marks')->count();
        $texSum = DB::table('marks')->sum('tex1');
        $recordsCount = DB::table('exam_records')->count();

        // Exams in a fresh year, inserted seeder-style (no period).
        foreach ([2, 3] as $term) {
            DB::table('exams')->insert([
                'name' => 'T1 Probe Term ' . $term, 'term' => $term, 'year' => '2098-2099',
                'my_class_id' => 0, 'period_id' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('exams')->insert([
            'name' => 'T1 Probe Class Term 2', 'term' => 2, 'year' => '2098-2099',
            'my_class_id' => 13, 'period_id' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        if (!class_exists('CreateAssessmentPeriodsAndLinkExams', false)) {
            require database_path('migrations/2026_11_07_000023_create_assessment_periods_and_link_exams.php');
        }
        (new \CreateAssessmentPeriodsAndLinkExams())->up();

        $periods = AssessmentPeriod::where('year', '2098-2099')->orderBy('legacy_term')->get();
        $this->assertCount(2, $periods, 'one period per distinct (year, term)');
        foreach ($periods as $p) {
            $this->assertSame('term', $p->kind);
            $this->assertTrue($p->counts_toward_annual);
            $this->assertSame($p->legacy_term * 10, $p->sort_order);
            $this->assertSame('Term ' . $p->legacy_term, $p->name);
        }
        $this->assertSame(0, Exam::where('year', '2098-2099')->whereNull('period_id')->count(), 'every exam linked');
        $exam1 = Exam::findOrFail(1);
        $this->assertNotNull($exam1->period_id);
        $this->assertSame(1, $exam1->period->legacy_term);

        $this->assertSame($marksCount, DB::table('marks')->count());
        $this->assertSame($texSum, DB::table('marks')->sum('tex1'));
        $this->assertSame($recordsCount, DB::table('exam_records')->count());
    }

    /* ---------------- T2: fresh-install empty state ---------------- */

    public function test_t2_empty_session_shows_pattern_empty_state()
    {
        $this->switchSession('2099-2100');
        $this->assertSame(0, AssessmentPeriod::where('year', '2099-2100')->count());
        $this->actingAs($this->admin())->get(route('exams.periods.index'))
            ->assertOk()->assertSee('No periods yet');
    }

    /* ---------------- T3: unlinked fallback + fixup ---------------- */

    public function test_t3_unlinked_exam_falls_back_and_link_action_links_it()
    {
        $id = DB::table('exams')->insertGetId([
            'name' => 'T3 Unlinked Exam', 'term' => 1, 'year' => $this->year(),
            'my_class_id' => 13, 'period_id' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $exam = Exam::findOrFail($id);
        $this->assertSame('Term 1', $exam->periodLabel(), 'fallback equals today\'s exact string');

        $this->actingAs($this->admin())->get(route('exams.index'))
            ->assertOk()->assertSee('T3 Unlinked Exam')->assertSee('Term 1');

        $this->actingAs($this->admin())->post(route('exams.periods.link_unlinked'))
            ->assertRedirect();
        $this->assertSame($this->term1Period()->id, (int) $exam->fresh()->period_id);
    }

    /* ---------------- T4: unique swap + guarded down ---------------- */

    public function test_t4_unique_is_period_scoped_and_down_refuses_with_unit_exam()
    {
        $p2 = $this->mkPeriod(['name' => 'T4 Term 2', 'legacy_term' => 2, 'sort_order' => 20]);
        $t1 = $this->term1Period();

        DB::table('exams')->insert([
            'name' => 'T4 A', 'term' => 1, 'year' => '2097-2098', 'my_class_id' => 0,
            'period_id' => $t1->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        // Same (term, year, scope) under a DIFFERENT period inserts fine.
        DB::table('exams')->insert([
            'name' => 'T4 B', 'term' => 1, 'year' => '2097-2098', 'my_class_id' => 0,
            'period_id' => $p2->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame(2, Exam::where('year', '2097-2098')->count());

        $this->expectException(\Illuminate\Database\QueryException::class);
        try {
            DB::table('exams')->insert([
                'name' => 'T4 Dup', 'term' => 1, 'year' => '2097-2098', 'my_class_id' => 0,
                'period_id' => $t1->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        } finally {
            // Set up the down() refusal AFTER the duplicate check:
            // a unit exam exists, so rollback must refuse.
            $unit = $this->mkPeriod(['name' => 'T4 Unit', 'kind' => 'unit', 'feeds_period_id' => $t1->id,
                'counts_toward_annual' => false, 'sort_order' => 30]);
            Exam::create(['name' => 'T4 Unit Exam', 'term' => 1, 'year' => $this->year(),
                'my_class_id' => 13, 'period_id' => $unit->id]);
        }
    }

    public function test_t4_down_throws_while_a_unit_exam_exists()
    {
        $unit = $this->mkPeriod(['name' => 'T4b Unit', 'kind' => 'unit', 'feeds_period_id' => $this->term1Period()->id,
            'counts_toward_annual' => false, 'sort_order' => 30]);
        Exam::create(['name' => 'T4b Unit Exam', 'term' => 1, 'year' => $this->year(),
            'my_class_id' => 13, 'period_id' => $unit->id]);

        if (!class_exists('CreateAssessmentPeriodsAndLinkExams', false)) {
            require database_path('migrations/2026_11_07_000023_create_assessment_periods_and_link_exams.php');
        }
        $this->expectException(\RuntimeException::class);
        (new \CreateAssessmentPeriodsAndLinkExams())->down();
    }

    /* ---------------- T5: presets ---------------- */

    public function test_t5_presets()
    {
        $this->switchSession('2099-2100');
        $this->actingAs($this->admin())->post(route('exams.periods.apply_preset'), ['preset' => 'unit_terms'])
            ->assertRedirect(route('exams.periods.index'));

        $periods = AssessmentPeriod::forYear('2099-2100')->get();
        $this->assertCount(6, $periods);
        $byName = $periods->keyBy('name');
        $this->assertSame(10, $byName['Unit Test 1']->sort_order);
        $this->assertSame($byName['Term 1']->id, (int) $byName['Unit Test 1']->feeds_period_id);
        $this->assertFalse($byName['Unit Test 1']->counts_toward_annual);
        $this->assertSame(20, $byName['Term 1']->sort_order);
        $this->assertSame(1, $byName['Term 1']->legacy_term);
        $this->assertTrue($byName['Term 1']->counts_toward_annual);
        $this->assertSame(30, $byName['Unit Test 2']->sort_order);
        $this->assertSame($byName['Term 2']->id, (int) $byName['Unit Test 2']->feeds_period_id);
        $this->assertSame(50, $byName['Unit Test 3']->sort_order);
        $this->assertSame($byName['Term 3']->id, (int) $byName['Unit Test 3']->feeds_period_id);
        $this->assertSame(60, $byName['Term 3']->sort_order);

        // Applying again over an existing pattern is refused.
        $this->actingAs($this->admin())->post(route('exams.periods.apply_preset'), ['preset' => 'terms3'])
            ->assertSessionHas('flash_danger');
        $this->assertCount(6, AssessmentPeriod::forYear('2099-2100')->get());

        // Terms preset equals the backfill shape.
        $this->switchSession('2098-2099');
        $this->actingAs($this->admin())->post(route('exams.periods.apply_preset'), ['preset' => 'terms3']);
        $terms = AssessmentPeriod::forYear('2098-2099')->get();
        $this->assertCount(3, $terms);
        foreach ([1, 2, 3] as $n) {
            $p = $terms->firstWhere('legacy_term', $n);
            $this->assertNotNull($p);
            $this->assertSame('Term ' . $n, $p->name);
            $this->assertSame('term', $p->kind);
            $this->assertSame($n * 10, $p->sort_order);
            $this->assertTrue($p->counts_toward_annual);
        }

        // Two Terms + Blank.
        $this->switchSession('2097-2098');
        $this->actingAs($this->admin())->post(route('exams.periods.apply_preset'), ['preset' => 'two_terms']);
        $this->assertCount(2, AssessmentPeriod::forYear('2097-2098')->get());
        $this->switchSession('2096-2097');
        $this->actingAs($this->admin())->post(route('exams.periods.apply_preset'), ['preset' => 'blank']);
        $this->assertCount(0, AssessmentPeriod::forYear('2096-2097')->get());
    }

    /* ---------------- T6: invariants ---------------- */

    public function test_t6_period_invariants()
    {
        $admin = $this->admin();
        $t1 = $this->term1Period();
        $store = function ($payload) use ($admin) {
            return $this->actingAs($admin)->post(route('exams.periods.store'), $payload);
        };

        // Unit without a feeds target.
        $store(['name' => 'T6 Unit NoFeed', 'kind' => 'unit'])
            ->assertSessionHasErrors('feeds_period_id');
        $this->assertDatabaseMissing('assessment_periods', ['name' => 'T6 Unit NoFeed']);

        // A valid unit, then a unit feeding that unit (chains refused).
        $store(['name' => 'T6 Unit A', 'kind' => 'unit', 'feeds_period_id' => $t1->id])->assertRedirect();
        $unitA = AssessmentPeriod::where('name', 'T6 Unit A')->firstOrFail();
        $store(['name' => 'T6 Unit B', 'kind' => 'unit', 'feeds_period_id' => $unitA->id])
            ->assertSessionHasErrors('feeds_period_id');
        $this->assertDatabaseMissing('assessment_periods', ['name' => 'T6 Unit B']);

        // Cross-session feeds target.
        $other = $this->mkPeriod(['name' => 'T6 Other Session Term', 'year' => '1999-2000', 'legacy_term' => 1]);
        $store(['name' => 'T6 Unit Cross', 'kind' => 'unit', 'feeds_period_id' => $other->id])
            ->assertSessionHasErrors('feeds_period_id');

        // Second ACTIVE period with legacy_term 1 in this session.
        $store(['name' => 'T6 Second T1', 'kind' => 'term', 'legacy_term' => 1])
            ->assertSessionHasErrors('legacy_term');
        $this->assertDatabaseMissing('assessment_periods', ['name' => 'T6 Second T1']);

        // Unit counts_toward_annual forced false even when posted true.
        $store(['name' => 'T6 Unit Counts', 'kind' => 'unit', 'feeds_period_id' => $t1->id,
            'counts_toward_annual' => 1])->assertRedirect();
        $this->assertFalse(AssessmentPeriod::where('name', 'T6 Unit Counts')->firstOrFail()->counts_toward_annual);
    }

    /* ---------------- T7: locks ---------------- */

    public function test_t7_locks_on_periods_with_exams()
    {
        $admin = $this->admin();
        $t1 = $this->term1Period();

        // Rename succeeds (labels only).
        $this->actingAs($admin)->put(route('exams.periods.update', $t1->id), [
            'name' => 'Term One Renamed', 'kind' => 'term', 'legacy_term' => 1, 'is_active' => 1,
        ])->assertRedirect(route('exams.periods.index'));
        $this->assertSame('Term One Renamed', $t1->fresh()->name);

        // A unit WITH exams: feeds change refused server-side.
        $unit = $this->mkPeriod(['name' => 'T7 Unit', 'kind' => 'unit', 'feeds_period_id' => $t1->id,
            'counts_toward_annual' => false, 'sort_order' => 15]);
        Exam::create(['name' => 'T7 Unit Exam', 'term' => 1, 'year' => $this->year(),
            'my_class_id' => 13, 'period_id' => $unit->id]);
        $t2 = $this->mkPeriod(['name' => 'T7 Term 2', 'legacy_term' => 2, 'sort_order' => 20]);
        $this->actingAs($admin)->put(route('exams.periods.update', $unit->id), [
            'name' => 'T7 Unit', 'kind' => 'unit', 'feeds_period_id' => $t2->id, 'is_active' => 1,
        ])->assertSessionHas('flash_danger');
        $this->assertSame($t1->id, (int) $unit->fresh()->feeds_period_id, 'feeds locked once exams exist');

        // Kind change refused too.
        $this->actingAs($admin)->put(route('exams.periods.update', $unit->id), [
            'name' => 'T7 Unit', 'kind' => 'term', 'feeds_period_id' => '', 'is_active' => 1,
        ])->assertSessionHas('flash_danger');
        $this->assertSame('unit', $unit->fresh()->kind);

        // Delete refused; deactivate works and exams still render.
        $this->actingAs($admin)->delete(route('exams.periods.destroy', $t1->id))
            ->assertSessionHas('flash_danger');
        $this->assertNotNull(AssessmentPeriod::find($t1->id));
        $this->actingAs($admin)->post(route('exams.periods.deactivate', $t1->id))->assertRedirect();
        $this->assertFalse($t1->fresh()->is_active);
        $this->actingAs($admin)->get(route('exams.index'))->assertOk()->assertSee('Term 1 Examination');

        // Reorder succeeds on a period with exams.
        $a = $this->mkPeriod(['name' => 'T7 Order A', 'sort_order' => 500]);
        $b = $this->mkPeriod(['name' => 'T7 Order B', 'sort_order' => 510]);
        $this->actingAs($admin)->post(route('exams.periods.move', $a->id), ['direction' => 'down']);
        $this->assertLessThan($a->fresh()->sort_order, $b->fresh()->sort_order, 'move down swaps the order');
    }

    /* ---------------- T8: gates ---------------- */

    public function test_t8_period_gates_mirror_exam_gates()
    {
        // SA: EXACTLY the exams routes' behaviour — the global
        // PlatformOnly boundary (SA = platform account, school
        // domain belongs to the Admin) redirects SA GETs to the
        // dashboard and 403s SA writes, for exams.index itself
        // just as for the pattern page. The controller gate shape
        // (teamOrPrincipal:SA read / teamSAWrite write) is copied
        // verbatim underneath that boundary.
        $this->actingAs($this->sa())->get(route('exams.index'))
            ->assertRedirect(route('dashboard'));
        $this->actingAs($this->sa())->get(route('exams.periods.index'))
            ->assertRedirect(route('dashboard'));
        $this->actingAs($this->sa())->post(route('exams.periods.store'), [
            'name' => 'T8 SA Probe', 'kind' => 'term',
        ])->assertForbidden();
        $this->assertDatabaseMissing('assessment_periods', ['name' => 'T8 SA Probe']);

        // Principal reads, never writes.
        $this->actingAs($this->principal())->get(route('exams.periods.index'))->assertOk();
        $this->actingAs($this->principal())->post(route('exams.periods.store'), [
            'name' => 'T8 Principal Probe', 'kind' => 'term',
        ])->assertRedirect(route('login'));
        $this->assertDatabaseMissing('assessment_periods', ['name' => 'T8 Principal Probe']);

        // Teacher gets nothing.
        $this->actingAs($this->teacher())->get(route('exams.periods.index'))
            ->assertRedirect(route('login'));
        $this->actingAs($this->teacher())->post(route('exams.periods.store'), [
            'name' => 'T8 Teacher Probe', 'kind' => 'term',
        ])->assertRedirect(route('login'));
        $this->assertDatabaseMissing('assessment_periods', ['name' => 'T8 Teacher Probe']);

        // Admin writes.
        $this->actingAs($this->admin())->post(route('exams.periods.store'), [
            'name' => 'T8 Admin Probe', 'kind' => 'standalone',
        ])->assertRedirect(route('exams.periods.index'));
        $this->assertDatabaseHas('assessment_periods', ['name' => 'T8 Admin Probe']);
    }

    /* ---------------- T9: copy to session ---------------- */

    public function test_t9_copy_to_session_remaps_feeds_and_refuses_non_empty_target()
    {
        $t1 = $this->term1Period();
        $unit = $this->mkPeriod(['name' => 'T9 Unit', 'kind' => 'unit', 'feeds_period_id' => $t1->id,
            'counts_toward_annual' => false, 'sort_order' => 15]);

        $this->actingAs($this->admin())->post(route('exams.periods.copy_to_session'), [
            'target_year' => '2099-2100',
        ])->assertRedirect(route('exams.periods.index'));

        $clones = AssessmentPeriod::where('year', '2099-2100')->get();
        $this->assertGreaterThanOrEqual(2, $clones->count());
        $clonedUnit = $clones->firstWhere('name', 'T9 Unit');
        $clonedTerm = $clones->firstWhere('legacy_term', 1);
        $this->assertNotNull($clonedUnit);
        $this->assertNotNull($clonedTerm);
        $this->assertSame($clonedTerm->id, (int) $clonedUnit->feeds_period_id, 'feeds remapped to the clone');
        $this->assertNotSame($t1->id, (int) $clonedUnit->feeds_period_id);

        $count = $clones->count();
        $this->actingAs($this->admin())->post(route('exams.periods.copy_to_session'), [
            'target_year' => '2099-2100',
        ])->assertSessionHas('flash_danger');
        $this->assertSame($count, AssessmentPeriod::where('year', '2099-2100')->count());
    }

    /* ---------------- T10: exam creation through periods ---------------- */

    public function test_t10_exam_creation_derives_term_and_period_partition()
    {
        $admin = $this->admin();
        $t2 = $this->mkPeriod(['name' => 'T10 Term 2', 'legacy_term' => 2, 'sort_order' => 20]);
        $unit2 = $this->mkPeriod(['name' => 'T10 Unit 2', 'kind' => 'unit', 'feeds_period_id' => $t2->id,
            'counts_toward_annual' => false, 'sort_order' => 15]);
        $store = function ($payload) use ($admin) {
            return $this->actingAs($admin)->post(route('exams.store'), $payload);
        };

        // Term exam for class 13 derives term = 2.
        $store(['name' => 'T10 Term Exam', 'period_id' => $t2->id, 'my_class_id' => 13])
            ->assertRedirect(route('exams.index'));
        $termExam = Exam::where('name', 'T10 Term Exam')->firstOrFail();
        $this->assertSame(2, (int) $termExam->term);
        $this->assertSame($t2->id, (int) $termExam->period_id);

        // Its unit-test exam for the SAME class coexists, term also 2.
        $store(['name' => 'T10 Unit Exam', 'period_id' => $unit2->id, 'my_class_id' => 13])
            ->assertRedirect(route('exams.index'));
        $unitExam = Exam::where('name', 'T10 Unit Exam')->firstOrFail();
        $this->assertSame(2, (int) $unitExam->term, 'unit exam reuses the parent term number');

        // A second unit exam for the same period + scope: polite refusal.
        $store(['name' => 'T10 Unit Exam Dup', 'period_id' => $unit2->id, 'my_class_id' => 13])
            ->assertSessionHas('flash_danger');
        $this->assertDatabaseMissing('exams', ['name' => 'T10 Unit Exam Dup']);
        $msg = session('flash_danger');
        $this->assertStringContainsString('period', $msg);

        // Global Term exam on a fresh period blocks a class exam
        // for that period, but NOT a class unit exam feeding it.
        $t3 = $this->mkPeriod(['name' => 'T10 Term 3', 'legacy_term' => 3, 'sort_order' => 30]);
        $unit3 = $this->mkPeriod(['name' => 'T10 Unit 3', 'kind' => 'unit', 'feeds_period_id' => $t3->id,
            'counts_toward_annual' => false, 'sort_order' => 25]);
        $store(['name' => 'T10 Global Term 3', 'period_id' => $t3->id, 'my_class_id' => 0])
            ->assertRedirect(route('exams.index'));
        $store(['name' => 'T10 Blocked Class', 'period_id' => $t3->id, 'my_class_id' => 13])
            ->assertSessionHas('flash_danger');
        $this->assertDatabaseMissing('exams', ['name' => 'T10 Blocked Class']);
        $store(['name' => 'T10 Unit 3 Exam', 'period_id' => $unit3->id, 'my_class_id' => 13])
            ->assertRedirect(route('exams.index'));
        $this->assertDatabaseHas('exams', ['name' => 'T10 Unit 3 Exam']);
    }

    /* ---------------- T11: period validation + edit lock ---------------- */

    public function test_t11_period_validation_and_edit_lock()
    {
        $admin = $this->admin();

        // Period of another session rejected.
        $other = $this->mkPeriod(['name' => 'T11 Other', 'year' => '1999-2000']);
        $this->actingAs($admin)->post(route('exams.store'), [
            'name' => 'T11 Cross Exam', 'period_id' => $other->id, 'my_class_id' => 13,
        ])->assertSessionHasErrors('period_id');
        $this->assertDatabaseMissing('exams', ['name' => 'T11 Cross Exam']);

        // Inactive period rejected.
        $inactive = $this->mkPeriod(['name' => 'T11 Inactive', 'is_active' => false]);
        $this->actingAs($admin)->post(route('exams.store'), [
            'name' => 'T11 Inactive Exam', 'period_id' => $inactive->id, 'my_class_id' => 13,
        ])->assertSessionHasErrors('period_id');
        $this->assertDatabaseMissing('exams', ['name' => 'T11 Inactive Exam']);

        // Period change allowed before marks, refused after.
        $pa = $this->mkPeriod(['name' => 'T11 Period A', 'legacy_term' => 2, 'sort_order' => 20]);
        $pb = $this->mkPeriod(['name' => 'T11 Period B', 'legacy_term' => 3, 'sort_order' => 30]);
        $this->actingAs($admin)->post(route('exams.store'), [
            'name' => 'T11 Movable Exam', 'period_id' => $pa->id, 'my_class_id' => 13,
        ])->assertRedirect(route('exams.index'));
        $exam = Exam::where('name', 'T11 Movable Exam')->firstOrFail();

        $this->actingAs($admin)->put(route('exams.update', $exam->id), [
            'name' => 'T11 Movable Exam', 'period_id' => $pb->id,
        ])->assertRedirect(route('exams.index'));
        $this->assertSame($pb->id, (int) $exam->fresh()->period_id);
        $this->assertSame(3, (int) $exam->fresh()->term);

        // Give the exam a mark row — the period now locks.
        $sr = \App\Models\StudentRecord::where('section_id', 76)->firstOrFail();
        \App\Models\Mark::create([
            'student_id' => $sr->user_id, 'subject_id' => 79, 'my_class_id' => 13,
            'section_id' => 76, 'exam_id' => $exam->id, 'year' => $this->year(), 't1' => 5,
        ]);
        $this->actingAs($admin)->put(route('exams.update', $exam->id), [
            'name' => 'T11 Movable Exam', 'period_id' => $pa->id,
        ])->assertSessionHas('flash_danger');
        $this->assertSame($pb->id, (int) $exam->fresh()->period_id, 'period locked once marks exist');
    }

    /* ---------------- T19: S7 refusal ---------------- */

    public function test_t19_standalone_period_exam_refuses_marks_entry()
    {
        $admin = $this->admin();
        $standalone = $this->mkPeriod(['name' => 'T19 Midterm', 'kind' => 'standalone',
            'counts_toward_annual' => true, 'sort_order' => 40]);
        $this->actingAs($admin)->post(route('exams.store'), [
            'name' => 'T19 Midterm Exam', 'period_id' => $standalone->id, 'my_class_id' => 13,
        ])->assertRedirect(route('exams.index'));
        $exam = Exam::where('name', 'T19 Midterm Exam')->firstOrFail();
        $this->assertSame(0, (int) $exam->term, 'no legacy term — the tex0 sentinel');
        $this->assertFalse($exam->marksEnterable());

        // entryState: disabled with the plain reason.
        $res = $this->actingAs($admin)->get(route('marks.entry_state', [
            'exam_id' => $exam->id, 'class_id' => 13, 'section_id' => 76, 'subject_id' => 79,
        ]));
        $res->assertOk();
        $this->assertFalse($res->json('enabled'));
        $this->assertSame('Marks entry for this period opens in a later update.', $res->json('reason'));

        // Direct hits refused server-side (403, never a tex0 500).
        $this->actingAs($admin)->get(route('marks.manage', [$exam->id, 13, 76, 79]))
            ->assertForbidden();
        $this->actingAs($admin)->post(route('marks.submit', [$exam->id, 13, 76, 79]))
            ->assertForbidden();
        $this->actingAs($admin)->post(route('marks.import_internal', [$exam->id, 13, 76, 79]), [
            'source_kind' => 'assignments', 'mode' => 'all',
        ])->assertForbidden();
    }
}
