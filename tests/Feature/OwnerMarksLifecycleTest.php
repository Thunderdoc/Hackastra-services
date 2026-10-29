<?php

namespace Tests\Feature;

use App\Models\ExamDate;
use App\Models\Mark;
use App\Models\MarkSubmission;
use App\Support\MarkScheme;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Marks entry lifecycle (owner order 2026-10-29).
 *
 * The owner's example: an exam on 16 July — the assigned teacher's
 * marks options (enter / edit / submit, theory + internal + every
 * component) enable ON the exam date, not before. Drafts save
 * freely while entry is open; SUBMITTING locks the unit for the
 * teacher; only the school Admin can reopen it. Exams/subjects
 * with no date-sheet row are not date-gated at all.
 *
 * Fixture: Term 1 (exam 1), Class 12 (13), Section E (68), English
 * (79) — a configured-scheme subject whose effective teacher for
 * the section is Ananya (teacher@).
 */
class OwnerMarksLifecycleTest extends TestCase
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

    protected function principal()
    {
        return User::where('email', 'principal@hackstra.app')->firstOrFail();
    }

    /** Create the unit's mark rows the way the real flow does. */
    protected function ensureRows()
    {
        $this->actingAs($this->admin())->post(route('marks.selector'), [
            'exam_id' => 1,
            'my_class_id' => 13,
            'section_id' => 68,
            'subject_id' => 79,
        ]);
        $this->assertGreaterThan(0, Mark::where([
            'exam_id' => 1, 'my_class_id' => 13, 'section_id' => 68,
            'subject_id' => 79, 'year' => '2026-2027',
        ])->count(), 'fixture: selector must create the unit mark rows');
    }

    /** A complete valid save payload: every cell a small real score. */
    protected function fullPayload($subjectId = 79)
    {
        $marks = Mark::where([
            'exam_id' => 1, 'my_class_id' => 13, 'section_id' => 68,
            'subject_id' => $subjectId, 'year' => '2026-2027',
        ])->get();
        $payload = [];
        foreach (MarkScheme::configuredComponents(1, $subjectId) as $comp) {
            foreach ($marks as $mk) {
                $payload['comp_' . $mk->id . '_' . $comp->id] = 10;
            }
        }
        return $payload;
    }

    public function test_future_exam_date_keeps_entry_closed()
    {
        $this->ensureRows();
        ExamDate::create([
            'exam_id' => 1, 'my_class_id' => 13, 'subject_id' => 79,
            'exam_date' => '2027-07-16',
        ]);

        $page = $this->actingAs($this->teacher())->get(route('marks.manage', [1, 13, 68, 79]));
        $page->assertOk();
        $page->assertSee('Marks entry opens on');
        $page->assertSee('17 Jul 2027');

        // Saves and submits are both refused before the date.
        $this->actingAs($this->teacher())->put(route('marks.update', [1, 13, 68, 79]), [])
            ->assertForbidden();
        $this->actingAs($this->teacher())->post(route('marks.submit', [1, 13, 68, 79]))
            ->assertForbidden();
        $this->assertSame(0, MarkSubmission::count());
    }

    public function test_past_exam_date_opens_entry_and_submit_locks_it()
    {
        $this->ensureRows();
        ExamDate::create([
            'exam_id' => 1, 'my_class_id' => 13, 'subject_id' => 79,
            'exam_date' => '2026-07-16',
        ]);
        $teacher = $this->teacher();

        // Draft saves work once the exam date has arrived.
        $this->actingAs($teacher)->put(route('marks.update', [1, 13, 68, 79]), $this->fullPayload())
            ->assertOk();

        // Submit locks the unit.
        $this->actingAs($teacher)->post(route('marks.submit', [1, 13, 68, 79]))
            ->assertRedirect();
        $submission = MarkSubmission::forUnit(1, 68, 79);
        $this->assertNotNull($submission);
        $this->assertSame((int) $teacher->id, (int) $submission->submitted_by);

        // No more editing for the teacher — save refused, page locked.
        $this->actingAs($teacher)->put(route('marks.update', [1, 13, 68, 79]), $this->fullPayload())
            ->assertForbidden();
        $page = $this->actingAs($teacher)->get(route('marks.manage', [1, 13, 68, 79]));
        $page->assertOk();
        $page->assertSee('Marks submitted');
        $page->assertSee('locked for editing');

        // Neither the teacher nor the principal can reopen — the
        // teacher reaches the controller and is refused; the
        // principal is stopped by the marks route group's team gate
        // (redirect) before that. Either way the lock survives.
        $this->actingAs($teacher)->post(route('marks.reopen', [1, 13, 68, 79]))
            ->assertForbidden();
        $this->actingAs($this->principal())->post(route('marks.reopen', [1, 13, 68, 79]))
            ->assertRedirect();
        $this->assertNotNull(MarkSubmission::forUnit(1, 68, 79));

        // Admin reopen returns the unit to draft; saving works again.
        $this->actingAs($this->admin())->post(route('marks.reopen', [1, 13, 68, 79]))
            ->assertRedirect();
        $this->assertNull(MarkSubmission::forUnit(1, 68, 79));
        $this->actingAs($teacher)->put(route('marks.update', [1, 13, 68, 79]), $this->fullPayload())
            ->assertOk();
    }

    public function test_exam_day_itself_is_still_closed_and_next_day_opens()
    {
        $this->ensureRows();
        // Exam TODAY: the paper is being written today — entry is
        // still closed (owner rule: it opens the NEXT day).
        ExamDate::create([
            'exam_id' => 1, 'my_class_id' => 13, 'subject_id' => 79,
            'exam_date' => \Illuminate\Support\Carbon::today()->toDateString(),
        ]);
        $this->actingAs($this->teacher())->put(route('marks.update', [1, 13, 68, 79]), [])
            ->assertForbidden();
    }

    public function test_day_after_exam_opens_entry()
    {
        $this->ensureRows();
        ExamDate::create([
            'exam_id' => 1, 'my_class_id' => 13, 'subject_id' => 79,
            'exam_date' => \Illuminate\Support\Carbon::yesterday()->toDateString(),
        ]);
        $this->actingAs($this->teacher())->put(route('marks.update', [1, 13, 68, 79]), $this->fullPayload())
            ->assertOk();
    }

    public function test_no_exam_date_row_means_no_date_gate()
    {
        $this->ensureRows();

        $page = $this->actingAs($this->teacher())->get(route('marks.manage', [1, 13, 68, 79]));
        $page->assertOk();
        $page->assertSee('Draft — not yet submitted');
        $page->assertSee('Submit Marks');

        $this->actingAs($this->teacher())->put(route('marks.update', [1, 13, 68, 79]), $this->fullPayload())
            ->assertOk();
    }

    public function test_future_date_for_one_subject_does_not_gate_another()
    {
        $this->ensureRows();
        ExamDate::create([
            'exam_id' => 1, 'my_class_id' => 13, 'subject_id' => 79,
            'exam_date' => '2027-07-16',
        ]);

        // Hindi (80) has no date row: the admin can still save its
        // marks — the gate is per (exam, class, subject), never global.
        $this->actingAs($this->admin())->post(route('marks.selector'), [
            'exam_id' => 1, 'my_class_id' => 13, 'section_id' => 68, 'subject_id' => 80,
        ]);
        $this->actingAs($this->admin())->put(route('marks.update', [1, 13, 68, 80]), $this->fullPayload(80))
            ->assertOk();
    }
}
