<?php

namespace Tests\Feature;

use App\Models\MyClass;
use App\Models\Section;
use App\Models\Subject;
use App\Models\TimeSlot;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\Helpers\Qs;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Agent D round 19 — double-warning RENDERING + truncation.
 *
 * Round 18 proved the session-flash STRING (cap 1 + needs_double
 * -> 'Double period not possible: <Class> — Science (double
 * period could not be formed)'). What was never proven is the
 * RENDERED outcome and the multi-class shape:
 *
 *  (a) The generate landing page (tt.index) renders the exact
 *      double-warning toast — not just a session value nobody
 *      sees. Toast assertions target the exact flash({...})
 *      call (partials/js/custom_js), per the round-11 lesson:
 *      bare assertSee on generic words is vacuous against the
 *      layout's AJAX handler text. The em-dash passes through
 *      Blade escaping unescaped; there are no apostrophes in
 *      this message to trip over.
 *  (b) A scope=all run with needs_double subjects in SIX fresh
 *      classes under cap 1 truncates the flash to the first 4
 *      warnings + '; and 2 more' (line 920 builds exactly that).
 *      Seeded classes cannot pollute the count: needs_double
 *      defaults false and no seeder sets it, so every warning
 *      in the flash comes from a fresh class, in class-id
 *      (= creation) order.
 *  (c) A MIXED scope=all run under cap 2: one class pairs (no
 *      warning) while another class — whose double teacher
 *      carries one kept cell per day from a record NOT being
 *      rebuilt (a section record for an untagged section, which
 *      scope=all never targets) — cannot pair and warns alone.
 *      The kept-load trick mirrors round 15, extended to all
 *      six days so the cap-2 headroom is exactly 1/day.
 *
 * All fixtures fresh and rolled back.
 */
