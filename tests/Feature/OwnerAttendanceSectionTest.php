<?php

namespace Tests\Feature;

use App\Models\AttendanceSession;
use App\Models\AttendanceSessionEntry;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\Models\TimeTable;
use App\Support\SectionTeacher;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Section-scope fix: a section period's register — take page and
 * submitted-register view — lists only that section's students,
 * never the whole class. Covers the legacy seeded sessions too:
 * the seeder used to store section periods with section_id NULL
 * and class-wide entries, so the view derives the section from
 * the period itself and filters to it.
 */
class OwnerAttendanceSectionTest extends TestCase
{
    use DatabaseTransactions;

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    public function test_take_page_section_period_lists_only_section_students()
    {
        // tt 788: Class 12-E (section 68) English, Monday.
        $response = $this->actingAs($this->teacher())
            ->get(route('attendance.periods.take', [788, 'date' => '2026-11-02']));
        $response->assertOk();
        $response->assertSee('40 students');
        $rolls = [];
        preg_match_all('/reg-roll">(\d+)</', $response->getContent(), $rolls);
        $this->assertCount(40, $rolls[1], 'Take page must list the 40 section students, not the 520 class students');
    }

    public function test_submitted_register_view_filters_legacy_class_wide_session()
    {
        // Legacy-shaped fixture built in-test (owner CI fix
        // 2026-10-29): the old test borrowed seeded session 11,
        // which held the legacy shape only in one dev database. A
        // fresh seed's session 11 is a different, modern row, so
        // build the legacy shape directly: a Class 11-A section
        // period (record 12, Mathematics) whose session is stored
        // with section_id NULL and entries for the WHOLE class.
        $cell = TimeTable::where('ttr_id', 12)->where('subject_id', 69)
            ->orderBy('id')->firstOrFail();
        $date = null;
        $cursor = \Carbon\Carbon::create(2026, 11, 2);
        for ($i = 0; $i < 56 && !$date; $i++) {
            $candidate = $cursor->copy()->addDays($i);
            if ($candidate->format('l') === $cell->day
                && !AttendanceSession::where('time_table_id', $cell->id)
                    ->whereDate('date', $candidate->toDateString())->exists()) {
                $date = $candidate->toDateString();
            }
        }
        $this->assertNotNull($date, 'a free date for the fixture session must exist');
        $session = AttendanceSession::create([
            'date' => $date,
            'time_table_id' => $cell->id,
            'my_class_id' => 12,
            'section_id' => null,
            'subject_id' => 69,
            'teacher_id' => SectionTeacher::teacherIdFor(55, Subject::findOrFail(69)),
            'topic' => 'Legacy class-wide session (section probe)',
            'status' => AttendanceSession::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);
        foreach (StudentRecord::where('my_class_id', 12)->where('grad', 0)->orderBy('id')->get() as $sr) {
            AttendanceSessionEntry::create([
                'session_id' => $session->id,
                'student_record_id' => $sr->id,
                'status' => AttendanceSessionEntry::STATUS_PRESENT,
            ]);
        }
        $this->assertNull($session->section_id);

        $response = $this->actingAs($this->admin())
            ->get(route('attendance.periods.show', $session->id));
        $response->assertOk();
        $response->assertSee('Section: A');

        $content = $response->getContent();
        $admNos = [];
        preg_match_all('/HA\/S\/2026\/\d+/', $content, $admNos);
        $this->assertCount(40, array_unique($admNos[0]), 'Register view must list the 40 section students, not the 360 class students');

        $outsider = StudentRecord::where('my_class_id', 12)->where('section_id', 56)->where('grad', 0)->firstOrFail();
        $response->assertDontSee($outsider->adm_no);
    }
}
