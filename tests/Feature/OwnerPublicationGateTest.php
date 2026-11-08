<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Exam;
use App\Models\ExamDate;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\Support\FeeDefaulter;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Publication gate (owner order 2026-11-08): a student-facing
 * document appears ONLY after the school publishes it.
 *
 *  - Marksheet: already gated by results_published (Workstream
 *    BH) — re-pinned here against the ONE shared not-published
 *    partial every gated document now renders.
 *  - Exam Date Sheet: students/parents land on the shared
 *    not-published state until the (exam, class) sheet is
 *    PUBLISHED, and print/PDF refuse for every unpublished
 *    sheet (dated or not). Teachers keep the working sheet
 *    view and the R24 undated-print rule.
 *  - Admit Card: NEW per-exam admit_cards_published flag
 *    (migration 2026_11_08_000031), Principal-only publish /
 *    unpublish from the Publication page, audited. The
 *    publication check sits BEFORE the fee gate on both the
 *    my-cards list and the direct card URL. Office (teamSA)
 *    generation is never blocked.
 *
 * Anchors: records 1 (owes 0) and 2 (owes 14,400), both
 * children of parent@hackstra.app; exam 1 is the session's
 * only exam and starts with nothing published.
 */
class OwnerPublicationGateTest extends TestCase
{
    use DatabaseTransactions;

    protected $sr1;
    protected $sr2;
    protected $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sr1 = StudentRecord::findOrFail(1);
        $this->sr2 = StudentRecord::findOrFail(2);
        $this->session = Qs::getCurrentSession();

