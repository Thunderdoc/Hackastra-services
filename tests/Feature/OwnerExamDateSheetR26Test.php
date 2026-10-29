<?php

namespace Tests\Feature;

use App\Models\ExamDate;
use App\Models\MyClass;
use App\Models\Subject;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Batch 2026-10-28 (exam-timetable round 26) — two seams never
 * probed before:
 *
 * (a) FEE-DEFAULTER BLOCK ON MY_CARD. Round 25 deliberately skipped
 * the fee-blocked shape (student 58 carries unpaid records in
 * 2026-2027 summing to 14400 outstanding — payment_record id 4
 * alone is 10800, but outstandingFor sums ALL unpaid records:
 * 10800 + 3000 + 600) and locked only the allow/deny gate. The
 * code (AdmitCardController::myCard) is: gate
 * first (self / own child / teamSA, else 403), then — only for
 * non-teamSA viewers — FeeDefaulter::outstandingFor > 0 renders the
 * fee_blocked view ("Admit Card Cannot Be Generated Yet") instead
 * of the card. So the expected shape is: student 58 self → 200
 * block view (with the "ask your parent" branch, since the viewer
 * is not a parent); parent of 58 → 200 block view (with the "Pay
 * the Fee" button, viewer_is_parent true); admin for 58 → 200 real
 * card (teamSA office generation is never blocked); teacher for
 * 58 → 403 (the gate fires before the fee block is even reached).
 * A card leaking to student 58 or their parent would be a REAL
 * finding; the block text names the real outstanding balance.
 *
 * (b) GENERATE MODE=REPLACE ORPHAN CLEANUP. Round 1 claimed
 * replace-mode orphan cleanup; it was never test-locked. The code
 * (ExamDateController::generate) deletes, in replace mode, every
 * row for the class whose subject_id is no longer among the
 * class's current subjects (exam_dates has no FK, so a
 * deleted/moved subject's row would otherwise survive as an
 * invisible phantom that still occupies a slot). This test creates
 * rows for 3 subjects, deletes one subject row directly in the DB
 * (no UI delete-subject-with-dates path exists), regenerates
 * mode=replace, and asserts the orphan row is gone while the two
 * surviving subjects each hold exactly one regenerated row.
 */
class OwnerExamDateSheetR26Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function parent()
    {
        return User::where('email', 'parent@hackstra.app')->firstOrFail();
    }

    protected function student58()
    {
        return User::findOrFail(58);
    }

    public function test_student_58_my_card_shows_fee_block_not_card()
    {
        // User 58 (student_record 2, linked to parent@): payment
        // records 4-6 leave 14,400 outstanding in 2026-2027.
        $response = $this->actingAs($this->student58())
            ->get(route('admit_cards.my_card', ['exam' => 1, 'student_id' => 58]));

        $response->assertStatus(200);
        $response->assertSee('Admit Card Cannot Be Generated Yet', false);
        // The student's NAME is faker-generated per seed (it was
        // 'Dariana Heaney' in one dev database, something else in
        // every fresh seed — hardcoding it kept CI red). Resolve the
        // name the seed actually created; the outstanding amount
        // (10,800 + 3,000 + 600 = 14,400) IS structural and stays.
        $response->assertSee($this->student58()->name, false);
        $response->assertSee('14,400', false);
        // Student branch of the block view (viewer is not a parent).
        $response->assertSee('Please ask your parent to pay the fee', false);
    }

    public function test_parent_my_card_for_owing_child_shows_fee_block()
    {
        $response = $this->actingAs($this->parent())
            ->get(route('admit_cards.my_card', ['exam' => 1, 'student_id' => 58]));

        $response->assertStatus(200);
        $response->assertSee('Admit Card Cannot Be Generated Yet', false);
        $response->assertSee($this->student58()->name, false);
        // Parent branch: the block view offers the Pay the Fee button.
        $response->assertSee('Pay the Fee', false);
    }

    public function test_admin_my_card_for_owing_student_bypasses_fee_block()
    {
        // teamSA office generation is never blocked (code + blade
        // comment both say so); the admin sees the real card.
        $response = $this->actingAs($this->admin())
            ->get(route('admit_cards.my_card', ['exam' => 1, 'student_id' => 58]));

        $response->assertStatus(200);
        $response->assertSee($this->student58()->name, false);
        $response->assertDontSee('Cannot Be Generated Yet', false);
    }

    public function test_teacher_my_card_for_owing_student_still_403()
    {
        // Control: the gate fires before the fee block is reached —
        // a teacher gets 403 even for an owing student, same as the
        // round-25 non-owing control.
        $response = $this->actingAs($this->teacher())
            ->get(route('admit_cards.my_card', ['exam' => 1, 'student_id' => 58]));

        $response->assertStatus(403);
    }

    public function test_generate_replace_deletes_orphan_rows_for_deleted_subject()
    {
        $class = MyClass::create(['name' => 'R26 Orphan Class']);
        $subjects = [];
        foreach (['R26 Alpha', 'R26 Beta', 'R26 Gamma'] as $i => $name) {
            $subjects[] = Subject::create([
                'name' => $name,
                'my_class_id' => $class->id,
                'teacher_id' => 4,
                'slug' => 'r26-orphan-' . $class->id . '-' . ($i + 1),
                'periods_per_week' => 6,
            ]);
        }

        // Pre-existing sheet rows for all three subjects.
        foreach ($subjects as $i => $subject) {
            ExamDate::create([
                'exam_id' => 1,
                'my_class_id' => $class->id,
                'subject_id' => $subject->id,
                'exam_date' => '2026-11-0' . ($i + 2),
                'start_time' => '09:00',
            ]);
        }
        $this->assertSame(3, ExamDate::where('exam_id', 1)->where('my_class_id', $class->id)->count());

        // The subject disappears from the subjects table (direct DB
        // delete — no UI delete-subject-with-dates path exists).
        $deletedId = $subjects[2]->id;
        Subject::where('id', $deletedId)->delete();
        $this->assertSame(0, Subject::where('id', $deletedId)->count());

        $resp = $this->actingAs($this->admin())->post(route('exam_dates.generate'), [
            'exam_id' => 1,
            'scope' => 'one',
            'class_id' => $class->id,
            'start_date' => '2026-12-01',
            'exam_days' => 10,
            'papers_per_day' => 2,
            'session1_time' => '09:00',
            'session2_time' => '13:00',
            'mode' => 'replace',
        ]);
        $resp->assertRedirect();

        // The orphan row is gone; the two surviving subjects each
        // hold exactly one regenerated row — no phantom, no dupes.
        $this->assertDatabaseMissing('exam_dates', [
            'exam_id' => 1,
            'my_class_id' => $class->id,
            'subject_id' => $deletedId,
        ]);
        $rows = ExamDate::where('exam_id', 1)->where('my_class_id', $class->id)->get();
        $this->assertSame(2, $rows->count());
        $this->assertEqualsCanonicalizing(
            [$subjects[0]->id, $subjects[1]->id],
            $rows->pluck('subject_id')->all()
        );
    }
}
