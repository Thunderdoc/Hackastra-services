<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\MyClass;
use App\Models\Section;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\Models\TimeSlot;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\Support\WeekDays;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Day-order pin (owner report 2026-11-07): the printed Prep A
 * timetable — and the on-screen View Timetable — rendered its day
 * rows Friday, Monday, Thursday, Tuesday, Wednesday.
 *
 * Root cause: show_record() and recordPrintData() built the day
 * list as $tts->unique('day') over getTimeTable(), which orders
 * cells by period start time — i.e. first-period encounter order,
 * not week order. Both builders (and recordWeekGrid) now pass the
 * names through App\Support\WeekDays::sortNames(), and the mobile
 * API flattens record grids in the grid's canonical day order.
 *
 * The fixture recreates the owner's exact encounter order: the
 * earliest period of the day belongs to Friday, then Monday,
 * Thursday, Tuesday, Wednesday.
 */
class OwnerTimetableDayOrderTest extends TestCase
{
    use DatabaseTransactions;

    protected const EXPECTED = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function slot(int $recordId, string $from, string $to): TimeSlot
    {
        return TimeSlot::create([
            'ttr_id' => $recordId,
            'hour_from' => (int) date('g', strtotime($from)),
            'min_from' => date('i', strtotime($from)),
            'meridian_from' => date('A', strtotime($from)),
            'hour_to' => (int) date('g', strtotime($to)),
            'min_to' => date('i', strtotime($to)),
            'meridian_to' => date('A', strtotime($to)),
            'time_from' => $from,
            'time_to' => $to,
            'timestamp_from' => strtotime($from),
            'timestamp_to' => strtotime($to),
            'full' => $from . ' - ' . $to,
        ]);
    }

    /**
     * A whole-class record whose cells are encountered (by period
     * start time, the order getTimeTable() returns) Friday first —
     * the owner's Prep A shape. Returns [record, section, subject].
     */
    protected function fridayFirstRecord(): array
    {
        $u = uniqid();
        $class = MyClass::create(['name' => 'DayOrder Class ' . $u]);
        $section = Section::create([
            'name' => 'DayOrderSec' . $u, 'my_class_id' => $class->id, 'active' => 1,
        ]);
        $teacher = User::create([
            'name' => 'DayOrder Teacher ' . $u,
            'email' => 'dayorder-teacher-' . $u . '@test.local',
            'password' => bcrypt('teacher'),
            'user_type' => 'teacher',
            'code' => 'DOT' . $u,
        ]);
        $subject = Subject::create([
            'name' => 'DayOrderSub' . $u, 'slug' => 'dayorder-sub-' . $u,
            'my_class_id' => $class->id, 'teacher_id' => $teacher->id,
            'periods_per_week' => 6, 'stream' => null,
        ]);
        $record = TimeTableRecord::create([
            'name' => 'DayOrder Timetable ' . $u,
            'my_class_id' => $class->id,
            'section_id' => null,
            'exam_id' => null,
            'year' => Qs::getCurrentSession(),
            'approval_status' => 'approved',
        ]);

        // Earliest period first: Friday, Monday, Thursday, Tuesday,
        // Wednesday — the exact encounter order of the owner's print.
        $encounter = ['Friday', 'Monday', 'Thursday', 'Tuesday', 'Wednesday'];
        $times = [['9:00 AM', '9:40 AM'], ['9:40 AM', '10:20 AM'], ['10:35 AM', '11:15 AM'],
            ['11:15 AM', '11:55 AM'], ['12:40 PM', '1:20 PM']];
        $dayNum = ['Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3, 'Thursday' => 4,
            'Friday' => 5, 'Saturday' => 6, 'Sunday' => 7];
        foreach ($encounter as $i => $day) {
            $slot = $this->slot($record->id, $times[$i][0], $times[$i][1]);
            TimeTable::create([
                'ttr_id' => $record->id,
                'ts_id' => $slot->id,
                'day' => $day,
                'day_num' => $dayNum[$day],
                'subject_id' => $subject->id,
                'timestamp_from' => $slot->timestamp_from,
                'timestamp_to' => $slot->timestamp_to,
            ]);
        }

        return [$record, $section, $subject];
    }

    /** Day names in rendered row order, extracted from the grid markup. */
    protected function renderedDayOrder(string $html, string $markerPattern): array
    {
        preg_match_all($markerPattern, $html, $m);
        return $m[1];
    }

    public function test_helper_sorts_into_week_order()
    {
        $this->assertSame(
            ['Monday', 'Wednesday', 'Friday', 'Sunday'],
            WeekDays::sortNames(['Friday', 'Monday', 'Friday', 'Sunday', 'Wednesday'])->all()
        );
        $this->assertSame(self::EXPECTED, WeekDays::sortNames(['Friday', 'Monday', 'Thursday', 'Tuesday', 'Wednesday'])->all());
    }

    public function test_show_record_renders_days_monday_to_friday()
    {
        [$record] = $this->fridayFirstRecord();

        $response = $this->actingAs($this->admin())->get(route('ttr.show', $record->id));
        $response->assertOk();

        $order = $this->renderedDayOrder($response->getContent(), '/ttx-day-col"><strong>([A-Za-z]+)<\/strong>/');
        $this->assertSame(self::EXPECTED, $order,
            'View Timetable rows must run Monday → Friday regardless of cell encounter order');
    }

    public function test_print_record_renders_days_monday_to_friday()
    {
        [$record] = $this->fridayFirstRecord();

        $response = $this->actingAs($this->admin())->get(route('ttr.print', $record->id));
        $response->assertOk();

        $order = $this->renderedDayOrder($response->getContent(), '/tt-day"><strong>([A-Za-z]+)<\/strong>/');
        $this->assertSame(self::EXPECTED, $order,
            'Printed timetable rows must run Monday → Friday regardless of cell encounter order');
    }

    public function test_student_api_timetable_entries_follow_week_order()
    {
        [$record, $section] = $this->fridayFirstRecord();

        $u = uniqid();
        $studentUser = User::create([
            'name' => 'DayOrder Student ' . $u,
            'email' => 'dayorder-student-' . $u . '@test.local',
            'password' => bcrypt('student'),
            'user_type' => 'student',
            'code' => 'DOS' . $u,
        ]);
        StudentRecord::create([
            'session' => Qs::getCurrentSession(),
            'user_id' => $studentUser->id,
            'my_class_id' => $record->my_class_id,
            'section_id' => $section->id,
            'adm_no' => 'DO/' . $u,
            'year_admitted' => '2026',
        ]);

        $token = $studentUser->createToken('mobile')->plainTextToken;
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/timetable/mine');
        $response->assertOk();

        $days = collect($response->json('entries'))->pluck('day')->unique()->values()->all();
        $this->assertSame(self::EXPECTED, $days,
            'API timetable entries must be grouped Monday → Friday regardless of cell encounter order');
    }
}
