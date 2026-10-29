<?php

namespace Tests\Feature;

use App\Models\TimeTableRecord;
use App\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Timetable print ghost-class guard (narrow round).
 *
 * TimeTableController::recordPrintData() sets $d['my_class'] =
 * MyClass::find($ttr->my_class_id), which is null when the class is
 * gone but the timetable record remains. print.blade.php line 110
 * used to read $my_class->name unguarded, so ttr.print on such a
 * record 500'd. The blade now uses the same guard the show blade
 * already uses: optional($my_class)->name ?: 'Deleted class'.
 *
 * Probe rows live ONLY in the disposable snapshot DB this run is
 * executed against (DB_DATABASE pointed at a /tmp copy); tearDown
 * deletes the probe record, and the snapshot itself is deleted
 * after the run. Never run this against the shared database.sqlite.
 */
class OwnerTimetablePrintGhostClassTest extends TestCase
{
    protected const PROBE_NAME = 'Probe Ghost Print Class';

    protected function tearDown(): void
    {
        TimeTableRecord::where('name', self::PROBE_NAME)->delete();
        parent::tearDown();
    }

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    public function test_normal_timetable_print_still_renders()
    {
        $record = TimeTableRecord::orderBy('id')->first();
        $this->assertNotNull($record, 'snapshot must contain at least one timetable record');
        $className = optional($record->my_class)->name;
        $this->assertNotEmpty($className);

        $print = $this->actingAs($this->admin())->get(route('ttr.print', $record->id));
        $print->assertOk();
        $this->assertStringContainsString(strtoupper($className), $print->getContent());
        $this->assertStringContainsString((string) $record->year, $print->getContent());
    }

    public function test_ghost_class_record_print_renders_instead_of_500()
    {
        // A record whose class no longer exists. The FK on
        // time_table_records.my_class_id is ON DELETE CASCADE, so a
        // normal class delete can never leave such a row behind —
        // this state only arises from a bypassed/legacy delete, which
        // is exactly what the guard must survive. Build it the same
        // way: FK enforcement off, orphan row in, enforcement on.
        DB::statement('PRAGMA foreign_keys=OFF');
        try {
            $ghost = TimeTableRecord::create([
                'name' => self::PROBE_NAME,
                'my_class_id' => 999999,
                'section_id' => null,
                'exam_id' => null,
                'year' => '2099-2100',
            ]);
        } finally {
            DB::statement('PRAGMA foreign_keys=ON');
        }
        $this->assertNull($ghost->fresh()->my_class, 'probe record must have no class');

        $print = $this->actingAs($this->admin())->get(route('ttr.print', $ghost->id));
        $print->assertOk();
        $html = $print->getContent();
        $this->assertStringContainsString('DELETED CLASS', $html);
        $this->assertStringContainsString('2099-2100', $html);
        // The titleband renders fully uppercased by design
        // (strtoupper in print.blade.php), so the section label
        // arrives as ALL SECTIONS — same style as the class name.
        $this->assertStringContainsString('ALL SECTIONS', $html);
    }
}
