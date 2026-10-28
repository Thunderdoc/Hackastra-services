<?php

namespace Tests\Feature;

use App\Models\ExamDate;
use App\Models\ExamDuty;
use App\Models\LeaveRequest;
use App\Support\Invigilation;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Batch 2026-10-28 (exam-timetable round 16) — manual-assign
 * DROPDOWN coherence, companion to round 14's warning rendering.
 * Round 6 established warn-only semantics for leave and
 * own-subject assignments (the duty IS created, a note fires on
 * submit); this round proves the dropdown does not silently
 * filter out the very teachers those warnings exist for, and
 * pins exactly how a genuine clash is handled.
 *
 * Code under test (quoted, not assumed):
 * - ExamDutyController::index passes the WHOLE pool to the view:
 *   `$d['teachers'] = Invigilation::pool();`
 * - duties.blade.php renders every pool member in every sitting's
 *   <select> with no per-sitting filter and no clash/leave marker:
 *   `@foreach($teachers as $t) <option value="{{ Qs::hash($t->id) }}">{{ $t->name }}</option>`
 * - Invigilation::pool() is the ONLY filter: active teachers
 *   (user_type teacher AND status null-or-active), ordered by name.
 * - A genuine clash is a HARD refusal at submit, not a dropdown
 *   exclusion: Invigilation::assign() returns
 *   [false, 'That teacher already has a duty at this date and time.']
 *   when hasClash() fires (same date + start time, different
 *   sitting); re-saving the SAME teacher to the SAME sitting is
 *   explicitly not a clash.
 * - Leave / own-subject are SOFT: submit succeeds, duty created,
 *   warning notes appended (round 14 locked the rendering).
 *
 * The asymmetry (clash refused at submit with no in-dropdown
 * indication, leave/own-subject listed + warned) is therefore BY
 * DESIGN per the Invigilation docblock — "Hard rule: a teacher
 * never holds two duties at the same date + start time. Soft
 * rule, surfaced as a warning rather than a block: a teacher
 * should preferably not invigilate their OWN class-subject's
 * paper." — not a filtering bug. These tests lock the surface
 * so any future dropdown filter that silently drops warn-only
 * teachers fails loudly.
 *
 * Fixtures: exam 1 (Term 1), Prep (class 1) subject 1 (English,
 * teacher Ananya Sharma, teacher@hackstra.app, id 4); Priya Nair
 * (teacher2@teacher.com, id 12) is the non-own-subject teacher.
 * Sittings use a future date (2027-02-06) so no past-sitting
 * rule interferes. Each test runs inside its own transaction.
 */
class OwnerExamDutiesDropdownR16Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function ananya()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function priya()
    {
        return User::where('email', 'teacher2@teacher.com')->firstOrFail();
    }

    protected function sitting(array $over = [])
    {
        return ExamDate::create(array_merge([
            'exam_id' => 1,
            'my_class_id' => 1,
            'subject_id' => 1,
            'exam_date' => '2027-02-06',
            'start_time' => '09:00:00',
        ], $over));
    }

    protected function dutiesPageContent()
    {
        $this->actingAs($this->admin());
        return $this->get(route('exam_duties.index', ['exam_id' => 1]))
            ->assertOk()->getContent();
    }

    protected function plainOptionPattern($name)
    {
        // A plain, enabled option: the Blade template leaves a
        // space before `>` when the option is unselected
        // (`<option value="HASH" >Name</option>`), and the
        // negative lookahead rejects any disabled attribute, so
        // a future "(clash)"-style exclusion would fail loudly.
        return '/<option value="[^"]+"(?![^>]*disabled)[^>]*>' . preg_quote($name, '/') . '<\/option>/';
    }

    public function test_teacher_on_approved_leave_is_listed_in_assign_dropdown()
    {
        $this->sitting();
        LeaveRequest::create([
            'user_id' => $this->priya()->id,
            'start_date' => '2027-02-05',
            'end_date' => '2027-02-07',
            'reason' => 'probe',
            'leave_type' => 'normal',
            'status' => 'approved',
        ]);
        $this->assertTrue(Invigilation::isOnApprovedLeave($this->priya()->id, '2027-02-06'));

        $content = $this->dutiesPageContent();
        $this->assertMatchesRegularExpression(
            $this->plainOptionPattern('Priya Nair'), $content,
            'On-leave teacher must stay in the dropdown (warning fires on submit, round 14).'
        );
    }

    public function test_own_subject_teacher_is_listed_in_assign_dropdown()
    {
        $sitting = $this->sitting();
        $this->assertTrue(Invigilation::isOwnSubject($this->ananya()->id, $sitting));

        $content = $this->dutiesPageContent();
        $this->assertMatchesRegularExpression(
            $this->plainOptionPattern('Ananya Sharma'), $content,
            'Own-subject teacher must stay in the dropdown (warning fires on submit, round 14).'
        );
    }

    public function test_clash_teacher_is_listed_but_submit_refuses()
    {
        $sittingA = $this->sitting();
        [$ok] = Invigilation::assign($sittingA, (int) $this->ananya()->id, (int) $this->admin()->id);
        $this->assertTrue($ok);
        // Same date + same start time, different class/subject:
        // a genuine clash for Ananya under hasClash().
        $sittingB = $this->sitting(['my_class_id' => 2, 'subject_id' => 7]);
        $this->assertTrue(Invigilation::hasClash((int) $this->ananya()->id, $sittingB));

        // (c1) Still listed, with no in-dropdown clash indication.
        $content = $this->dutiesPageContent();
        $this->assertMatchesRegularExpression(
            $this->plainOptionPattern('Ananya Sharma'), $content,
            'Clash teacher is listed (no dropdown exclusion, no disabled marker).'
        );

        // (c2) Submit is the enforcement point: hard refusal, no row.
        $this->actingAs($this->admin());
        $response = $this->from(route('exam_duties.index', ['exam_id' => 1]))
            ->post(route('exam_duties.assign'), [
                'exam_date_id' => $sittingB->id,
                'teacher_id' => $this->ananya()->id,
            ]);
        $response->assertRedirect();
        $this->assertSame(
            'That teacher already has a duty at this date and time.',
            $response->getSession()->get('flash_danger')
        );
        $this->assertSame(0, ExamDuty::where('exam_date_id', $sittingB->id)->count());
        $this->assertSame(1, ExamDuty::where('exam_date_id', $sittingA->id)
            ->where('teacher_id', $this->ananya()->id)->count());
    }

    public function test_suspended_teacher_is_the_only_dropdown_exclusion()
    {
        $this->sitting();
        $priya = $this->priya();
        $priya->status = 'suspended';
        $priya->save();
        $this->assertNotContains($priya->id, Invigilation::pool()->pluck('id')->all());

        $content = $this->dutiesPageContent();
        $this->assertDoesNotMatchRegularExpression(
            $this->plainOptionPattern('Priya Nair'), $content,
            'Pool membership is the only dropdown filter: suspended teachers drop out.'
        );
        // And an active teacher still lists, so the page itself rendered.
        $this->assertMatchesRegularExpression(
            $this->plainOptionPattern('Ananya Sharma'), $content
        );
    }
}
