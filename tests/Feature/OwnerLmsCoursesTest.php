<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Assignment;
use App\Models\AttendanceSessionEntry;
use App\Models\CourseNotice;
use App\Models\LeaveRequest;
use App\Models\Section;
use App\Models\StudentRecord;
use App\Models\StudyMaterial;
use App\Models\Subject;
use App\Models\Substitution;
use App\Models\TimeTable;
use App\Support\SectionTeacher;
use App\Support\Streams;
use App\Support\SubstitutionAccess;
use App\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * LMS build (owner-approved samples, 2026-11-08): My Week (fitted
 * grid + courses table + attendance on one surface), the courses
 * fold-in (cards, course page, notices, pin + sequence), the
 * retired student list redirects, scope refusals on both sides,
 * and the substitute's read-only course window.
 *
 * Everything resolves by email / name at runtime — no hardcoded
 * ids (seed ids shifted before and will again).
 */
class OwnerLmsCoursesTest extends TestCase
{
    use DatabaseTransactions;

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function student(): User
    {
        return $this->user('student@hackstra.app');
    }

    private function teacher(): User
    {
        return $this->user('teacher@hackstra.app');
    }

    private function studentRecord(): StudentRecord
    {
        return Qs::getSRByUserID($this->student()->id);
    }

    private function prepEnglish(StudentRecord $sr): Subject
    {
        return Subject::where('my_class_id', $sr->my_class_id)
            ->where('name', 'English Language')->firstOrFail();
    }

    // ---------------------------------------------------------------
    // PART 1 — My Week
    // ---------------------------------------------------------------

    public function test_my_week_grid_contains_every_cell_and_courses_table_matches_db()
    {
        $sr = $this->studentRecord();
        $record = SectionTeacher::recordForClassSection(
            $sr->my_class_id, $sr->section_id, Qs::getCurrentSession());
        $this->assertNotNull($record, 'premise: demo section must have an in-force record');

        $cells = TimeTable::where('ttr_id', $record->id)->with('subject')->get();
        $this->assertTrue($cells->count() > 0, 'premise: record must have cells');

        $html = $this->actingAs($this->student())->get(route('tt.mine'))
            ->assertOk()->getContent();

        // Fitted grid (never the sideways-scrolling one) and every
        // subject that appears in a cell is on the page.
        $this->assertStringContainsString('mw-week-table', $html);
        $this->assertStringContainsString('mw-daylist', $html);
        foreach ($cells->pluck('subject.name')->unique() as $name) {
            $this->assertStringContainsString($name, $html, "grid must show {$name}");
        }

        // Courses table: for every subject of the student's class,
        // the row carries the DB's effective teacher and the DB's
        // counted periods/week.
        // (Anchored on the card headings — the sidebar menu also
        // carries both labels, earlier in the document.)
        $tableStart = strpos($html, '>My Courses</p>');
        $tableEnd = strpos($html, '>My Attendance</p>');
        $this->assertTrue($tableStart !== false && $tableEnd !== false && $tableEnd > $tableStart);
        $table = substr($html, $tableStart, $tableEnd - $tableStart);

        $subjects = Streams::subjectsForSection(
            Subject::where('my_class_id', $sr->my_class_id)->get(), $sr->section);
        foreach ($subjects as $subject) {
            // Exact cell text — a bare name search would land
            // "Science" inside "Computer Science"'s row first.
            $pos = strpos($table, '>' . $subject->name . '</strong>');
            $this->assertTrue($pos !== false, "courses table must list {$subject->name}");
            $row = substr($table, $pos, 900);

            $teacher = SectionTeacher::teacherFor($sr->section_id, $subject);
            if ($teacher) {
                $this->assertStringContainsString($teacher->name, $row,
                    "courses table must name the effective teacher for {$subject->name}");
            }
            $expected = $cells->where('subject_id', $subject->id)->count();
            $this->assertMatchesRegularExpression(
                '/data-label="Periods\/Week">\s*' . $expected . '\s*</',
                $row,
                "courses table periods/week for {$subject->name} must be {$expected}");
            // Honest placeholders: the seed assigns no rooms, and
            // no Prep subject is a lab subject.
            $this->assertMatchesRegularExpression('/data-label="Room">\s*—\s*</', $row);
            $this->assertMatchesRegularExpression('/data-label="Type">\s*Theory\s*</', $row);
        }
    }

