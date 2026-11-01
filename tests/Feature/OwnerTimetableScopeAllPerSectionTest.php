<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\MyClass;
use App\Models\Section;
use App\Models\Subject;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Scope=all per-section expansion (owner direction 2026-10-30).
 *
 * Timetables are PER SECTION for every class. The generator's
 * scope=all used to expand only STREAMED classes into section
 * targets; a non-streamed class got ONE whole-class target. On the
 * seeded school — whose records are per-section throughout — a
 * scope=all skip run therefore found no sectionless record to
 * skip and built 11 duplicate whole-class grids beside the
 * section grids. These pins lock the corrected expansion:
 *
 *  (1) scope=all + skip on the seeded school creates ZERO new
 *      records (every section target finds its seeded record);
 *  (2) a class WITH sections expands to exactly one record per
 *      section, none sectionless;
 *  (3) a class with NO sections keeps its single whole-class
 *      record.
 *
 * Expectations are computed from live queries — no seed totals
 * are hardcoded. All fixtures fresh and rolled back.
 */
class OwnerTimetableScopeAllPerSectionTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    private function teacher(string $code): User
    {
        return User::create([
            'name' => 'PS Teacher ' . $code,
            'email' => 'ps-' . strtolower($code) . '-' . uniqid() . '@test.local',
            'password' => bcrypt('teacher'),
            'user_type' => 'teacher',
            'code' => 'PS' . $code . uniqid(),
        ]);
    }

    private function generateAll(string $mode)
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
                'scope' => 'all',
                'mode' => $mode,
            ]);
    }

    private function currentYearRecords()
    {
        return TimeTableRecord::whereNull('exam_id')
            ->where('year', Qs::getCurrentSession());
    }

    /** (1) The seeded school is fully per-section: skip creates nothing. */
    public function test_scope_all_skip_on_seeded_school_creates_no_records()
    {
        // Preconditions from live queries, so the pin cannot pass
        // vacuously: the seed really carries records, and classes
        // really have sections.
        $before = $this->currentYearRecords()->count();
        $this->assertGreaterThan(0, $before, 'seeded school must carry timetable records');
        $this->assertGreaterThan(0, Section::count(), 'seeded school must have sections');
        $beforeSectionless = $this->currentYearRecords()->whereNull('section_id')->count();

        $response = $this->generateAll('skip');
        $response->assertRedirect(route('tt.index'));

        $this->assertSame($before, $this->currentYearRecords()->count(),
            'scope=all skip must create zero new records on the per-section seeded school');
        $this->assertSame($beforeSectionless, $this->currentYearRecords()->whereNull('section_id')->count(),
            'scope=all must not add whole-class records for classes that have sections');
        $this->assertStringContainsString('No new timetables',
            (string) session('flash_success'),
            'a fully-skipped run must say so plainly');
    }

    /** (2) A sectioned class expands to one record per section — none sectionless. */
    public function test_scope_all_expands_sectioned_class_into_section_records_only()
    {
        $class = MyClass::create(['name' => 'PS Two Sections ' . uniqid()]);
        $secA = Section::create(['name' => 'A', 'my_class_id' => $class->id]);
        $secB = Section::create(['name' => 'B', 'my_class_id' => $class->id]);
        Subject::create([
            'name' => 'PS Subject', 'slug' => 'ps-sub-' . uniqid(),
            'my_class_id' => $class->id, 'teacher_id' => $this->teacher('SEC')->id,
            'periods_per_week' => 6,
        ]);

        $response = $this->generateAll('skip');
        $response->assertRedirect(route('tt.index'));

        $records = $this->currentYearRecords()->where('my_class_id', $class->id)->get();
        $this->assertCount(2, $records, 'one record per section, nothing else');
        $this->assertEqualsCanonicalizing(
            [(int) $secA->id, (int) $secB->id],
            $records->pluck('section_id')->map(fn ($v) => (int) $v)->all(),
            'both records must carry their section_id'
        );
        $this->assertSame(0, $records->whereNull('section_id')->count(),
            'no whole-class record may be built for a sectioned class');
        foreach ($records as $record) {
            $this->assertGreaterThan(0, TimeTable::where('ttr_id', $record->id)->count(),
                'each section grid must actually be built, not just registered');
        }
    }

    /** (3) A sectionless class keeps its single whole-class record. */
    public function test_scope_all_keeps_whole_class_target_for_sectionless_class()
    {
        $class = MyClass::create(['name' => 'PS No Sections ' . uniqid()]);
        Subject::create([
            'name' => 'PS Lone Subject', 'slug' => 'ps-lone-' . uniqid(),
            'my_class_id' => $class->id, 'teacher_id' => $this->teacher('LONE')->id,
            'periods_per_week' => 6,
        ]);
        $this->assertSame(0, Section::where('my_class_id', $class->id)->count(),
            'the fixture class must genuinely have no sections');

        $response = $this->generateAll('skip');
        $response->assertRedirect(route('tt.index'));

        $records = $this->currentYearRecords()->where('my_class_id', $class->id)->get();
        $this->assertCount(1, $records, 'a sectionless class still gets exactly one record');
        $this->assertNull($records->first()->section_id,
            'that record is the whole-class grid');
        $this->assertGreaterThan(0, TimeTable::where('ttr_id', $records->first()->id)->count(),
            'the whole-class grid must actually be built');
    }
}
