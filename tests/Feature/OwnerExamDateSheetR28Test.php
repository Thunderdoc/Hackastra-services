<?php

namespace Tests\Feature;

use App\Models\ExamDate;
use App\Models\ExamDuty;
use App\Models\MyClass;
use App\Models\Subject;
use App\Support\SchoolLevel;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Batch 2026-10-28 (exam-timetable round 28) — two seams never
 * probed before:
 *
 * (a) CROSS-CLASS SAME-SLOT DOUBLE-BOOKING GUARD. Round 18
 * proved the guard within one exam's sittings: a teacher
 * assigned to sitting A is invisible to sitting B at the same
 * date + start time (hasClash() is a live query, and duties are
 * created inside the loop). But hasClash() filters on date +
 * start_time only — it never mentions class — so the cross-
 * class shape (two sittings, same slot, DIFFERENT classes)
 * was never proven. This round proves it: two future sittings
 * at 2026-11-09 09:00, one for Prep (class 1) and one for
 * Class 1 (class 2), go through the real exam_duties.auto
 * POST and must land on DIFFERENT teachers. If one teacher
 * held both duties, hasClash() would be scoped too narrowly
 * and that would be a REAL finding. It is not — the guard is
 * class-blind by construction, and the test locks exactly
 * that (duty rows + teacher ids, not just the flash count).
 *
 * (b) EARLY-YEARS NAME RULE, NEGATIVE SIDE. Round 1
 * test-locked the POSITIVE side only (Nursery, LKG, UKG,
 * KG-2, Pre-Primary → primary despite a late id, plus a
 * 'Class 13' senior → secondary). The docblock ALSO claims
 * "Preparatory Course" does NOT match — "Preparatory" must
 * not leak through the whole-word \bprep\b boundary — but no
 * test ever asserted it. This round test-locks the negative
 * side two ways: directly (levelForClassId + isEarlyYearsName
 * + levelsForClasses agreement) and end-to-end through the
 * real scope=all generate (same params as the round-1
 * generate test): a late-created "Preparatory Course" class
 * must share slots with secondary Class 10 and none with
 * primary Prep, while a late-created "KG-1" class must share
 * slots with Prep and none with Class 10. Note on the brief's
 * "periods/day" phrasing: the 6/8 periods_per_day numbers are
 * the TIMETABLE presets (tt_gen_primary / tt_gen_secondary),
 * not exam-sheet numbers — the exam generator's observable
 * level behavior is slot-group sharing (same-group classes
 * share a (day, session) slot; other-group slots are skipped),
 * which is what this test measures, exactly as round 1 did.
 */
