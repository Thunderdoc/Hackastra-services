<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\ExamDate;
use App\Models\ExamDuty;
use App\Models\MyClass;
use App\Models\Subject;
use App\Support\Invigilation;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Batch 2026-10-28 (exam-timetable round 36) — invigilation
 * AUTO-ASSIGN DETERMINISM: does re-running auto-assign on the
 * same sittings give the same teachers, or is the assignment
 * silently arbitrary?
 *
 * Code read first (app/Support/Invigilation.php):
 * - pool() orders by NAME (line 29) — deterministic pool order.
 * - autoAssign() orders sittings by exam_date, start_time,
 *   my_class_id (line 186) — deterministic sitting order.
 * - Candidates are filtered (no clash, no approved leave) and
 *   then ->sortBy(count) (lines 224-229). Laravel's sortBy is
 *   STABLE (asort-based): equal counts keep the pool's name
 *   order, so the fewest-duties tie-break is name order.
 * - The pick is the first NON-OWN-SUBJECT candidate among the
 *   least loaded (lines 241-243), and counts update in memory
 *   as sittings fill (line 265), so later sittings see the
 *   earlier picks — still fully deterministic.
 * - Sittings in the PAST are skipped (line 216), so every
 *   fixture sitting uses a FUTURE date (today is 2026-10-28;
 *   fixtures use December 2026).
 *
 * This suite proves the determinism empirically: run
 * autoAssign, delete the duties, run it again on the
 * identical sitting set, and assert the mappings are
 * IDENTICAL — plus the exact expected mapping (name-order
 * tie-break) so a future ordering change fails loudly
 * instead of silently reshuffling duties.
 *
 * Fixture rules: subjects carry teacher_id = the ADMIN id, so
 * NO pool teacher is the own-subject teacher (pool() is ALL
 * active teachers — there is no teacher outside the pool to
 * use instead), except test 3, which deliberately makes the
 * name-first pool teacher the subject teacher to prove the
 * own-subject preference leg. All fixtures live in
 * DatabaseTransactions and roll back; nothing touches the
 * shared DB.
 */
