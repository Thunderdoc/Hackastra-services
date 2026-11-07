<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Exam Patterns Phase 1 (Round 3, R1 design §1/§3) — the
 * assessment_periods model.
 *
 * One row of assessment_periods is one named period of one
 * session (Term 1, Unit Test 2, Midterm…): kind term|unit|
 * standalone, an optional feeds_period_id (unit -> the term
 * period its marks feed), counts_toward_annual, sort_order and
 * the transitional legacy_term (1/2/3) that keeps every legacy
 * reader (tex{term} totals, ordinal labels) byte-identical.
 *
 * up(): create the table, add exams.period_id (nullable FK,
 * restrict), BACKFILL one term period per distinct (year, term)
 * present in exams and link every exam to it, then swap the
 * exams unique from (term, year, my_class_id) to
 * (period_id, year, my_class_id). The swap is safe at that
 * moment because period_id is then a bijection with term within
 * each year, so the new unique accepts/rejects exactly the same
 * combinations as the old one (design §3.2).
 *
 * down(): reverses the swap and drops the column/table, but
 * THROWS when any exam is linked to a period whose kind is not
 * 'term' or whose legacy_term does not match the exam's term —
 * rolling back with unit/custom exams present would silently
 * corrupt their meaning, so the rollback refuses instead.
 */
class CreateAssessmentPeriodsAndLinkExams extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('assessment_periods')) {
            Schema::create('assessment_periods', function (Blueprint $table) {
                $table->increments('id');
                $table->string('year', 40);
                $table->string('name', 80);
                $table->string('kind', 16)->default('term');
                $table->unsignedInteger('feeds_period_id')->nullable();
                $table->boolean('counts_toward_annual')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->tinyInteger('legacy_term')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->foreign('feeds_period_id')->references('id')->on('assessment_periods')->onDelete('restrict');
                $table->unique(['year', 'name']);
                $table->index(['year', 'sort_order']);
            });
        }

        if (!Schema::hasColumn('exams', 'period_id')) {
            Schema::table('exams', function (Blueprint $table) {
                $table->unsignedInteger('period_id')->nullable()->after('my_class_id');
                $table->foreign('period_id')->references('id')->on('assessment_periods')->onDelete('restrict');
                $table->index('period_id');
            });
        }

        // Backfill (design §3.1): one term period per distinct
        // (year, term) in exams; link every exam of that pair.
        $pairs = DB::table('exams')->select('year', 'term')->distinct()
            ->orderBy('year')->orderBy('term')->get();
        foreach ($pairs as $pair) {
            $periodId = DB::table('assessment_periods')
                ->where('year', $pair->year)->where('name', 'Term ' . $pair->term)
                ->value('id');
            if (!$periodId) {
                $periodId = DB::table('assessment_periods')->insertGetId([
                    'year' => $pair->year,
                    'name' => 'Term ' . $pair->term,
                    'kind' => 'term',
                    'feeds_period_id' => null,
                    'counts_toward_annual' => true,
                    'sort_order' => ((int) $pair->term) * 10,
                    'legacy_term' => (int) $pair->term,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            DB::table('exams')->where('year', $pair->year)->where('term', $pair->term)
                ->update(['period_id' => $periodId]);
        }

        // Unique swap (design §3.2).
        // The PRAGMA runs ONLY on sqlite — it is sqlite syntax and
        // TiDB/MySQL rejects it (this exact line aborted the live
        // deploy on 2026-11-07: the fetch originally ran before the
        // driver check, on every engine).
        $isSqlite = DB::getDriverName() === 'sqlite';
        $indexes = $isSqlite
            ? collect(DB::select("PRAGMA index_list('exams')"))->pluck('name')->all()
            : [];
        $hasOld = $isSqlite
            ? in_array('exams_term_year_my_class_id_unique', $indexes, true)
            : Schema::hasIndex('exams', 'exams_term_year_my_class_id_unique');
        $hasNew = $isSqlite
            ? in_array('exams_period_id_year_my_class_id_unique', $indexes, true)
            : Schema::hasIndex('exams', 'exams_period_id_year_my_class_id_unique');
        if ($hasOld) {
            Schema::table('exams', function (Blueprint $table) {
                $table->dropUnique('exams_term_year_my_class_id_unique');
            });
        }
        if (!$hasNew) {
            Schema::table('exams', function (Blueprint $table) {
                $table->unique(['period_id', 'year', 'my_class_id']);
            });
        }
    }

    public function down()
    {
        // Refuse (see docblock): a rollback is only clean while
        // every exam still maps 1:1 onto a legacy term period.
        $bad = DB::table('exams')
            ->join('assessment_periods', 'assessment_periods.id', '=', 'exams.period_id')
            ->where(function ($q) {
                $q->where('assessment_periods.kind', '!=', 'term')
                    ->orWhereColumn('assessment_periods.legacy_term', '!=', 'exams.term')
                    ->orWhereNull('assessment_periods.legacy_term');
            })
            ->count();
        if ($bad > 0) {
            throw new RuntimeException(
                'Cannot roll back the assessment_periods migration: ' . $bad .
                ' exam(s) belong to unit/custom periods or periods whose legacy_term does not match the exam term. ' .
                'Remove those exams/periods first; refusing to silently corrupt them.'
            );
        }

        Schema::table('exams', function (Blueprint $table) {
            $table->dropUnique('exams_period_id_year_my_class_id_unique');
            $table->unique(['term', 'year', 'my_class_id']);
        });
        Schema::table('exams', function (Blueprint $table) {
            $table->dropForeign(['period_id']);
            $table->dropColumn('period_id');
        });
        Schema::dropIfExists('assessment_periods');
    }
}