    public function test_attendance_figures_and_drilldown_match_period_truth()
    {
        $sr = $this->studentRecord();
        $entries = AttendanceSessionEntry::where('student_record_id', $sr->id)
            ->with(['session.time_table.subject'])->get();
        $this->assertTrue($entries->count() > 0, 'premise: demo student must have entries');

        $marked = $entries->count();
        $attended = $entries->whereIn('status', ['present', 'late'])->count();
        $percent = (int) round($attended / $marked * 100);

        $response = $this->actingAs($this->student())->get(route('attendance.my'))->assertOk();
        $summary = $response->viewData('summary');
        $this->assertSame($marked, $summary['marked']);
        $this->assertSame($attended, $summary['attended']);
        $this->assertSame($percent, $summary['percent']);

        $html = $response->getContent();
        // Month cards ARE the picker — no raw month input remains.
        $this->assertStringContainsString('mw-monthcard', $html);
        $this->assertStringNotContainsString('type="month"', $html);
        $this->assertStringContainsString('Whole session', $html);

        // By-subject figures for one subject, recomputed raw.
        // (Subject rows carry the Subject model under 'subject',
        // per StudentAttendance::build's contract.)
        $subject = $entries->first()->session->subject;
        $subEntries = $entries->filter(function ($e) use ($subject) {
            return (int) $e->session->subject_id === (int) $subject->id;
        });
        $row = collect($response->viewData('subjects'))->first(function ($r) use ($subject) {
            return (int) optional($r['subject'])->id === (int) $subject->id;
        });
        $this->assertNotNull($row, 'premise: subject row must exist');
        $this->assertSame($subEntries->count(), $row['conducted']);
        $this->assertSame($subEntries->whereIn('status', ['present', 'late'])->count(), $row['attended']);

        // Drill-down: a real register date renders in the page.
        $aDate = $entries->first()->session->date->format('d M Y');
        $this->assertStringContainsString($aDate, $html);
        $this->assertStringContainsString('mw-register', $html);
    }

    public function test_leave_covered_absence_is_tagged_but_still_counts()
    {
        $sr = $this->studentRecord();
        $student = $this->student();
        $absent = AttendanceSessionEntry::where('student_record_id', $sr->id)
            ->where('status', 'absent')->with('session')->first();
        $this->assertNotNull($absent, 'premise: demo student must have an absence');

        $before = $this->actingAs($student)->get(route('attendance.my'))->viewData('summary');

        LeaveRequest::create([
            'user_id' => $student->id,
            'start_date' => $absent->session->date->toDateString(),
            'end_date' => $absent->session->date->toDateString(),
            'reason' => 'LMS leave tag fixture',
            'leave_type' => 'normal',
            'status' => 'approved',
            'decided_by' => $this->user('admin@hackstra.app')->id,
            'decided_at' => now(),
        ]);

        $response = $this->actingAs($student)->get(route('attendance.my'))->assertOk();
        // The rendered badge uses the &middot; entity; one tag is
        // the legend chip, so a covered register row means >= 2.
        $this->assertTrue(substr_count($response->getContent(), 'mw-leave-tag') >= 2,
            'a leave-covered absence must wear the dashed tag in its register row');
        $response->assertSee('Absent &middot; Leave approved', false);
        // Settled rule: the tag is display-only — the absence
        // still counts exactly as before.
        $after = $response->viewData('summary');
        $this->assertSame($before['percent'], $after['percent']);
        $this->assertSame($before['marked'], $after['marked']);
    }

