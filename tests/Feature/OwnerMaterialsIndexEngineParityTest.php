<?php

namespace Tests\Feature;

use App\Models\StudyMaterial;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * LIVE 500 on GET /materials (owner report, 2026-11-01) — teacher
 * AND admin, while every local check was green.
 *
 * Root cause (engine parity, the live-schema-parity lesson in
 * query form): the index built its filter dropdowns from
 * DISTINCT plucks cloned from the listing query, which carries
 * ORDER BY (class, subject, latest). pluck() selects ONLY the
 * plucked column but keeps the orders, producing
 *   SELECT DISTINCT subject_id FROM study_materials
 *   ORDER BY my_class_id, subject_id, created_at
 * MySQL/TiDB refuse that (error 3065 — an ORDER BY column absent
 * from the SELECT list is incompatible with DISTINCT); SQLite
 * accepts it, so the SQLite-only suite and CI could never see
 * the failure. The fix strips the listing orders (reorder()) on
 * the two derived plucks. These pins hold the invariant on any
 * engine: the page loads for every role, and no DISTINCT select
 * the request issues may carry an ORDER BY.
 */
class OwnerMaterialsIndexEngineParityTest extends TestCase
{
    use DatabaseTransactions;

    protected function userByEmail(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    public function test_materials_index_loads_for_teacher_admin_student_and_parent()
    {
        foreach (['teacher@hackstra.app', 'admin@hackstra.app', 'student@hackstra.app', 'parent@hackstra.app'] as $email) {
            $this->actingAs($this->userByEmail($email))
                ->get(route('materials.index'))
                ->assertOk();
        }
    }

    public function test_materials_index_issues_no_ordered_distinct_select()
    {
        DB::enableQueryLog();
        try {
            $this->actingAs($this->userByEmail('admin@hackstra.app'))
                ->get(route('materials.index'))
                ->assertOk();
            $queries = array_column(DB::getQueryLog(), 'query');
        } finally {
            DB::disableQueryLog();
        }

        $this->assertNotEmpty($queries);
        foreach ($queries as $sql) {
            $isDistinctSelect = stripos($sql, 'distinct') !== false
                && stripos($sql, 'count(') === false;
            if ($isDistinctSelect) {
                $this->assertStringNotContainsStringIgnoringCase(
                    'order by', $sql,
                    'a DISTINCT select must never inherit the listing ORDER BY (MySQL/TiDB error 3065)'
                );
            }
        }
    }

    public function test_materials_index_with_file_and_section_shapes_still_loads()
    {
        $teacher = $this->userByEmail('teacher@hackstra.app');
        StudyMaterial::create([
            'teacher_id' => $teacher->id,
            'my_class_id' => 1,
            'section_id' => 1,
            'subject_id' => 1,
            'title' => 'Engine Parity File Material',
            'description' => 'A document material scoped to one section.',
            'file_path' => 'study-materials/parity/notes.pdf',
            'link_url' => null,
        ]);

        $this->actingAs($teacher)->get(route('materials.index'))
            ->assertOk()
            ->assertSee('Engine Parity File Material');
        $this->actingAs($this->userByEmail('admin@hackstra.app'))->get(route('materials.index'))
            ->assertOk()
            ->assertSee('Engine Parity File Material');
    }
}
