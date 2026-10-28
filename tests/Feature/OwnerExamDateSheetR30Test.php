<?php

namespace Tests\Feature;

use App\Models\ExamDate;
use App\Models\MyClass;
use App\Models\Subject;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Batch 2026-10-28 (exam-timetable round 30) — the Manage-page
 * completion path for the round-29 skip seam. Round 29 proved
 * mode=skip is CLASS-LEVEL: a partially-scheduled class gains
 * its missing subject only via mode=replace (which rewrites
 * hand-kept dates/notes) or via the Manage page. This suite
 * test-locks the Manage claim through the real
 * exam_dates.save POST (route exams/date-sheet/manage, form
 * shape dates[subject_id][exam_date|start_time|note]):
 *
 * (1) A full-form Manage save that re-submits the 2 hand-kept
 * rows unchanged and adds the missing subject's date leaves
 * the kept rows business-identical (same row id, date, time,
 * note) and creates exactly the 1 new row — no rewrite, no
 * drop, no duplicate. (updateOrCreate may refresh
 * updated_at on the re-submitted rows; byte-identity here
 * means id + business fields, stated openly.)
 * (2) A Manage save that moves a kept row onto another kept
 * row's date+time is refused with the round-11/23 clash
 * message, and stored rows stay byte-identical — the clash
 * guard holds through the Manage path, not just generate.
 * (3) A partial-form save posting ONLY the missing subject
 * leaves kept rows alone entirely (code: "Subject not in
 * the form — leave it alone"), proving Manage can be used
 * surgically. This is the code-level property behind the
 * full-form result, not the browser form's shape (the real
 * form posts every subject).
 *
 * Row-level skip is deliberately NOT built here: it stays
 * an owner call (round 29). Manage is the confirmed
 * workaround until that call.
 */