    public function test_record_show_page_uses_the_fitted_grid()
    {
        $sr = $this->studentRecord();
        $record = SectionTeacher::recordForClassSection(
            $sr->my_class_id, $sr->section_id, Qs::getCurrentSession());
        $this->assertNotNull($record);

        $html = $this->actingAs($this->user('admin@hackstra.app'))
            ->get(route('ttr.show', $record->id))->assertOk()->getContent();
        $this->assertStringContainsString('mw-week-table', $html);
        foreach (TimeTable::where('ttr_id', $record->id)->with('subject')->get()
                     ->pluck('subject.name')->unique() as $name) {
            $this->assertStringContainsString($name, $html);
        }
    }

    // ---------------------------------------------------------------
    // PART 2 — Courses
    // ---------------------------------------------------------------

    public function test_course_cards_cover_own_subjects_only()
    {
        $sr = $this->studentRecord();
        $subjects = Streams::subjectsForSection(
            Subject::where('my_class_id', $sr->my_class_id)->get(), $sr->section);

        $html = $this->actingAs($this->student())->get(route('courses.index'))
            ->assertOk()->getContent();
        $this->assertSame($subjects->count(), substr_count($html, 'course-card"'));
        foreach ($subjects as $subject) {
            $this->assertStringContainsString($subject->name, $html);
        }
        // Another class's subject never appears as a card.
        $foreign = Subject::where('my_class_id', '!=', $sr->my_class_id)
            ->where('name', 'Physics')->first();
        if ($foreign) {
            $this->assertStringNotContainsString('course/' . $foreign->id, $html);
        }
    }

    public function test_pin_unpin_and_reorder_persist_and_order_the_student_course()
    {
        $teacher = $this->teacher();
        $sr = $this->studentRecord();
        $subject = $this->prepEnglish($sr);

        $mk = function (string $title) use ($teacher, $sr, $subject) {
            return StudyMaterial::create([
                'teacher_id' => $teacher->id,
                'my_class_id' => $sr->my_class_id,
                'section_id' => $sr->section_id,
                'subject_id' => $subject->id,
                'title' => $title,
                'link_url' => 'https://example.com/' . $title,
            ]);
        };
        $alpha = $mk('LMSPIN Alpha');
        $beta = $mk('LMSPIN Beta');
        $gamma = $mk('LMSPIN Gamma');

        // Pin the oldest: it must jump above the newer ones for
        // the student.
        $this->actingAs($teacher)->post(route('courses.pin'), [
            'kind' => 'material', 'id' => $alpha->id,
        ])->assertRedirect();
        $this->assertTrue((bool) $alpha->fresh()->is_pinned);

        $html = $this->actingAs($this->student())->get(route('courses.show', $subject->id))
            ->assertOk()->getContent();
        $this->assertTrue(strpos($html, 'LMSPIN Alpha') < strpos($html, 'LMSPIN Gamma'),
            'pinned item must render before newer unpinned items');

        // Re-sequence Beta, Alpha, Gamma and unpin: the posted
        // order becomes the student's order.
        $this->actingAs($teacher)->postJson(route('courses.reorder'), [
            'kind' => 'material', 'ids' => [$beta->id, $alpha->id, $gamma->id],
        ])->assertOk()->assertJson(['ok' => true]);
        $this->assertSame(1, (int) $beta->fresh()->seq);
        $this->assertSame(2, (int) $alpha->fresh()->seq);
        $this->assertSame(3, (int) $gamma->fresh()->seq);

        $this->actingAs($teacher)->post(route('courses.pin'), [
            'kind' => 'material', 'id' => $alpha->id,
        ])->assertRedirect();
        $this->assertFalse((bool) $alpha->fresh()->is_pinned);

        $html = $this->actingAs($this->student())->get(route('courses.show', $subject->id))
            ->assertOk()->getContent();
        $this->assertTrue(strpos($html, 'LMSPIN Beta') < strpos($html, 'LMSPIN Alpha'));
        $this->assertTrue(strpos($html, 'LMSPIN Alpha') < strpos($html, 'LMSPIN Gamma'));
    }