class OwnerExamDateSheetR36Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function makeClassWithSubjects($name, array $subjects, $slugPrefix, $teacherId)
    {
        $class = MyClass::create(['name' => $name]);
        $created = [];
        foreach ($subjects as $i => $subjectName) {
            $created[] = Subject::create([
                'name' => $subjectName,
                'my_class_id' => $class->id,
                'teacher_id' => $teacherId,
                'slug' => $slugPrefix . '-' . $class->id . '-' . ($i + 1),
                'periods_per_week' => 6,
            ]);
        }
        return [$class, $created];
    }

    protected function makeExam($name, $term)
    {
        return Exam::create([
            'name' => $name,
            'term' => $term,
            'year' => '2026-2027',
            'my_class_id' => 0,
        ]);
    }

    protected function plantSitting($examId, $classId, $subjectId, $date, $time = '09:00')
    {
        return ExamDate::create([
            'exam_id' => $examId,
            'my_class_id' => $classId,
            'subject_id' => $subjectId,
            'exam_date' => $date,
            'start_time' => $time,
            'note' => null,
        ]);
    }

    /** Sitting-date-ordered teacher mapping: [exam_date => teacher_id]. */
    protected function mappingFor($examId)
    {
        $map = [];
        foreach (ExamDate::where('exam_id', $examId)->orderBy('exam_date')->get() as $sitting) {
            $duty = ExamDuty::where('exam_date_id', $sitting->id)->first();
            $map[$sitting->exam_date] = $duty ? (int) $duty->teacher_id : null;
        }
        return $map;
    }

    public function test_equal_counts_assign_identically_across_two_runs()
    {
        $pool = Invigilation::pool();
        $this->assertGreaterThanOrEqual(6, $pool->count(), 'Pool must hold at least 6 eligible teachers.');
        $poolIds = $pool->pluck('id')->map(fn ($id) => (int) $id)->all();

        // No pool teacher is own-subject: subjects point at the admin.
        [$class, $subjects] = $this->makeClassWithSubjects(
            'R36 Det Class',
            ['R36-DET-A', 'R36-DET-B', 'R36-DET-C', 'R36-DET-D'],
            'r36-det',
            $this->admin()->id
        );
        $exam = $this->makeExam('R36 Det Exam', 'R36 Det Term');
        $dates = ['2026-12-01', '2026-12-02', '2026-12-03', '2026-12-04'];
        foreach ($dates as $i => $date) {
            $this->plantSitting($exam->id, $class->id, $subjects[$i]->id, $date);
        }

        $run1 = Invigilation::autoAssign($exam->id, $this->admin()->id);
        $this->assertSame(4, $run1['assigned']);
        $this->assertSame([], $run1['unfilled']);
        $map1 = $this->mappingFor($exam->id);
        $this->assertNotContains(null, array_values($map1), 'Every sitting must be filled.');
        $this->assertCount(4, array_unique(array_values($map1)), 'Equal counts + distinct dates = 4 distinct teachers.');

        // Exact mechanism: name-order tie-break — sitting i goes to
        // pool teacher i (counts start at 0 and increment per pick).
        $expected = [];
        foreach ($dates as $i => $date) {
            $expected[$date] = $poolIds[$i];
        }
        $this->assertSame($expected, $map1, 'Mapping must follow the pool name order exactly.');

        // Delete ONLY this exam's duties, re-run on identical sittings.
        ExamDuty::whereIn('exam_date_id', ExamDate::where('exam_id', $exam->id)->pluck('id'))->delete();
        $run2 = Invigilation::autoAssign($exam->id, $this->admin()->id);
        $this->assertSame(4, $run2['assigned']);
        $map2 = $this->mappingFor($exam->id);
        $this->assertSame($map1, $map2, 'Second run on identical sittings must reproduce the first mapping exactly.');
    }

    public function test_unequal_counts_still_prefer_fewest_and_repeat()
    {
        $pool = Invigilation::pool();
        $this->assertGreaterThanOrEqual(6, $pool->count());
        $poolIds = $pool->pluck('id')->map(fn ($id) => (int) $id)->all();
        $first = $poolIds[0];

        [$class, $subjects] = $this->makeClassWithSubjects(
            'R36 Uneq Class',
            ['R36-UNEQ-A', 'R36-UNEQ-B', 'R36-UNEQ-C', 'R36-UNEQ-D'],
            'r36-uneq',
            $this->admin()->id
        );
        $exam = $this->makeExam('R36 Uneq Exam', 'R36 Uneq Term');
        $dates = ['2026-12-07', '2026-12-08', '2026-12-09', '2026-12-10'];
        $sittings = [];
        foreach ($dates as $i => $date) {
            $sittings[] = $this->plantSitting($exam->id, $class->id, $subjects[$i]->id, $date);
        }

        // Unequal start: the name-first pool teacher already holds
        // this exam's first sitting (counts are PER EXAM, so the
        // pre-load must be on this exam, not another one).
        ExamDuty::create([
            'exam_date_id' => $sittings[0]->id,
            'teacher_id' => $first,
            'assigned_by' => $this->admin()->id,
        ]);

        $run1 = Invigilation::autoAssign($exam->id, $this->admin()->id);
        $this->assertSame(3, $run1['assigned'], 'The pre-assigned sitting is skipped, 3 remain.');
        $map1 = $this->mappingFor($exam->id);
        $this->assertSame($first, $map1['2026-12-07'], 'Pre-assigned sitting untouched.');
        // Fewest-first: the loaded teacher (count 1) is passed over
        // for every remaining sitting; the next three pool teachers
        // take them in name order.
        $this->assertSame($poolIds[1], $map1['2026-12-08']);
        $this->assertSame($poolIds[2], $map1['2026-12-09']);
        $this->assertSame($poolIds[3], $map1['2026-12-10']);
        $this->assertNotContains($first, [$map1['2026-12-08'], $map1['2026-12-09'], $map1['2026-12-10']]);

        // Delete ONLY the auto-created duties (keep the pre-load),
        // re-run: the remaining sittings must map identically.
        ExamDuty::whereIn('exam_date_id', [$sittings[1]->id, $sittings[2]->id, $sittings[3]->id])->delete();
        $run2 = Invigilation::autoAssign($exam->id, $this->admin()->id);
        $this->assertSame(3, $run2['assigned']);
        $map2 = $this->mappingFor($exam->id);
        $this->assertSame($map1, $map2, 'Second run must reproduce the first mapping, pre-load included.');
    }

    public function test_own_subject_preference_is_deterministic_too()
    {
        $pool = Invigilation::pool();
        $this->assertGreaterThanOrEqual(6, $pool->count());
        $poolIds = $pool->pluck('id')->map(fn ($id) => (int) $id)->all();

        // The name-first pool teacher IS this subject's teacher, so
        // the own-subject preference leg must skip her for the
        // first 0-count candidate who is not the subject teacher.
        [$class, $subjects] = $this->makeClassWithSubjects(
            'R36 Own Class',
            ['R36-OWN-A'],
            'r36-own',
            $poolIds[0]
        );
        $exam = $this->makeExam('R36 Own Exam', 'R36 Own Term');
        $sitting = $this->plantSitting($exam->id, $class->id, $subjects[0]->id, '2026-12-14');

        $run1 = Invigilation::autoAssign($exam->id, $this->admin()->id);
        $this->assertSame(1, $run1['assigned']);
        $map1 = $this->mappingFor($exam->id);
        $this->assertSame($poolIds[1], $map1['2026-12-14'], 'Own-subject teacher skipped for the next least-loaded teacher.');

        ExamDuty::where('exam_date_id', $sitting->id)->delete();
        $run2 = Invigilation::autoAssign($exam->id, $this->admin()->id);
        $this->assertSame(1, $run2['assigned']);
        $this->assertSame($map1, $this->mappingFor($exam->id), 'Own-subject preference repeats identically.');
    }
}