class OwnerExamDateSheetR30Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function makeClassWithSubjects($name, array $subjects, $slugPrefix)
    {
        $class = MyClass::create(['name' => $name]);
        $created = [];
        foreach ($subjects as $i => $subjectName) {
            $created[] = Subject::create([
                'name' => $subjectName,
                'my_class_id' => $class->id,
                'teacher_id' => 4,
                'slug' => $slugPrefix . '-' . $class->id . '-' . ($i + 1),
                'periods_per_week' => 6,
            ]);
        }
        return [$class, $created];
    }

    protected function rowMap($classId)
    {
        return ExamDate::where('exam_dates.exam_id', 1)->where('exam_dates.my_class_id', $classId)
            ->join('subjects', 'subjects.id', '=', 'exam_dates.subject_id')
            ->orderBy('subjects.name')
            ->get(['subjects.name as subject', 'exam_dates.id', 'exam_dates.exam_date', 'exam_dates.start_time', 'exam_dates.note'])
            ->mapWithKeys(function ($r) {
                return [$r->subject => [$r->id, $r->exam_date, substr((string) $r->start_time, 0, 5), $r->note]];
            })->all();
    }

    protected function seedKeptRows($class, $subjects)
    {
        ExamDate::create(['exam_id' => 1, 'my_class_id' => $class->id, 'subject_id' => $subjects[0]->id,
            'exam_date' => '2026-11-02', 'start_time' => '09:00:00', 'note' => 'R30-KEPT-A']);
        ExamDate::create(['exam_id' => 1, 'my_class_id' => $class->id, 'subject_id' => $subjects[1]->id,
            'exam_date' => '2026-11-03', 'start_time' => '13:00:00', 'note' => 'R30-KEPT-B']);
    }

    public function test_manage_save_adds_missing_subject_and_keeps_hand_kept_rows_identical()
    {
        ExamDate::where('exam_id', 1)->delete();
        [$class, $subjects] = $this->makeClassWithSubjects(
            'R30 Manage Class', ['R30-MANAGE-A', 'R30-MANAGE-B', 'R30-MANAGE-C'], 'r30-manage');
        $this->seedKeptRows($class, $subjects);
        $before = $this->rowMap($class->id);
        $this->assertCount(2, $before, 'Precondition: exactly the 2 hand-kept rows exist');

        $this->actingAs($this->admin());
        $resp = $this->post(route('exam_dates.save'), [
            'exam_id' => 1,
            'class_id' => $class->id,
            'dates' => [
                $subjects[0]->id => ['exam_date' => '2026-11-02', 'start_time' => '09:00', 'note' => 'R30-KEPT-A'],
                $subjects[1]->id => ['exam_date' => '2026-11-03', 'start_time' => '13:00', 'note' => 'R30-KEPT-B'],
                $subjects[2]->id => ['exam_date' => '2026-11-04', 'start_time' => '09:00', 'note' => 'R30-NEW-C'],
            ],
        ]);
        $resp->assertRedirect(route('exam_dates.manage', ['exam_id' => 1, 'class_id' => $class->id]));
        $resp->assertSessionHas('flash_success');
        $this->assertStringContainsString('3 date(s) set', session('flash_success'));

        $after = $this->rowMap($class->id);
        $this->assertCount(3, $after, 'Exactly one new row: the missing subject, nothing else');
        $this->assertSame($before['R30-MANAGE-A'], $after['R30-MANAGE-A'],
            'Kept row A must survive the Manage save with same id, date, time and note');
        $this->assertSame($before['R30-MANAGE-B'], $after['R30-MANAGE-B'],
            'Kept row B must survive the Manage save with same id, date, time and note');
        $this->assertSame('2026-11-04', $after['R30-MANAGE-C'][1]);
        $this->assertSame('09:00', $after['R30-MANAGE-C'][2]);
        $this->assertSame('R30-NEW-C', $after['R30-MANAGE-C'][3]);
    }

    public function test_manage_save_clash_is_refused_and_stored_rows_stay_identical()
    {
        ExamDate::where('exam_id', 1)->delete();
        [$class, $subjects] = $this->makeClassWithSubjects(
            'R30 Clash Class', ['R30-CLASH-A', 'R30-CLASH-B', 'R30-CLASH-C'], 'r30-clash');
        $this->seedKeptRows($class, $subjects);
        $before = $this->rowMap($class->id);

        $this->actingAs($this->admin());
        $resp = $this->post(route('exam_dates.save'), [
            'exam_id' => 1,
            'class_id' => $class->id,
            'dates' => [
                // Move A onto B's slot; B stays put. Post-save map
                // then holds two papers at 2026-11-03 13:00.
                $subjects[0]->id => ['exam_date' => '2026-11-03', 'start_time' => '13:00', 'note' => 'R30-KEPT-A'],
                $subjects[1]->id => ['exam_date' => '2026-11-03', 'start_time' => '13:00', 'note' => 'R30-KEPT-B'],
            ],
        ]);
        $resp->assertRedirect();
        $resp->assertSessionHas('flash_danger');
        $this->assertStringContainsString('Two papers cannot share one session:', session('flash_danger'));
        $this->assertStringContainsString('are both on 2026-11-03 at 13:00', session('flash_danger'));

        $this->assertSame($before, $this->rowMap($class->id),
            'A refused Manage save must write nothing: kept rows byte-identical, no new row');
    }

    public function test_manage_save_posting_only_the_missing_subject_leaves_kept_rows_alone()
    {
        ExamDate::where('exam_id', 1)->delete();
        [$class, $subjects] = $this->makeClassWithSubjects(
            'R30 Partial Class', ['R30-PARTIAL-A', 'R30-PARTIAL-B', 'R30-PARTIAL-C'], 'r30-partial');
        $this->seedKeptRows($class, $subjects);
        $before = $this->rowMap($class->id);

        $this->actingAs($this->admin());
        $resp = $this->post(route('exam_dates.save'), [
            'exam_id' => 1,
            'class_id' => $class->id,
            'dates' => [
                $subjects[2]->id => ['exam_date' => '2026-11-04', 'start_time' => '09:00', 'note' => 'R30-NEW-C'],
            ],
        ]);
        $resp->assertRedirect(route('exam_dates.manage', ['exam_id' => 1, 'class_id' => $class->id]));
        $resp->assertSessionHas('flash_success');
        $this->assertStringContainsString('1 date(s) set', session('flash_success'));

        $after = $this->rowMap($class->id);
        $this->assertCount(3, $after);
        $this->assertSame($before['R30-PARTIAL-A'], $after['R30-PARTIAL-A']);
        $this->assertSame($before['R30-PARTIAL-B'], $after['R30-PARTIAL-B']);
        $this->assertSame('2026-11-04', $after['R30-PARTIAL-C'][1]);
    }
}