class OwnerExamDateSheetR28Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function makeClassWithSubjects($name, array $subjects)
    {
        $class = MyClass::create(['name' => $name]);
        $created = [];
        foreach ($subjects as $i => $subjectName) {
            $created[] = Subject::create([
                'name' => $subjectName,
                'my_class_id' => $class->id,
                'teacher_id' => 4,
                'slug' => 'r28-' . $class->id . '-' . ($i + 1),
                'periods_per_week' => 6,
            ]);
        }
        return [$class, $created];
    }

    public function test_auto_assign_never_double_books_across_classes_at_same_slot()
    {
        $prepSubject = Subject::where('my_class_id', 1)->orderBy('id')->firstOrFail();
        $class1Subject = Subject::where('my_class_id', 2)->orderBy('id')->firstOrFail();

        $sittingPrep = ExamDate::create([
            'exam_id' => 1, 'my_class_id' => 1, 'subject_id' => $prepSubject->id,
            'exam_date' => '2026-11-09', 'start_time' => '09:00:00',
        ]);
        $sittingClass1 = ExamDate::create([
            'exam_id' => 1, 'my_class_id' => 2, 'subject_id' => $class1Subject->id,
            'exam_date' => '2026-11-09', 'start_time' => '09:00:00',
        ]);

        $this->actingAs($this->admin());
        $resp = $this->post(route('exam_duties.auto'), ['exam_id' => 1]);
        $resp->assertRedirect();
        $resp->assertSessionHas('flash_success', 'Auto-assignment complete: 2 sitting(s) got an invigilator.');

        $duties = ExamDuty::whereIn('exam_date_id', [$sittingPrep->id, $sittingClass1->id])->get();
        $this->assertCount(2, $duties, 'Both same-slot sittings must be filled');
        $teacherIds = $duties->pluck('teacher_id')->all();
        $this->assertCount(2, array_unique($teacherIds), 'No teacher may hold both same-slot duties across classes');
        $this->assertSame($sittingPrep->id, $duties->firstWhere('exam_date_id', $sittingPrep->id)->exam_date_id);
        $this->assertSame($sittingClass1->id, $duties->firstWhere('exam_date_id', $sittingClass1->id)->exam_date_id);
    }

    public function test_preparatory_course_is_secondary_and_kg1_is_primary()
    {
        [$prepCourse] = $this->makeClassWithSubjects('Preparatory Course', ['PC English', 'PC Number Work', 'PC Rhymes']);
        [$kg1] = $this->makeClassWithSubjects('KG-1', ['KG1 English', 'KG1 Number Work', 'KG1 Rhymes']);

        // Direct negative side: "Preparatory" must not trip \bprep\b.
        $this->assertFalse(SchoolLevel::isEarlyYearsName('Preparatory Course'));
        $this->assertTrue(SchoolLevel::isEarlyYearsName('KG-1'));
        $this->assertSame('secondary', SchoolLevel::levelForClassId($prepCourse->id), 'Preparatory Course must stay secondary despite the Prep-like name');
        $this->assertSame('primary', SchoolLevel::levelForClassId($kg1->id), 'KG-1 must be primary despite a late id');

        $levels = SchoolLevel::levelsForClasses(MyClass::whereIn('id', [$prepCourse->id, $kg1->id])->get());
        $this->assertSame('secondary', $levels[$prepCourse->id]);
        $this->assertSame('primary', $levels[$kg1->id]);

        // End-to-end through the real scope=all generate (round-1 params).
        ExamDate::where('exam_id', 1)->delete();
        $this->actingAs($this->admin());
        $resp = $this->post(route('exam_dates.generate'), [
            'exam_id' => 1,
            'scope' => 'all',
            'start_date' => '2026-12-01',
            'exam_days' => 10,
            'papers_per_day' => 2,
            'session1_time' => '09:00',
            'session2_time' => '13:00',
            'venue' => 'Main Hall',
            'skip_sunday' => '1',
            'gap_every' => 2,
            'gap_days' => 1,
            'skip_dates' => '2026-12-05',
            'mode' => 'replace',
        ]);
        $resp->assertRedirect();

        $slots = function ($classId) {
            return ExamDate::where('exam_id', 1)->where('my_class_id', $classId)
                ->get()->map(function ($r) { return $r->exam_date . '|' . substr((string) $r->start_time, 0, 5); })->all();
        };
        $pcSlots = $slots($prepCourse->id);
        $kg1Slots = $slots($kg1->id);
        $c10Slots = $slots(11);
        $prepSlots = $slots(1);
        $this->assertCount(3, $pcSlots);
        $this->assertCount(3, $kg1Slots);
        $this->assertNotEmpty(array_intersect($pcSlots, $c10Slots), 'Preparatory Course (secondary) must share slots with Class 10');
        $this->assertSame([], array_intersect($pcSlots, $prepSlots), 'Preparatory Course must share no slot with primary Prep');
        $this->assertNotEmpty(array_intersect($kg1Slots, $prepSlots), 'KG-1 (primary) must share slots with Prep');
        $this->assertSame([], array_intersect($kg1Slots, $c10Slots), 'KG-1 must share no slot with secondary Class 10');
    }
}