class OwnerTimetableDoubleWarningRenderRound19Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function teacher(string $tag): User
    {
        return User::create([
            'name' => 'R19 Teacher ' . $tag,
            'email' => 'r19-' . strtolower($tag) . '-' . uniqid() . '@test.local',
            'password' => bcrypt('teacher'),
            'user_type' => 'teacher',
            'code' => 'R19' . $tag . uniqid(),
        ]);
    }

    /** Six subjects x 6/week, Science marked needs_double. */
    protected function makeSixSubjectClass(string $name): array
    {
        $class = MyClass::create(['name' => $name]);
        $doubleId = null;
        foreach (['English', 'Mathematics', 'Science', 'Social Science', 'Hindi', 'Computer Science'] as $i => $subName) {
            $sub = Subject::create([
                'name' => $subName,
                'slug' => 'r19-' . $i . '-' . uniqid(),
                'my_class_id' => $class->id,
                'teacher_id' => $this->teacher($name . $i)->id,
                'periods_per_week' => 6,
                'needs_double' => $subName === 'Science',
            ]);
            if ($subName === 'Science') {
                $doubleId = $sub->id;
            }
        }

        return [$class, $doubleId];
    }

    protected function generateOne(int $classId, $cap): \Illuminate\Testing\TestResponse
    {
        $response = $this->actingAs($this->admin())
            ->post(route('tt.generate'), [
                'start_time' => '09:00', 'period_minutes' => 40, 'periods_per_day' => 6,
                'lunch_after' => 4, 'lunch_minutes' => 45, 'break_after' => 2, 'break_minutes' => 15,
                'days' => 6, 'scope' => 'one', 'class_id' => $classId, 'mode' => 'replace',
                'max_teacher_periods_per_day' => $cap,
            ]);
        $response->assertRedirect(route('tt.index'));

        return $response;
    }

    protected function generateAll($cap): \Illuminate\Testing\TestResponse
    {
        $response = $this->actingAs($this->admin())
            ->post(route('tt.generate'), [
                'start_time' => '09:00', 'period_minutes' => 40, 'periods_per_day' => 6,
                'lunch_after' => 4, 'lunch_minutes' => 45, 'break_after' => 2, 'break_minutes' => 15,
                'days' => 6, 'scope' => 'all', 'mode' => 'replace',
                'max_teacher_periods_per_day' => $cap,
            ]);
        $response->assertRedirect(route('tt.index'));

        return $response;
    }

    /** Follow a redirect response back to its target page. */
    protected function follow($resp)
    {
        $resp->assertRedirect();

        return $this->actingAs($this->admin())->get($resp->headers->get('Location'));
    }

    protected function recordFor(int $classId): TimeTableRecord
    {
        return TimeTableRecord::where('my_class_id', $classId)
            ->whereNull('exam_id')->whereNull('section_id')->firstOrFail();
    }

    /**
     * Adjacent pairs for one subject: per day, order ALL cells by
     * time; a pair = two of the subject's cells at consecutive
     * slot positions in that day's list (round-17 method).
     */
    protected function pairsFor(int $recordId, int $subjectId): array
    {
        $pairs = [];
        for ($day = 1; $day <= 6; $day++) {
            $cells = TimeTable::where('ttr_id', $recordId)
                ->where('day_num', $day)->orderBy('timestamp_from')->get();
            $positions = [];
            foreach ($cells as $pos => $cell) {
                if ((int) $cell->subject_id === $subjectId) {
                    $positions[] = $pos;
                }
            }
            for ($i = 0; $i + 1 < count($positions); $i++) {
                if ($positions[$i + 1] === $positions[$i] + 1) {
                    $pairs[] = $day;
                }
            }
        }

        return $pairs;
    }

    public function test_landing_page_renders_double_warning_toast()
    {
        $name = 'R19 Toast ' . uniqid();
        [$class, $doubleId] = $this->makeSixSubjectClass($name);
        $resp = $this->generateOne($class->id, 1);
        $record = $this->recordFor($class->id);

        // Reality first: cap 1 blocks the pair, target still met.
        $this->assertCount(0, $this->pairsFor($record->id, $doubleId),
            'cap 1 must block the pair so the warning has something true to say');
        $this->assertSame(6, TimeTable::where('ttr_id', $record->id)
            ->where('subject_id', $doubleId)->count(), 'target must still be met via singles');

        // Session flash carries the warning (round-18 proof, kept).
        $flash = session('flash_success') ?? '';
        $this->assertStringContainsString('Double period not possible', $flash);
        $this->assertStringContainsString($name . ' — Science (double period could not be formed)', $flash,
            "flash must name the class and subject; got: $flash");

        // Rendered outcome: the landing page's toast call carries
        // the warning verbatim, ending the message (the double
        // clause is the last clause before the final period).
        $this->follow($resp)->assertSee(
            "Double period not possible: {$name} — Science (double period could not be formed).', type : 'success'",
            false);
    }

    public function test_scope_all_truncates_double_warnings_to_four_plus_more()
    {
        $names = [];
        $doubles = [];
        for ($i = 1; $i <= 6; $i++) {
            $name = 'R19 Trunc ' . $i . ' ' . uniqid();
            [$class, $doubleId] = $this->makeSixSubjectClass($name);
            $names[] = $name;
            $doubles[] = [$class, $doubleId];
        }
        $this->generateAll(1);
        $flash = session('flash_success') ?? '';

        // All six warnings exist in the run's summary, but the
        // flash shows the first four + '; and 2 more' — and the
        // truncated pair (5th, 6th) must appear NOWHERE in the
        // flash: scope=all headlines are counts, not names, and
        // these fresh classes are generated (not skipped, kept,
        // or short), so their names occur only in warnings.
        $this->assertStringContainsString('Double period not possible:', $flash);
        $positions = [];
        foreach (array_slice($names, 0, 4) as $shown) {
            $this->assertStringContainsString($shown . ' — Science (double period could not be formed)', $flash,
                "first four warnings must render; flash: $flash");
            $pos = strpos($flash, $shown);
            $this->assertNotFalse($pos, "warning for $shown must be present in flash");
            $positions[] = $pos;
        }
        for ($i = 0; $i + 1 < count($positions); $i++) {
            $this->assertLessThan($positions[$i + 1], $positions[$i],
                'warnings must render in creation (class-id) order');
        }
        $this->assertStringContainsString('; and 2 more', $flash,
            "six warnings must truncate to four + '; and 2 more'; flash: $flash");
        // Pin the truncation to the DOUBLE clause specifically:
        // a cap-1 scope=all run legitimately starves seeded
        // section grids too (shared teachers hit cap 1 everywhere
        // — 665 capped empties, 90+ shortfalls), so a shortfalls
        // clause with its own '; and N more' may also be present.
        // The double clause is the message's last clause, so slice
        // from its marker to the end and count inside it only.
        $start = strpos($flash, 'Double period not possible: ');
        $this->assertNotFalse($start, 'double-warning clause must be present');
        $clause = substr($flash, $start);
        $this->assertSame(4, substr_count($clause, 'double period could not be formed'),
            "double clause must hold exactly four warnings; clause: $clause");
        $this->assertStringEndsWith('; and 2 more.', $clause,
            "double clause must end with the truncation count; clause: $clause");
        $this->assertStringNotContainsString($names[4], $flash, '5th class name must be truncated away');
        $this->assertStringNotContainsString($names[5], $flash, '6th class name must be truncated away');

        // Reality check at both ends: first and last classes both
        // genuinely unpaired under cap 1.
        foreach ([0, 5] as $idx) {
            [$class, $doubleId] = $doubles[$idx];
            $record = $this->recordFor($class->id);
            $this->assertCount(0, $this->pairsFor($record->id, $doubleId),
                "class {$names[$idx]} must have zero pairs under cap 1");
        }
    }

    public function test_mixed_run_warns_only_for_capped_unpaired_class()
    {
        // Class P: pairs under cap 2 (round-18 control).
        $pairName = 'R19 Pair ' . uniqid();
        [$pairClass, $pairDouble] = $this->makeSixSubjectClass($pairName);

        // Class U: its Science teacher T carries one kept cell per
        // day from a section record that scope=all never targets
        // (the section is untagged, so class K yields only its
        // class-grid target). Kept cells sit at 4:00–4:40 PM,
        // outside every generated slot, so busy overlap cannot
        // explain any refusal — only the cap can.
        $keptName = 'R19 Kept ' . uniqid();
        $classK = MyClass::create(['name' => $keptName]);
        $teacherK = $this->teacher('KEPTK');
        Subject::create([
            'name' => 'Kept Other', 'slug' => 'r19-keptk-' . uniqid(),
            'my_class_id' => $classK->id, 'teacher_id' => $teacherK->id, 'periods_per_week' => 6,
        ]);
        $sectionK = Section::create(['name' => 'R19 Sec', 'my_class_id' => $classK->id, 'active' => 1]);
        $year = Qs::getCurrentSession();

        $unpairedName = 'R19 Unpaired ' . uniqid();
        [$unpairedClass, $unpairedDouble] = $this->makeSixSubjectClass($unpairedName);
        // The Science teacher of class U is the kept-load teacher T.
        // The kept subject itself must NOT live in class K: class
        // K's class grid would then place T as well and corrupt
        // the load math. Instead the kept subject lives in a host
        // class X (taught there by a distinct teacher) and reaches
        // T only through a section override on section K — the
        // preload resolves kept cells through teacherIdFor, which
        // honors the override.
        $teacherT = User::where('id', Subject::find($unpairedDouble)->teacher_id)->firstOrFail();
        $classX = MyClass::create(['name' => 'R19 KeptHost ' . uniqid()]);
        $keptSubject = Subject::create([
            'name' => 'R19 Kept Subject', 'slug' => 'r19-keptsubj-' . uniqid(),
            'my_class_id' => $classX->id, 'teacher_id' => $this->teacher('KEPTHOST')->id,
            'periods_per_week' => 6,
        ]);
        \Illuminate\Support\Facades\DB::table('section_subject_teachers')->insert([
            'section_id' => $sectionK->id, 'subject_id' => $keptSubject->id, 'teacher_id' => $teacherT->id,
        ]);
        $recordK = TimeTableRecord::create([
            'name' => 'R19 Kept ' . uniqid(), 'my_class_id' => $classK->id,
            'section_id' => $sectionK->id, 'exam_id' => null, 'year' => $year,
        ]);
        $dayNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        foreach ($dayNames as $idx => $dayName) {
            $dayNum = $idx + 1;
            // 2026-11-02 is a Monday (round-15 shape).
            $ts = strtotime('2026-11-02 16:00:00') + ($dayNum - 1) * 86400;
            $slot = TimeSlot::create([
                'ttr_id' => $recordK->id,
                'hour_from' => 4, 'min_from' => '00', 'meridian_from' => 'PM',
                'hour_to' => 4, 'min_to' => '40', 'meridian_to' => 'PM',
                'time_from' => '4:00 PM', 'time_to' => '4:40 PM',
                'timestamp_from' => $ts, 'timestamp_to' => $ts + 2400,
                'full' => '4:00 PM - 4:40 PM',
            ]);
            TimeTable::create([
                'ttr_id' => $recordK->id, 'ts_id' => $slot->id,
                'day' => $dayName, 'day_num' => $dayNum, 'subject_id' => $keptSubject->id,
                'timestamp_from' => $ts, 'timestamp_to' => $ts + 2400,
            ]);
        }

        $this->generateAll(2);
        $flash = session('flash_success') ?? '';

        // Exactly one double warning, naming U only.
        $this->assertStringContainsString(
            $unpairedName . ' — Science (double period could not be formed)', $flash,
            "mixed run must warn for the capped-unpaired class; flash: $flash");
        $this->assertSame(1, substr_count($flash, 'double period could not be formed'),
            "exactly one class may warn; flash: $flash");

        // Reality: P pairs, U does not — and U still meets target
        // via singles (1 kept + 1 placed per day = cap 2).
        $pairRecord = $this->recordFor($pairClass->id);
        $this->assertCount(1, $this->pairsFor($pairRecord->id, $pairDouble),
            'paired class must form exactly one pair under cap 2');
        $unpairedRecord = $this->recordFor($unpairedClass->id);
        $this->assertCount(0, $this->pairsFor($unpairedRecord->id, $unpairedDouble),
            'kept-loaded class must form zero pairs under cap 2');
        $this->assertSame(6, TimeTable::where('ttr_id', $unpairedRecord->id)
            ->where('subject_id', $unpairedDouble)->count(),
            'unpaired class must still meet its 6/week target via singles');
    }
}
