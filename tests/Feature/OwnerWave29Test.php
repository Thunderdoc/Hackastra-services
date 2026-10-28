<?php

namespace Tests\Feature;

use App\Models\AttendanceSession;
use App\Models\AttendanceSessionEntry;
use App\Models\StudentRecord;
use App\Models\TimeTable;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Wave 29: attendance take page shows system roll numbers (roster
 * position in admission order), the roster exports to Excel/CSV
 * and prints to PDF with the same numbers, and the submitted
 * register carries the same roll numbers everywhere.
 */
class OwnerWave29Test extends TestCase
{
    use DatabaseTransactions;

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->first();
    }

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->first();
    }

    protected function otherTeacher()
    {
        return User::where('user_type', 'teacher')->where('id', '!=', $this->teacher()->id)->first();
    }

    /** A Monday class-grid period actually taught by teacher@. */
    protected function takePeriod()
    {
        return TimeTable::where('day', 'Monday')
            ->whereHas('subject', function ($q) {
                $q->where('teacher_id', $this->teacher()->id);
            })
            ->whereHas('tt_record', function ($q) {
                $q->whereNull('section_id');
            })
            ->firstOrFail();
    }

    protected function rosterCount(int $classId): int
    {
        return StudentRecord::where('my_class_id', $classId)->where('grad', 0)->count();
    }

    public function test_take_page_shows_system_roll_numbers_in_order()
    {
        $tt = $this->takePeriod();
        AttendanceSession::where('time_table_id', $tt->id)->whereDate('date', '2026-10-19')->delete();

        $response = $this->actingAs($this->teacher())
            ->get(route('attendance.periods.take', [$tt->id, 'date' => '2026-10-19']));
        $response->assertOk();
        $response->assertSee('Export roster (Excel)');
        $response->assertSee('Roll numbers are allocated by the system');

        preg_match_all('/reg-roll">(\d+)</', $response->getContent(), $m);
        $rolls = array_map('intval', $m[1]);
        $this->assertNotEmpty($rolls);
        $this->assertSame(range(1, count($rolls)), $rolls, 'Roll badges must run 1..N in roster order');
        $this->assertSame($this->rosterCount($tt->tt_record->my_class_id), count($rolls));
    }

    public function test_roster_export_csv_matches_take_page()
    {
        $tt = $this->takePeriod();
        $expected = $this->rosterCount($tt->tt_record->my_class_id);

        $response = $this->actingAs($this->teacher())->get(route('attendance.periods.roster_export', $tt->id));
        $response->assertOk();
        $response->assertDownload();

        $lines = array_values(array_filter(explode("\n", trim(preg_replace('/^\xEF\xBB\xBF/', '', $response->streamedContent())))));
        $header = str_getcsv($lines[0]);
        $this->assertSame(['Roll No', 'Student', 'Section', 'Admission No'], $header);
        $this->assertSame($expected, count($lines) - 1, 'One CSV row per roster student');
        $first = str_getcsv($lines[1]);
        $this->assertSame('1', $first[0], 'First roster row carries roll 1');
    }

    public function test_roster_pdf_downloads()
    {
        $tt = $this->takePeriod();

        $response = $this->actingAs($this->teacher())->get(route('attendance.periods.roster_pdf', $tt->id));
        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_wrong_teacher_cannot_export_roster()
    {
        $tt = $this->takePeriod();

        $this->actingAs($this->otherTeacher())
            ->get(route('attendance.periods.roster_export', $tt->id))
            ->assertForbidden();
    }

    protected function makeSession()
    {
        $tt = $this->takePeriod();
        $classId = $tt->tt_record->my_class_id;
        $students = StudentRecord::where('my_class_id', $classId)->where('grad', 0)->orderBy('id')->take(3)->get();

        $session = AttendanceSession::create([
            'date' => '2026-10-19',
            'time_table_id' => $tt->id,
            'my_class_id' => $classId,
            'section_id' => null,
            'subject_id' => $tt->subject_id,
            'teacher_id' => $this->teacher()->id,
            'topic' => 'Wave 29 test topic',
            'status' => AttendanceSession::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);

        $now = now();
        AttendanceSessionEntry::insert($students->map(function ($student, $i) use ($session, $now) {
            return [
                'session_id' => $session->id,
                'student_record_id' => $student->id,
                'status' => ['present', 'absent', 'late'][$i],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        })->all());

        return [$session, $students];
    }

    public function test_submitted_register_shows_same_roll_numbers()
    {
        [$session, $students] = $this->makeSession();

        // Owner rule change: submitted registers are gone from the
        // teacher's end, so roll-number coverage now runs as admin
        // (oversight keeps full view); teacher-403 is covered by
        // OwnerAttendanceTeacherViewTest.
        $response = $this->actingAs($this->admin())->get(route('attendance.periods.show', $session->id));
        $response->assertOk();
        $response->assertSee('Roll No');

        // The register sorts by name for reading; the roll cells
        // must still be the admission-order positions (1, 2, 3 for
        // the first three roster students, as a set).
        preg_match_all('/<td>(\d+)<\/td>/', $response->getContent(), $m);
        $rolls = array_map('intval', $m[1]);
        sort($rolls);
        $this->assertSame([1, 2, 3], $rolls);
    }

    public function test_submitted_register_csv_has_roll_column()
    {
        [$session] = $this->makeSession();

        // Same owner-rule change as above: CSV coverage runs as admin.
        $response = $this->actingAs($this->admin())->get(route('attendance.periods.export_register', $session->id));
        $response->assertOk();
        $response->assertDownload();
        $lines = array_values(array_filter(explode("\n", trim(preg_replace('/^\xEF\xBB\xBF/', '', $response->streamedContent())))));
        $this->assertSame(['Roll No', 'Student', 'Section', 'Admission No', 'Status'], str_getcsv($lines[0]));
        $this->assertSame(3, count($lines) - 1);
    }
}
