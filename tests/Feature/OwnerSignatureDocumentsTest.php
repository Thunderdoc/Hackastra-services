<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\TimeTable;
use App\Support\SubjectColor;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Signature documents (owner order 2026-10-29 night — the
 * school's papers must be fixed properly and look like nobody
 * else's): the class timetable print names the ROOM (the screen
 * grid has since T3; the print never got it), every subject
 * wears its stable colour chip with a legend, section records
 * name their class teacher; the teacher's week print carries
 * the same chips; the exam date sheet carries a papers summary
 * and the generic instructions strip; and the Manage page shows
 * a schedule health line before anyone publishes.
 */
class OwnerSignatureDocumentsTest extends TestCase
{
    use DatabaseTransactions;

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    public function test_class_timetable_print_names_room_legend_and_class_teacher()
    {
        $room = Room::create(['name' => 'Room 21', 'kind' => 'classroom', 'capacity' => 40]);
        $cell = TimeTable::where('ttr_id', 25)->whereNotNull('subject_id')->firstOrFail();
        $cell->update(['room_id' => $room->id]);
        $subjectName = $cell->subject->name;

        $sectionTeacher = User::findOrFail(
            (int) DB::table('sections')->where('id', 68)->value('teacher_id')
        );

        $resp = $this->actingAs($this->admin())->get(route('ttr.print', 25));
        $resp->assertOk();
        $resp->assertSee('Room 21');
        $resp->assertSee('tt-chip', false);
        $resp->assertSee('background: ' . SubjectColor::forName($subjectName), false);
        $resp->assertSee('Class Teacher:');
        $resp->assertSee($sectionTeacher->name);
    }

    public function test_teacher_week_print_carries_subject_colour_chips()
    {
        $ananya = User::where('email', 'teacher@hackstra.app')->firstOrFail();
        // tt 788: Class 12-E's Monday period, effectively hers —
        // its subject (seed name "English Language") must appear
        // in her week wearing its own colour.
        $subjectName = TimeTable::findOrFail(788)->subject->name;

        $resp = $this->actingAs($ananya)->get(route('tt.mine_print'));
        $resp->assertOk();
        $resp->assertSee('tt-chip', false);
        $resp->assertSee($subjectName);
        $resp->assertSee('background: ' . SubjectColor::forName($subjectName), false);
    }

    protected function seedTwoPrepDates(): void
    {
        $now = now();
        foreach ([[1, '2026-11-02'], [2, '2026-11-03']] as [$subjectId, $date]) {
            DB::table('exam_dates')->insert([
                'exam_id' => 1,
                'my_class_id' => 1,
                'subject_id' => $subjectId,
                'exam_date' => $date,
                'start_time' => '09:00:00',
                'note' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function test_date_sheet_print_carries_summary_instructions_and_watermark()
    {
        $this->seedTwoPrepDates();

        $resp = $this->actingAs($this->admin())
            ->get(route('exam_dates.print', ['exam_id' => 1, 'class_id' => 1]));
        $resp->assertOk();
        $resp->assertSee('Papers:');
        $resp->assertSee('First exam:');
        $resp->assertSee('Mon, 02 Nov 2026');
        $resp->assertSee('Last exam:');
        $resp->assertSee('Tue, 03 Nov 2026');
        $resp->assertSee('Instructions');
        $resp->assertSee('admit card');
        $resp->assertSee('ds-wrap', false);
    }

    public function test_manage_page_shows_schedule_health_line()
    {
        $this->seedTwoPrepDates();

        $resp = $this->actingAs($this->admin())
            ->get(route('exam_dates.manage', ['exam_id' => 1, 'class_id' => 1]));
        $resp->assertOk();
        $resp->assertSee('Schedule looks clean:');
        $resp->assertSee('2 of 6 papers have dates');
        $resp->assertSee('no two papers share a date and time');
    }
}
