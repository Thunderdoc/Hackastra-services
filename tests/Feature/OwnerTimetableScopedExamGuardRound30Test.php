<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\MyClass;
use App\Models\TimeTableRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Agent D round 30 — symmetric exam-scope guard on timetable records.
 *
 * Round 29 proved store_record / update_record (ttr.store / ttr.update,
 * teamSAWrite, admin-only) applied NO exam-scope check: a grid naming an
 * exam scoped to class X could be created/updated for class Y through
 * the real UI. This round builds the guard, mirroring agent E round 31's
 * scopeMismatchMessage guard in ExamDateController::generate()/save()
 * (same message text, same scope test: !empty($exam->my_class_id)).
 *
 * Failing-first: this suite was written and run BEFORE the guard was
 * added. Pre-fix, the two mismatched tests FAIL (store returns JSON
 * ok:true and the row is stored; update redirects and re-points the
 * record). Controls pass pre- and post-fix.
 *
 * The generator is NOT touched: generate() only ever writes exam_id
 * null (proven round 29). TeacherReminders is NOT touched: the
 * staff-only reminder echo for legacy direct-DB rows is out of this
 * fix's scope — with creation closed, no NEW mismatched grid can arise
 * through the UI.
 *
 * All fixtures fresh and rolled back (DatabaseTransactions).
 */
class OwnerTimetableScopedExamGuardRound30Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function freshClass(string $tag): MyClass
    {
        return MyClass::create(['name' => 'R30 Class ' . $tag . ' ' . uniqid()]);
    }

    /** An exam scoped to $classId (0 = global). Term 9 avoids the seeded global terms 1-3. */
    protected function freshExam(string $tag, int $classId, int $term = 1): Exam
    {
        return Exam::create([
            'name' => 'R30 Exam ' . $tag . ' ' . uniqid(),
            'term' => $term,
            'year' => '2026-2027',
            'my_class_id' => $classId,
            'results_published' => 0,
        ]);
    }

    protected function mismatchedPair(): array
    {
        $classX = $this->freshClass('X');
        $classY = $this->freshClass('Y');
        $scoped = $this->freshExam('Scoped', $classX->id, 1);

        return [$classX, $classY, $scoped];
    }

    protected function scopeMessage(MyClass $scopedClass, MyClass $targetClass): string
    {
        return 'This exam is scoped to ' . $scopedClass->name
            . ' — it cannot carry dates for ' . $targetClass->name . '.';
    }

    public function test_mismatched_store_is_refused_and_stores_nothing()
    {
        [$classX, $classY, $scoped] = $this->mismatchedPair();
        $name = 'R30 Mismatch ' . uniqid();

        $response = $this->actingAs($this->admin())
            ->from(route('tt.index'))
            ->post(route('ttr.store'), [
                'name' => $name,
                'my_class_id' => $classY->id,
                'exam_id' => $scoped->id,
            ]);

        $response->assertRedirect(route('tt.index'));
        $response->assertSessionHas('flash_danger', $this->scopeMessage($classX, $classY));
        $this->assertDatabaseMissing('time_table_records', ['name' => $name]);
    }

    public function test_mismatched_update_is_refused_and_keeps_old_exam()
    {
        [$classX, $classY, $scoped] = $this->mismatchedPair();
        $record = TimeTableRecord::create([
            'name' => 'R30 RePoint ' . uniqid(),
            'my_class_id' => $classY->id,
            'section_id' => null,
            'exam_id' => null,
            'year' => '2026-2027',
        ]);

        $response = $this->actingAs($this->admin())
            ->from(route('tt.index'))
            ->put(route('ttr.update', $record->id), [
                'name' => $record->name,
                'my_class_id' => $classY->id,
                'exam_id' => $scoped->id,
            ]);

        $response->assertRedirect(route('tt.index'));
        $response->assertSessionHas('flash_danger', $this->scopeMessage($classX, $classY));
        $this->assertNull($record->fresh()->exam_id);
    }

    public function test_scoped_own_global_and_no_exam_controls_still_create()
    {
        [$classX, $classY, $scoped] = $this->mismatchedPair();
        $global = $this->freshExam('Global', 0, 9);

        $ownName = 'R30 Own ' . uniqid();
        $this->actingAs($this->admin())->post(route('ttr.store'), [
            'name' => $ownName,
            'my_class_id' => $classX->id,
            'exam_id' => $scoped->id,
        ])->assertStatus(200)->assertJson(['ok' => true]);
        $this->assertDatabaseHas('time_table_records', [
            'name' => $ownName,
            'my_class_id' => $classX->id,
            'exam_id' => $scoped->id,
        ]);

        $globalName = 'R30 Global ' . uniqid();
        $this->actingAs($this->admin())->post(route('ttr.store'), [
            'name' => $globalName,
            'my_class_id' => $classY->id,
            'exam_id' => $global->id,
        ])->assertStatus(200)->assertJson(['ok' => true]);
        $this->assertDatabaseHas('time_table_records', [
            'name' => $globalName,
            'my_class_id' => $classY->id,
            'exam_id' => $global->id,
        ]);

        // Plain class grid: no exam at all — the common case, untouched.
        $plainName = 'R30 Plain ' . uniqid();
        $this->actingAs($this->admin())->post(route('ttr.store'), [
            'name' => $plainName,
            'my_class_id' => $classY->id,
        ])->assertStatus(200)->assertJson(['ok' => true]);
        $this->assertDatabaseHas('time_table_records', [
            'name' => $plainName,
            'my_class_id' => $classY->id,
            'exam_id' => null,
        ]);
    }

    public function test_scoped_own_update_still_repoints()
    {
        [$classX, $classY, $scoped] = $this->mismatchedPair();
        $record = TimeTableRecord::create([
            'name' => 'R30 OwnUpdate ' . uniqid(),
            'my_class_id' => $classX->id,
            'section_id' => null,
            'exam_id' => null,
            'year' => '2026-2027',
        ]);

        $this->actingAs($this->admin())->put(route('ttr.update', $record->id), [
            'name' => $record->name,
            'my_class_id' => $classX->id,
            'exam_id' => $scoped->id,
        ])->assertRedirect(route('tt.index'));
        $this->assertSame($scoped->id, (int) $record->fresh()->exam_id);
    }
}