    public function test_notice_create_pin_and_section_scoping()
    {
        $teacher = $this->teacher();
        $sr = $this->studentRecord();
        $subject = $this->prepEnglish($sr);

        $this->actingAs($teacher)->post(route('courses.notices.store', $subject->id), [
            'section_id' => $sr->section_id,
            'body' => 'LMSNOTE Section A only',
            'is_pinned' => 1,
        ])->assertRedirect();
        $notice = CourseNotice::where('body', 'LMSNOTE Section A only')->first();
        $this->assertNotNull($notice);
        $this->assertTrue((bool) $notice->is_pinned);

        // Own section sees it, pinned on top of the course.
        $html = $this->actingAs($this->student())->get(route('courses.show', $subject->id))
            ->assertOk()->getContent();
        $this->assertStringContainsString('LMSNOTE Section A only', $html);
        $this->assertStringContainsString('Pinned notice', $html);

        // Another section's student of the SAME class does not.
        $otherSection = Section::where('my_class_id', $sr->my_class_id)
            ->where('id', '!=', $sr->section_id)->firstOrFail();
        $otherRecord = StudentRecord::where('section_id', $otherSection->id)->firstOrFail();
        $otherStudent = User::findOrFail($otherRecord->user_id);
        $otherHtml = $this->actingAs($otherStudent)->get(route('courses.show', $subject->id))
            ->assertOk()->getContent();
        $this->assertStringNotContainsString('LMSNOTE Section A only', $otherHtml);

        // A whole-class notice reaches both sections.
        $this->actingAs($teacher)->post(route('courses.notices.store', $subject->id), [
            'body' => 'LMSNOTE Whole class',
        ])->assertRedirect();
        $otherHtml = $this->actingAs($otherStudent)->get(route('courses.show', $subject->id))
            ->assertOk()->getContent();
        $this->assertStringContainsString('LMSNOTE Whole class', $otherHtml);

        // Students cannot post notices at all.
        $this->actingAs($this->student())->post(route('courses.notices.store', $subject->id), [
            'body' => 'LMSNOTE Student forgery',
        ])->assertForbidden();
    }

    public function test_teacher_scope_refusals_for_foreign_subject()
    {
        $teacher = $this->teacher();
        // A subject she neither owns nor effectively teaches in
        // any section (find one by the same rule the app uses).
        $foreign = null;
        foreach (Subject::where('teacher_id', '!=', $teacher->id)->get() as $candidate) {
            $effective = Section::where('my_class_id', $candidate->my_class_id)->get()
                ->contains(function ($sec) use ($teacher, $candidate) {
                    return SectionTeacher::teacherIdFor($sec->id, $candidate) === (int) $teacher->id;
                });
            if (!$effective) {
                $foreign = $candidate;
                break;
            }
        }
        $this->assertNotNull($foreign, 'premise: a foreign subject must exist');

        $this->actingAs($teacher)->get(route('courses.show', $foreign->id))->assertForbidden();
        $this->actingAs($teacher)->post(route('courses.notices.store', $foreign->id), [
            'body' => 'LMSNOTE Foreign',
        ])->assertForbidden();

        $material = StudyMaterial::create([
            'teacher_id' => $foreign->teacher_id,
            'my_class_id' => $foreign->my_class_id,
            'section_id' => Section::where('my_class_id', $foreign->my_class_id)->value('id'),
            'subject_id' => $foreign->id,
            'title' => 'LMSPIN Foreign material',
            'link_url' => 'https://example.com/foreign',
        ]);
        $this->actingAs($teacher)->post(route('courses.pin'), [
            'kind' => 'material', 'id' => $material->id,
        ])->assertForbidden();
        $this->actingAs($teacher)->postJson(route('courses.reorder'), [
            'kind' => 'material', 'ids' => [$material->id],
        ])->assertStatus(403);
    }

