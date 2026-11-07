<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\MyClass;
use App\Models\Section;
use App\Models\Subject;
use App\Models\TeacherSlotPreference;
use App\Models\TimeSlot;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\Support\SectionTeacher;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Teacher slot preferences (owner ask 2026-11-06, audit gap G2:
 * "teacher ko sirf uske section ka slot hi choose karne ka option
 * aaye").
 *
 * Until now a teacher had NO slot surface at all — this feature
 * is new and scoped from birth:
 *
 * - the page lists ONLY her effective sections
 *   (SectionTeacher::unitSectionIdsByClass) as context;
 * - saves are hers alone: teacher-only at the route's controller
 *   guard, no user id in the URL, the Admin cannot edit them;
 * - at most MAX_PER_TEACHER (10) preferences; a bigger set is
 *   refused whole, nothing is saved;
 * - the generator consumes a preference as a SOFT tie-break
 *   only: on an exact placement-score tie it beats the cosmetic
 *   rotation, and it still loses to every hard rule (proven
 *   against a personal day cap). With no preferences saved, the
 *   generator's ordering is the pre-feature rotation ordering.
 *
 * Fixtures resolve users by EMAIL (a nurse seed user shifted the
 * generated ids); every test runs in a transaction, so saved
 * preferences and generated grids never leak into the shared
 * seed.
 */
class OwnerTeacherSlotPreferencesTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    private function ananya(): User
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    private function teacherB(): User
    {
        return User::where('email', 'teacher1@teacher.com')->firstOrFail();
    }

    private function keysOf(User $teacher): array
    {
        $keys = array_keys(TeacherSlotPreference::keysFor((int) $teacher->id));
        sort($keys);
        return $keys;
    }

    /* ---------------- The page: scoping ---------------- */

    public function test_page_lists_only_her_sections()
    {
        $ananya = $this->ananya();
        $byClass = SectionTeacher::unitSectionIdsByClass($ananya);
        $hers = [];
        foreach ($byClass as $classId => $sectionIds) {
            foreach ($sectionIds as $sectionId) {
                $hers[] = $sectionId;
            }
        }
        $this->assertNotEmpty($hers, 'fixture: Ananya must have effective sections');

        $html = $this->actingAs($ananya)->get(route('tt.slot_preferences'))
            ->assertOk()->getContent();

        // Exactly one chip per effective section — no more.
        // (Counted on the chip element itself: the bare string
        // "sp-chip" also matches the page's style block.)
        $this->assertSame(count($hers), substr_count($html, '<span class="sp-chip">'));

        // Her first section's chip is there...
        $first = Section::findOrFail($hers[0]);
        $this->assertStringContainsString($first->my_class->name . ' · ' . $first->name, $html);

        // ...and a section she does not teach in is nowhere on it.
        $foreign = Section::whereNotIn('id', $hers)->firstOrFail();
        $this->assertStringNotContainsString($foreign->my_class->name . ' · ' . $foreign->name, $html);
    }

    public function test_teacher_without_sections_sees_an_honest_empty_state()
    {
        $fresh = User::create([
            'name' => 'No Sections Teacher', 'email' => 'pref-empty@test.local',
            'password' => bcrypt('teacher'), 'user_type' => 'teacher', 'code' => 'PREFEMPTY',
        ]);

        $html = $this->actingAs($fresh)->get(route('tt.slot_preferences'))
            ->assertOk()->getContent();
        $this->assertStringContainsString('You have no sections yet', $html);
        $this->assertStringNotContainsString('name="slots[]"', $html);
    }

    public function test_teacher_menu_links_the_page_inside_academics()
    {
        $html = $this->actingAs($this->ananya())->get(route('dashboard'))
            ->assertOk()->getContent();

        $this->assertStringContainsString('My Slot Preferences', $html);
        $mine = strpos($html, route('tt.mine'));
        $prefs = strpos($html, route('tt.slot_preferences'));
        $whats = strpos($html, '#whatsapp-groups');
        $this->assertNotFalse($prefs);
        $this->assertTrue($mine < $prefs && $prefs < $whats,
            'My Slot Preferences sits inside Academics, after My Timetable and before WhatsApp Groups');
    }

    /* ---------------- Saving: hers alone, capped, replacing ---------------- */

    public function test_teacher_saves_preferences_and_reads_them_back()
    {
        $ananya = $this->ananya();

        $response = $this->actingAs($ananya)
            ->from(route('tt.slot_preferences'))
            ->post(route('tt.slot_preferences.update'), [
                'slots' => ['Monday|1', 'Wednesday|3', 'Friday|8'],
            ]);
        $response->assertRedirect(route('tt.slot_preferences'));
        $response->assertSessionHas('flash_success');
        $this->assertSame(['Friday|8', 'Monday|1', 'Wednesday|3'], $this->keysOf($ananya));

        $html = $this->actingAs($ananya)->get(route('tt.slot_preferences'))
            ->assertOk()->getContent();
        $this->assertStringContainsString('value="Monday|1" checked', $html);
        $this->assertStringContainsString('value="Friday|8" checked', $html);
        $this->assertStringNotContainsString('value="Tuesday|2" checked', $html);
    }

    public function test_saving_replaces_the_whole_set()
    {
        $ananya = $this->ananya();
        $this->actingAs($ananya)->post(route('tt.slot_preferences.update'), [
            'slots' => ['Monday|1', 'Monday|2'],
        ]);
        $this->actingAs($ananya)->post(route('tt.slot_preferences.update'), [
            'slots' => ['Thursday|5'],
        ]);
        $this->assertSame(['Thursday|5'], $this->keysOf($ananya));

        // Saving an empty set clears the preferences.
        $this->actingAs($ananya)->post(route('tt.slot_preferences.update'), ['slots' => []]);
        $this->assertSame([], $this->keysOf($ananya));
    }

    public function test_more_than_ten_preferences_are_refused_and_nothing_is_saved()
    {
        $ananya = $this->ananya();
        $eleven = [];
        foreach (['Monday', 'Tuesday'] as $day) {
            for ($p = 1; $p <= 6; $p++) {
                $eleven[] = $day . '|' . $p;
            }
        }
        $eleven = array_slice($eleven, 0, 11);
        $this->assertCount(11, $eleven);

        $response = $this->actingAs($ananya)
            ->from(route('tt.slot_preferences'))
            ->post(route('tt.slot_preferences.update'), ['slots' => $eleven]);
        $response->assertSessionHas('flash_danger');
        $this->assertSame([], $this->keysOf($ananya));
    }

    public function test_teacher_b_cannot_see_or_write_teacher_a_preferences()
    {
        $ananya = $this->ananya();
        $b = $this->teacherB();

        $this->actingAs($ananya)->post(route('tt.slot_preferences.update'), [
            'slots' => ['Monday|1'],
        ]);

        // B's page shows B's own (empty) set — A's tick is not
        // visible there, and there is no route that addresses A's
        // set at all.
        $html = $this->actingAs($b)->get(route('tt.slot_preferences'))
            ->assertOk()->getContent();
        $this->assertStringNotContainsString('value="Monday|1" checked', $html);

        // B saving her own set leaves A's untouched.
        $this->actingAs($b)->post(route('tt.slot_preferences.update'), [
            'slots' => ['Tuesday|2'],
        ]);
        $this->assertSame(['Tuesday|2'], $this->keysOf($b));
        $this->assertSame(['Monday|1'], $this->keysOf($ananya));
    }

    public function test_other_roles_are_refused()
    {
        $this->actingAs($this->admin())->get(route('tt.slot_preferences'))->assertForbidden();
        $this->actingAs($this->admin())->post(route('tt.slot_preferences.update'), [
            'slots' => ['Monday|1'],
        ])->assertForbidden();
        $this->assertSame([], $this->keysOf($this->admin()));

        $student = User::where('email', 'student@hackstra.app')->firstOrFail();
        $this->actingAs($student)->get(route('tt.slot_preferences'))->assertForbidden();

        $parent = User::where('email', 'parent@hackstra.app')->firstOrFail();
        $this->actingAs($parent)->get(route('tt.slot_preferences'))->assertForbidden();
    }

    /* ---------------- Generator: soft tie-break only ---------------- */

    private function freshTeacher(string $code): User
    {
        return User::create([
            'name' => 'Pref Teacher ' . $code,
            'email' => 'pref-' . strtolower($code) . '@test.local',
            'password' => bcrypt('teacher'),
            'user_type' => 'teacher',
            'code' => $code,
        ]);
    }

    /**
     * A fresh section-less class with exactly two subjects, one
     * per teacher, each with a hard weekly target of 1. On Monday
     * period 1 both candidates score identically (no kept load,
     * no doubles), so the slot is decided by the tie-break keys
     * alone — the smallest fixture in which a preference can be
     * observed. Subjects return in creation order (subjectOrder
     * 0 and 1, as the generator reads them).
     */
    private function tieFixture(User $teacherA, User $teacherB, string $code): array
    {
        $class = MyClass::create(['name' => 'Pref Class ' . $code]);
        $subjectA = Subject::create([
            'name' => 'Pref Subject A ' . $code, 'slug' => 'pref-sub-a-' . strtolower($code),
            'my_class_id' => $class->id, 'teacher_id' => $teacherA->id, 'periods_per_week' => 1,
        ]);
        $subjectB = Subject::create([
            'name' => 'Pref Subject B ' . $code, 'slug' => 'pref-sub-b-' . strtolower($code),
            'my_class_id' => $class->id, 'teacher_id' => $teacherB->id, 'periods_per_week' => 1,
        ]);

        return [$class, $subjectA, $subjectB];
    }

    /** Who the pre-feature rotation alone would put at Monday P1: [winner, loser] subjects. */
    private function rotationWinnerAndLoser(MyClass $class, Subject $first, Subject $second): array
    {
        // rot(s) on day 1 = (subjectOrder + rotBase) % 2, with
        // rotBase = (class id - 1) % 2 for a whole-class target;
        // the smaller rot wins an exact score tie.
        $rotBase = ((int) $class->id - 1) % 2;
        $rotFirst = (0 + $rotBase) % 2;
        $rotSecond = (1 + $rotBase) % 2;
        return $rotFirst < $rotSecond ? [$first, $second] : [$second, $first];
    }

    private function generateForClass(MyClass $class)
    {
        return $this->actingAs($this->admin())
            ->from(route('tt.generate.form'))
            ->post(route('tt.generate'), [
                'start_time' => '09:00',
                'period_minutes' => 40,
                'periods_per_day' => 6,
                'lunch_after' => 4,
                'lunch_minutes' => 45,
                'break_after' => 2,
                'break_minutes' => 15,
                'days' => 5,
                'scope' => 'one',
                'class_id' => $class->id,
                'mode' => 'skip',
                'max_teacher_periods_per_day' => 8,
            ]);
    }

    private function mondayFirstSubjectId(MyClass $class): int
    {
        $record = TimeTableRecord::where('my_class_id', $class->id)
            ->whereNull('section_id')->whereNull('exam_id')
            ->where('year', Qs::getCurrentSession())->firstOrFail();
        $firstSlot = TimeSlot::where('ttr_id', $record->id)
            ->orderBy('timestamp_from')->firstOrFail();
        $cell = TimeTable::where('ttr_id', $record->id)
            ->where('ts_id', $firstSlot->id)->where('day', 'Monday')->firstOrFail();
        return (int) $cell->subject_id;
    }

    public function test_generator_without_preferences_keeps_the_rotation_winner()
    {
        $teacherA = $this->freshTeacher('GN1');
        $teacherB = $this->freshTeacher('GN2');
        [$class, $subjectA, $subjectB] = $this->tieFixture($teacherA, $teacherB, 'GN');
        [$winner] = $this->rotationWinnerAndLoser($class, $subjectA, $subjectB);

        $response = $this->generateForClass($class);
        $response->assertSessionHas('flash_success');
        $this->assertSame((int) $winner->id, $this->mondayFirstSubjectId($class));
    }

    public function test_generator_preference_wins_an_exact_tie()
    {
        $teacherA = $this->freshTeacher('GP1');
        $teacherB = $this->freshTeacher('GP2');
        [$class, $subjectA, $subjectB] = $this->tieFixture($teacherA, $teacherB, 'GP');
        [$winner, $loser] = $this->rotationWinnerAndLoser($class, $subjectA, $subjectB);

        // The rotation LOSER's teacher asks for Monday period 1
        // through her own page — on the tie, her preference is
        // the only key left that can move the slot.
        $loserTeacher = (int) $loser->teacher_id === (int) $teacherA->id ? $teacherA : $teacherB;
        $this->actingAs($loserTeacher)->post(route('tt.slot_preferences.update'), [
            'slots' => ['Monday|1'],
        ])->assertSessionHas('flash_success');

        $response = $this->generateForClass($class);
        $response->assertSessionHas('flash_success');
        $this->assertSame((int) $loser->id, $this->mondayFirstSubjectId($class),
            'on an exact score tie the preferred slot beats the rotation');
    }

    public function test_generator_preference_loses_to_a_cap()
    {
        $teacherA = $this->freshTeacher('GC1');
        $teacherB = $this->freshTeacher('GC2');
        [$class, $subjectA, $subjectB] = $this->tieFixture($teacherA, $teacherB, 'GC');
        [$winner, $loser] = $this->rotationWinnerAndLoser($class, $subjectA, $subjectB);
        $loserTeacher = (int) $loser->teacher_id === (int) $teacherA->id ? $teacherA : $teacherB;

        // The loser asks for Monday period 1...
        $this->actingAs($loserTeacher)->post(route('tt.slot_preferences.update'), [
            'slots' => ['Monday|1'],
        ])->assertSessionHas('flash_success');

        // ...but she is already AT her personal day cap of 1 on
        // Monday, from one kept cell (15:00 — outside the
        // generated school day, so only the CAP can bind, never
        // the busy map). The preference must lose: the rotation
        // winner takes Monday period 1.
        $loserTeacher->update(['max_periods_per_day' => 1]);
        $keptClass = MyClass::create(['name' => 'Pref Kept Class GC']);
        $keptSubject = Subject::create([
            'name' => 'Pref Kept Subject GC', 'slug' => 'pref-kept-sub-gc',
            'my_class_id' => $keptClass->id, 'teacher_id' => $loserTeacher->id,
            'periods_per_week' => 6,
        ]);
        $record = TimeTableRecord::create([
            'name' => $keptClass->name . ' ' . Qs::getCurrentSession(),
            'my_class_id' => $keptClass->id,
            'year' => Qs::getCurrentSession(),
        ]);
        $proto = TimeSlot::firstOrFail();
        $slotAttrs = $proto->getAttributes();
        unset($slotAttrs['id']);
        $slotAttrs['ttr_id'] = $record->id;
        $slotAttrs['timestamp_from'] = strtotime('2026-01-05 15:00');
        $slotAttrs['timestamp_to'] = strtotime('2026-01-05 15:40');
        $keptSlot = TimeSlot::create($slotAttrs);
        TimeTable::create([
            'ttr_id' => $record->id,
            'ts_id' => $keptSlot->id,
            'subject_id' => $keptSubject->id,
            'day' => 'Monday',
            'day_num' => 1,
            'timestamp_from' => strtotime('2026-01-05 15:00'),
            'timestamp_to' => strtotime('2026-01-05 15:40'),
        ]);

        $response = $this->generateForClass($class);
        $response->assertSessionHas('flash_success');
        $this->assertSame((int) $winner->id, $this->mondayFirstSubjectId($class),
            'a preference never overrides a cap — the capped teacher is refused the slot she prefers');

        // Only the SLOT was refused: her subject still meets its
        // target of 1 on a later day.
        $recordIds = TimeTableRecord::where('my_class_id', $class->id)
            ->whereNull('exam_id')->where('year', Qs::getCurrentSession())->pluck('id');
        $this->assertSame(1, TimeTable::whereIn('ttr_id', $recordIds)
            ->where('subject_id', $loser->id)->count());
    }
}
