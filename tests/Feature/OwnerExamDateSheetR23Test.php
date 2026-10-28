<?php

namespace Tests\Feature;

use App\Models\ExamDate;
use App\Models\MyClass;
use App\Models\Section;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Round 23 (2026-10-28): two date-sheet/admit seams never probed.
 *
 * (a) EMPTY-SUBJECT CLASS bulk admit: a class WITH students but
 * ZERO subjects configured. Code reading says this renders
 * cleanly — buildSchedule returns rows [] + fallback true, the
 * blade shows the toolbar warning plus one card whose subject
 * table carries the single "No subjects found for this class."
 * row — but no test ever proved it, and a 500 here (a card with
 * no subjects at all) would have been a live break. Locked here.
 * WORDING WRINKLE, stated openly (observation, NOT a defect):
 * the toolbar warning says the cards "list the class subjects
 * without dates" and the fallback note says "the subjects above
 * will be announced" — both assume at least one subject exists.
 * For a zero-subject class neither sentence is literally true,
 * but the card itself is honest ("No subjects found"). Wording
 * is shared with the normal dateless case, so it is left alone;
 * a product call if the owner wants a zero-subject variant.
 *
 * (b) CROSS-SUBJECT SAME-SLOT save — ROUND-11 DISTINCTION,
 * stated precisely so this round does not double-claim: round
 * 11 ALREADY locked exactly this refusal with TWO SUBMITTED
 * subjects (English + Mathematics both posted to 2026-11-05
 * 09:00 → "Two papers cannot share one session"). The save's
 * clash check groups the POST-SAVE map by (date, time) across
 * ALL subjects of the class — it is not a same-subject-twice
 * check. What round 11 did NOT cover is a new paper clashing
 * with an UNTOUCHED stored row (only one subject submitted,
 * the other already sitting in the stored sheet). That shape
 * is locked here: the postMap overlays stored rows, so the
 * untouched row still blocks the save.
 *
 * (c) TIME-LESS EXEMPTION, documented (observation, NOT a
 * defect, NOT locked as approved behavior): clashGroups skips
 * rows with no start_time, so a paper with a date but no time
 * is not a "session" and cannot clash — a timed paper can be
 * saved onto the same date as an untimed paper. Changing that
 * would be a product call (a dateless-time paper is arguably
 * unscheduled, not colliding); this test documents the current
 * behavior and must be updated if the rule ever tightens.
 *
 * Anchors: exam 1 (global Term 1), Prep = class 1, subjects
 * 1–6. Today in the test env is 2026-10-28, so 2026-11-* are
 * future. All probe rows live inside DatabaseTransactions
 * and roll back.
 */
class OwnerExamDateSheetR23Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function manageUrl()
    {
        return route('exam_dates.manage', ['exam_id' => 1, 'class_id' => 1]);
    }

    protected function store(int $subjectId, string $date, ?string $time = '09:00')
    {
        return ExamDate::create([
            'exam_id' => 1, 'my_class_id' => 1, 'subject_id' => $subjectId,
            'exam_date' => $date, 'start_time' => $time, 'note' => null,
        ]);
    }

    protected function rows()
    {
        return ExamDate::where('exam_id', 1)->where('my_class_id', 1)
            ->orderBy('subject_id')->get(['subject_id', 'exam_date', 'start_time'])
            ->map(function ($r) {
                return [(int) $r->subject_id, (string) $r->exam_date, $r->start_time ? substr((string) $r->start_time, 0, 5) : null];
            })->all();
    }

    protected function postSave(array $dates)
    {
        return $this->from($this->manageUrl())->post(route('exam_dates.save'), [
            'exam_id' => 1, 'class_id' => 1, 'dates' => $dates,
        ]);
    }

    public function test_bulk_print_for_class_with_students_but_no_subjects_renders_cleanly()
    {
        $class = MyClass::create(['name' => 'R23 Empty ' . uniqid()]);
        $section = Section::create([
            'name' => 'R23-A', 'my_class_id' => $class->id, 'teacher_id' => 4,
        ]);
        $user = User::create([
            'name' => 'R23 Student', 'email' => 'r23student' . $class->id . '@example.com',
            'password' => bcrypt('student'), 'user_type' => 'student',
            'code' => 'R23-' . $class->id,
        ]);
        StudentRecord::create([
            'session' => '2026-2027', 'user_id' => $user->id,
            'my_class_id' => $class->id, 'section_id' => $section->id,
            'adm_no' => 'R23/' . $class->id, 'grad' => 0,
        ]);
        $this->assertSame(0, Subject::where('my_class_id', $class->id)->count());

        $resp = $this->actingAs($this->admin())
            ->get(route('admit_cards.print', [1, $class->id]));
        $resp->assertOk();
        $resp->assertSee('1 card(s)');
        $resp->assertSee('No exam timetable or date sheet has been built');
        $resp->assertSee('No subjects found for this class.');
        $resp->assertSee('R23 Student');
        // No subject row of any kind: the card table holds only
        // the single empty-state row, never a broken subject line.
        $resp->assertDontSee('English Language');
    }

    public function test_save_clashing_with_untouched_stored_row_refused_and_rows_unchanged()
    {
        $this->actingAs($this->admin());
        // Subject 2 already sits in the stored sheet at the slot.
        // Only subject 1 is submitted — the clash partner is never
        // posted, so a submission-only check would miss it.
        $this->store(2, '2026-11-05', '09:00');
        $before = $this->rows();
        $n1 = Subject::find(1)->name;
        $n2 = Subject::find(2)->name;

        $resp = $this->postSave([
            1 => ['exam_date' => '2026-11-05', 'start_time' => '09:00'],
        ]);
        $resp->assertRedirect($this->manageUrl());
        $resp->assertSessionHas('flash_danger',
            "Two papers cannot share one session: {$n1} and {$n2} are both on 2026-11-05 at 09:00. Move one to another session.");
        $this->assertSame($before, $this->rows());
    }

    public function test_save_with_time_less_stored_paper_does_not_clash_documents_exemption()
    {
        $this->actingAs($this->admin());
        // Subject 2 has a date but NO start time: by construction
        // clashGroups skips time-less rows, so it is not a session
        // and cannot block subject 1's timed paper on the same date.
        $this->store(2, '2026-11-05', null);

        $resp = $this->postSave([
            1 => ['exam_date' => '2026-11-05', 'start_time' => '09:00'],
        ]);
        $resp->assertRedirect($this->manageUrl());
        $resp->assertSessionHas('flash_success');
        $this->assertSame([
            [1, '2026-11-05', '09:00'],
            [2, '2026-11-05', null],
        ], $this->rows());
    }
}
