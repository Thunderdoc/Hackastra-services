<?php

namespace Tests\Feature;

use App\Models\Mark;
use App\Support\MarkScheme;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Wave 32 (corrected owner rule): marks entry stays ENABLED for
 * submitted marks. Re-saving an entered combination must succeed
 * idempotently — no duplicate-key 500, no duplicate component rows,
 * no disabled grid — for teachers and Admin alike.
 *
 * Combo under test: Term 1 (exam 1), Class 12 (13), Section E (68),
 * English Language (79, configured scheme). Users are resolved by
 * email, never hardcoded ids. Section 68 (not 66): Ananya is the
 * EFFECTIVE teacher of section 68 English (override row 68/79) —
 * agent C round 24 tightened marks entry from class scope to
 * effective-teacher scope, so the teacher half of this suite must
 * use a section its teacher actually teaches (section 66 English
 * belongs to override teacher 45).
 */
class OwnerMarksResaveTest extends TestCase
{
    use DatabaseTransactions;

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function superAdmin()
    {
        return User::where('email', 'superadmin@hackstra.app')->firstOrFail();
    }

    protected function combo(): array
    {
        return [1, 13, 68, 79];
    }

    protected function comboRow(): array
    {
        return ['exam_id' => 1, 'my_class_id' => 13, 'section_id' => 68, 'subject_id' => 79];
    }

    /** Current stored scores as the manage page renders them. */
    protected function payload(): array
    {
        $data = [];
        $marks = Mark::where($this->comboRow())->get();
        foreach ($marks as $mk) {
            foreach (MarkScheme::components(1, 79) as $comp) {
                $v = MarkScheme::scoreFor($mk, $comp);
                $data['comp_'.$mk->id.'_'.$comp['id']] = $v === null ? '' : (string) $v;
            }
        }
        return $data;
    }

    protected function componentCount(): int
    {
        $markIds = Mark::where($this->comboRow())->pluck('id')->all();
        return DB::table('mark_components')->whereIn('mark_id', $markIds)->count();
    }

    public function test_teacher_save_twice_is_idempotent()
    {
        $before = $this->componentCount();
        $response = $this->actingAs($this->teacher())
            ->put(route('marks.update', $this->combo()), $this->payload());
        $response->assertOk();
        $response = $this->actingAs($this->teacher())
            ->put(route('marks.update', $this->combo()), $this->payload());
        $response->assertOk();
        $this->assertSame($before, $this->componentCount(), 'Re-saving must not duplicate component rows');
    }

    public function test_teacher_grid_stays_enabled_after_save()
    {
        $this->actingAs($this->teacher())
            ->put(route('marks.update', $this->combo()), $this->payload())
            ->assertOk();

        $response = $this->actingAs($this->teacher())
            ->get(route('marks.manage', $this->combo()));
        $response->assertOk();
        $content = $response->getContent();
        $this->assertMatchesRegularExpression('/<button type="submit" class="btn btn-primary">Update Marks/', $content, 'Submit button must stay visible for submitted marks');
        $this->assertDoesNotMatchRegularExpression('/name="comp_\d+_\d+"[^>]*disabled/', $content, 'Inputs must stay enabled for submitted marks');
    }

    public function test_admin_save_twice_is_idempotent()
    {
        $this->actingAs($this->admin())
            ->put(route('marks.update', $this->combo()), $this->payload())
            ->assertOk();
        $this->actingAs($this->admin())
            ->put(route('marks.update', $this->combo()), $this->payload())
            ->assertOk();
    }

    public function test_super_admin_put_is_forbidden()
    {
        $response = $this->actingAs($this->superAdmin())
            ->put(route('marks.update', $this->combo()), $this->payload());
        $response->assertForbidden();
    }

    public function test_orphan_mark_put_does_not_500()
    {
        // FK enforcement is off on this connection, so deleting the
        // user leaves its mark row orphaned (user relation = null).
        $mk = Mark::where($this->comboRow())->firstOrFail();
        User::where('id', $mk->student_id)->delete();
        $this->assertNull(Mark::find($mk->id)->user);

        $response = $this->actingAs($this->admin())
            ->put(route('marks.update', $this->combo()), $this->payload());
        $response->assertOk();
    }
}