    public function test_student_refusals_and_old_route_redirects()
    {
        $student = $this->student();
        $sr = $this->studentRecord();
        $subject = $this->prepEnglish($sr);

        // Another class's course refuses.
        $foreignSubject = Subject::where('my_class_id', '!=', $sr->my_class_id)->firstOrFail();
        $this->actingAs($student)->get(route('courses.show', $foreignSubject->id))->assertForbidden();
        // Students never pin.
        $this->actingAs($student)->post(route('courses.pin'), [
            'kind' => 'material', 'id' => 1,
        ])->assertForbidden();

        // Retired lists redirect into the course surface.
        $this->actingAs($student)->get(route('assignments.index'))
            ->assertRedirect(route('courses.index'));
        $this->actingAs($student)->get(route('materials.index'))
            ->assertRedirect(route('courses.index'));

        $loc = $this->actingAs($student)
            ->get(route('assignments.index', ['subject_id' => $subject->id]))
            ->headers->get('Location');
        $this->assertStringContainsString('/courses/' . $subject->id, $loc);
        $this->assertStringEndsWith('#assignments', $loc);

        $loc = $this->actingAs($student)
            ->get(route('materials.index', ['subject_id' => $subject->id]))
            ->headers->get('Location');
        $this->assertStringContainsString('/courses/' . $subject->id, $loc);
        $this->assertStringEndsWith('#materials', $loc);
    }

    public function test_substitute_reads_course_read_only_and_cannot_write()
    {
        $sr = $this->studentRecord();
        $record = SectionTeacher::recordForClassSection(
            $sr->my_class_id, $sr->section_id, Qs::getCurrentSession());
        $this->assertNotNull($record);

        $maths = Subject::where('my_class_id', $sr->my_class_id)
            ->where('name', 'Mathematics')->firstOrFail();
        $cell = TimeTable::where('ttr_id', $record->id)
            ->where('subject_id', $maths->id)->firstOrFail();
        $absent = SectionTeacher::teacherFor($sr->section_id, $maths);
        $this->assertNotNull($absent);

        $substitute = User::where('user_type', 'teacher')
            ->whereNotIn('id', [$absent->id, $this->teacher()->id])
            ->firstOrFail();

        Substitution::create([
            'time_table_id' => $cell->id,
            'date' => Carbon::today()->toDateString(),
            'absent_teacher_id' => $absent->id,
            'substitute_teacher_id' => $substitute->id,
            'created_by' => $this->user('admin@hackstra.app')->id,
            'status' => 'confirmed',
        ]);
        $this->assertTrue(SubstitutionAccess::coversSubjectOn(
            (int) $substitute->id, (int) $sr->my_class_id, (int) $maths->id, now()),
            'premise: confirmed cover must grant subject access today');

        $response = $this->actingAs($substitute)->get(
            route('courses.show', ['subject' => $maths->id, 'section' => $sr->section_id]));
        $response->assertOk();
        $response->assertSee('read-only');
        $response->assertDontSee('Post notice');
        $response->assertDontSee('course-pin-form', false);

        // Read-only means read-only: no notice, no pin.
        $this->actingAs($substitute)->post(route('courses.notices.store', $maths->id), [
            'section_id' => $sr->section_id,
            'body' => 'LMSNOTE Substitute forgery',
        ])->assertForbidden();
        $material = StudyMaterial::where('subject_id', $maths->id)->first();
        if ($material) {
            $this->actingAs($substitute)->post(route('courses.pin'), [
                'kind' => 'material', 'id' => $material->id,
            ])->assertForbidden();
        }
    }
}