        $this->assertSame(0, FeeDefaulter::outstandingFor($this->sr1->user_id, $this->session),
            'Precondition: record 1 must owe 0 for the current session');
        $this->assertSame(14400, FeeDefaulter::outstandingFor($this->sr2->user_id, $this->session),
            'Precondition: record 2 must owe 14,400 for the current session');
    }

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function principal()
    {
        return User::where('email', 'principal@hackstra.app')->firstOrFail();
    }

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function parentUser()
    {
        return User::where('email', 'parent@hackstra.app')->firstOrFail();
    }

    protected function user1()
    {
        return User::findOrFail($this->sr1->user_id);
    }

    protected function user2()
    {
        return User::findOrFail($this->sr2->user_id);
    }

    protected function seedSheet()
    {
        ExamDate::where('exam_id', 1)->where('my_class_id', 1)->delete();
        $day = 1;
        foreach (Subject::where('my_class_id', 1)->orderBy('id')->get() as $subject) {
            ExamDate::create([
                'exam_id' => 1,
                'my_class_id' => 1,
                'subject_id' => $subject->id,
                'exam_date' => '2027-02-' . str_pad($day, 2, '0', STR_PAD_LEFT),
                'start_time' => '09:00:00',
            ]);
            $day++;
        }
    }

    protected function publishSheet()
    {
        $this->actingAs($this->admin())->post(route('exam_dates.submit'), [
            'exam_id' => 1, 'class_id' => 1,
        ])->assertRedirect();
        $this->actingAs($this->principal())->post(route('exam_dates.approve'), [
            'exam_id' => 1, 'class_id' => 1,
        ])->assertRedirect();
    }

    /* ---------------- Admit cards: the new flag ---------------- */

    public function test_admit_flag_exists_and_defaults_unpublished()
    {
        $this->assertTrue(Schema::hasColumn('exams', 'admit_cards_published'));
        $exam = Exam::findOrFail(1);
        $this->assertFalse((bool) $exam->admit_cards_published,
            'Admit cards must start unpublished — publication is a deliberate act');
    }

    public function test_my_cards_list_shows_not_published_before_fee_state()
    {
        // Paid student: no card link, the not-published state instead.
        $paid = $this->actingAs($this->user1())->get(route('admit_cards.my'));
        $paid->assertStatus(200);
        $paid->assertViewIs('pages.support_team.exams.admit_my');
        $paid->assertSee('Admit card not published yet', false);
        $paid->assertSee("Your school hasn't published this yet", false);
        $paid->assertDontSee(route('admit_cards.my_card', [1, $this->sr1->user_id]), false);
        $paid->assertDontSee('View / Print', false);

        // Owing student: publication is checked BEFORE fees — the
        // fee state must not appear while unpublished.
        $owing = $this->actingAs($this->user2())->get(route('admit_cards.my'));
        $owing->assertStatus(200);
        $owing->assertSee('Admit card not published yet', false);
        $owing->assertDontSee('Fees pending', false);
        $owing->assertDontSee('14,400', false);

        // Parent list mirrors the student side.
        $parent = $this->actingAs($this->parentUser())->get(route('admit_cards.my'));
        $parent->assertStatus(200);
        $parent->assertSee('Admit card not published yet', false);
        $parent->assertDontSee('Fees pending', false);
        $parent->assertDontSee(route('admit_cards.my_card', [1, $this->sr1->user_id]), false);
    }

    public function test_direct_card_url_renders_not_published_state_for_student_and_parent()
    {
        $student = $this->actingAs($this->user1())
            ->get(route('admit_cards.my_card', ['exam' => 1, 'student_id' => $this->sr1->user_id]));
        $student->assertStatus(200);
        $student->assertViewIs('pages.support_team.not_published');
        $student->assertSee('Not Published Yet', false);
        $student->assertSee("Your school hasn't published this yet — it will appear here once published.", false);

        $parent = $this->actingAs($this->parentUser())
            ->get(route('admit_cards.my_card', ['exam' => 1, 'student_id' => $this->sr1->user_id]));
        $parent->assertStatus(200);
        $parent->assertViewIs('pages.support_team.not_published');

        // Ordering proof: the owing child also gets the
        // not-published state, never the fee block, while
        // unpublished.
        $owingChild = $this->actingAs($this->parentUser())
            ->get(route('admit_cards.my_card', ['exam' => 1, 'student_id' => $this->sr2->user_id]));
        $owingChild->assertStatus(200);
        $owingChild->assertViewIs('pages.support_team.not_published');
        $owingChild->assertDontSee('Admit Card Cannot Be Generated Yet', false);
    }

    public function test_only_principal_publishes_admit_cards_and_the_flip_is_audited()
    {
        $this->actingAs($this->admin())
            ->post(route('exams.publish_admit_cards', 1))
            ->assertForbidden();
        $this->assertFalse((bool) Exam::findOrFail(1)->admit_cards_published);

        $this->actingAs($this->principal())
            ->post(route('exams.publish_admit_cards', 1))
            ->assertRedirect();
        $this->assertTrue((bool) Exam::findOrFail(1)->admit_cards_published);
        $this->assertDatabaseHas('audit_logs', ['action' => 'exams.admit_cards_published']);

        $this->actingAs($this->principal())
            ->post(route('exams.unpublish_admit_cards', 1))
            ->assertRedirect();
        $this->assertFalse((bool) Exam::findOrFail(1)->admit_cards_published);
        $this->assertDatabaseHas('audit_logs', ['action' => 'exams.admit_cards_unpublished']);
    }

    public function test_published_admit_cards_then_the_fee_gate_applies_as_before()
    {
        Exam::findOrFail(1)->update(['admit_cards_published' => true]);

        // Paid student: the real card.
        $card = $this->actingAs($this->user1())
            ->get(route('admit_cards.my_card', ['exam' => 1, 'student_id' => $this->sr1->user_id]));
        $card->assertStatus(200);
        $card->assertViewIs('pages.support_team.exams.admit_print');

        // Owing child for the parent: NOW the fee block answers.
        $blocked = $this->actingAs($this->parentUser())
            ->get(route('admit_cards.my_card', ['exam' => 1, 'student_id' => $this->sr2->user_id]));
        $blocked->assertStatus(200);
        $blocked->assertViewIs('pages.support_team.fee_blocked');
        $blocked->assertSee('14,400', false);

        // List states return: paid child keeps View / Print, the
        // owing child shows the fee state.
        $list = $this->actingAs($this->parentUser())->get(route('admit_cards.my'));
        $list->assertSee(route('admit_cards.my_card', [1, $this->sr1->user_id]), false);
        $list->assertSee('Fees pending', false);
        $list->assertDontSee('Admit card not published yet', false);
    }

    public function test_office_generation_is_never_blocked_by_the_admit_gate()
    {
        $index = $this->actingAs($this->admin())->get(route('admit_cards.index', ['exam_id' => 1, 'class_id' => 1]));
        $index->assertStatus(200);

        $sheet = $this->actingAs($this->admin())->get(route('admit_cards.print', ['exam' => 1, 'class' => 1]));
        $sheet->assertStatus(200);
        $sheet->assertViewIs('pages.support_team.exams.admit_print');

        // The Publication page carries the switch for the Principal.
        $page = $this->actingAs($this->principal())->get(route('exams.publication', 1));
        $page->assertStatus(200);
        $page->assertSee('Admit cards are not published yet', false);
        $page->assertSee(route('exams.publish_admit_cards', 1), false);
    }

    /* ---------------- Marksheet: existing gate, shared state ---------------- */

    public function test_marksheet_unpublished_shows_the_shared_state_and_leaks_nothing()
    {
        $hash = Qs::hash($this->sr1->user_id);

        $show = $this->actingAs($this->user1())->get(route('marks.show', [$hash, $this->session]));
        $show->assertStatus(200);
        $show->assertViewIs('pages.support_team.marks.not_published');
        $show->assertSee('Results Not Published Yet', false);
        $show->assertSee("Your school hasn't published this yet — it will appear here once published.", false);
        $show->assertDontSee('English Language', false);

        $print = $this->actingAs($this->user1())->get(route('marks.print', [$hash, 1, $this->session]));
        $print->assertStatus(200);
        $print->assertViewIs('pages.support_team.marks.not_published');
        $print->assertDontSee('English Language', false);

        $annual = $this->actingAs($this->user1())->get(route('marks.annual', [$hash, $this->session]));
        $annual->assertStatus(200);
        $annual->assertViewIs('pages.support_team.marks.not_published');

        $pdfAnnual = $this->actingAs($this->user1())->get(route('marks.pdf_annual', [$hash, $this->session]));
        $pdfAnnual->assertStatus(200);
        $pdfAnnual->assertViewIs('pages.support_team.marks.not_published');

        // Parent mirrors the student.
        $parentShow = $this->actingAs($this->parentUser())->get(route('marks.show', [$hash, $this->session]));
        $parentShow->assertStatus(200);
        $parentShow->assertViewIs('pages.support_team.marks.not_published');
        $parentShow->assertDontSee('English Language', false);
    }

    public function test_marksheet_appears_once_the_principal_publishes_results()
    {
        $this->actingAs($this->principal())->post(route('exams.publish_results', 1))->assertRedirect();
        $this->assertTrue((bool) Exam::findOrFail(1)->results_published);

        $hash = Qs::hash($this->sr1->user_id);
        $show = $this->actingAs($this->user1())->get(route('marks.show', [$hash, $this->session]));
        $show->assertStatus(200);
        $show->assertViewIs('pages.support_team.marks.show.index');
        $show->assertSee('English Language', false);
    }

    /* ---------------- Exam Date Sheet ---------------- */

    public function test_date_sheet_unpublished_state_for_student_and_parent()
    {
        $this->seedSheet();

        $student = $this->actingAs($this->user1())->get(route('exam_dates.index'));
        $student->assertStatus(200);
        $student->assertViewIs('pages.support_team.not_published');
        $student->assertSee('Not Published Yet', false);
        $student->assertSee("Your school hasn't published this yet — it will appear here once published.", false);
        $student->assertDontSee('01 Feb 2027', false);
        $student->assertDontSee('English Language', false);

        $parent = $this->actingAs($this->parentUser())->get(route('exam_dates.index'));
        $parent->assertStatus(200);
        $parent->assertViewIs('pages.support_team.not_published');
        $parent->assertDontSee('01 Feb 2027', false);

        // Print + PDF refuse server-side for families.
        $this->actingAs($this->user1())->get(route('exam_dates.print'))
            ->assertRedirect(route('exam_dates.index'));
        $this->actingAs($this->user1())->get(route('exam_dates.pdf'))
            ->assertRedirect(route('exam_dates.index'));
        $this->actingAs($this->parentUser())->get(route('exam_dates.print'))
            ->assertRedirect(route('exam_dates.index'));
    }

    public function test_date_sheet_undated_and_unpublished_also_refuses_family_print()
    {
        // No dates at all and no state row: the family print/PDF
        // still refuse (owner order 2026-11-08) — the document
        // exists for families only once published. (Teachers keep
        // the R24 undated-print rule; see the overnight approval
        // suite.)
        ExamDate::where('exam_id', 1)->where('my_class_id', 1)->delete();

        $this->actingAs($this->user1())->get(route('exam_dates.print'))
            ->assertRedirect(route('exam_dates.index'));
        $this->actingAs($this->user1())->get(route('exam_dates.pdf'))
            ->assertRedirect(route('exam_dates.index'));

        $index = $this->actingAs($this->user1())->get(route('exam_dates.index'));
        $index->assertViewIs('pages.support_team.not_published');
    }

    public function test_date_sheet_appears_for_families_once_published()
    {
        $this->seedSheet();
        $this->publishSheet();

        $student = $this->actingAs($this->user1())->get(route('exam_dates.index'));
        $student->assertStatus(200);
        $student->assertViewIs('pages.support_team.exams.date_sheet');
        $student->assertSee('English Language', false);
        $student->assertSee('01 Feb 2027', false);

        $this->actingAs($this->user1())->get(route('exam_dates.print'))->assertStatus(200);
        $this->actingAs($this->parentUser())->get(route('exam_dates.index'))
            ->assertOk()->assertSee('01 Feb 2027', false);
    }
}
