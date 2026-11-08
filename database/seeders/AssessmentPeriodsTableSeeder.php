<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Exam Patterns on a FRESH install (CI fix 2026-11-07).
 *
 * Migration 2026_11_07_000023 backfills assessment_periods from
 * exams that already exist — the upgrade path (a school with
 * data). A fresh install migrates an EMPTY database, so the
 * backfill has nothing to derive from, and the demo seeders
 * then create exams with no period links and no periods at
 * all: the Exam Patterns feature starts broken and
 * Exam::period_id stays null (the partition guard, the annual
 * filter and the pattern page all key off periods).
 *
 * This seeder closes the gap on the seed path so a fresh
 * install lands in the same world as an upgraded one:
 *  - the current session gets its Term 1/2/3 periods up front
 *    (same shape the migration backfill writes: name "Term N",
 *    kind 'term', counts_toward_annual, sort_order = term * 10,
 *    legacy_term = term);
 *  - any other (year, term) pair present in exams gets its
 *    period too (the backfill derivation, verbatim);
 *  - every exam still unlinked is linked to its (year, term)
 *    period.
 * Idempotent: periods are matched on (year, name) exactly like
 * the migration, and only null period_id exams are touched.
 */
class AssessmentPeriodsTableSeeder extends Seeder
{
    public function run()
    {
        if (!Schema::hasTable('assessment_periods') || !Schema::hasColumn('exams', 'period_id')) {
            return;
        }

        $session = (string) (DB::table('settings')
            ->where('type', 'current_session')->value('description') ?: '2026-2027');

        // The current session's terms first, in order — on a
        // fresh table this also gives Term 1 the first id, the
        // shape upgraded installs have (their backfill created
        // the earliest exam's period first).
        foreach ([1, 2, 3] as $term) {
            $this->ensureTermPeriod($session, $term);
        }

        // Backfill derivation for every other seeded (year, term).
        $pairs = DB::table('exams')->select('year', 'term')->distinct()
            ->orderBy('year')->orderBy('term')->get();
        foreach ($pairs as $pair) {
            $this->ensureTermPeriod((string) $pair->year, (int) $pair->term);
        }

        // Link every still-unlinked exam to its period.
        $unlinked = DB::table('exams')->whereNull('period_id')
            ->select('id', 'year', 'term')->get();
        foreach ($unlinked as $exam) {
            $periodId = DB::table('assessment_periods')
                ->where('year', $exam->year)->where('name', 'Term ' . $exam->term)
                ->value('id');
            if ($periodId) {
                DB::table('exams')->where('id', $exam->id)
                    ->update(['period_id' => $periodId]);
            }
        }
    }

    private function ensureTermPeriod(string $year, int $term): void
    {
        $exists = DB::table('assessment_periods')
            ->where('year', $year)->where('name', 'Term ' . $term)
            ->value('id');
        if ($exists) {
            return;
        }
        DB::table('assessment_periods')->insert([
            'year' => $year,
            'name' => 'Term ' . $term,
            'kind' => 'term',
            'feeds_period_id' => null,
            'counts_toward_annual' => true,
            'sort_order' => $term * 10,
            'legacy_term' => $term,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
