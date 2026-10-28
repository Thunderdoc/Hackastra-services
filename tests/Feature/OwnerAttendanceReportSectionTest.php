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
 * Owner order 2026-10-28 ("section wise ve dala"): the monthly
 * attendance report gains a Section filter. Students, tallies and
 * registers all narrow to the section; a section from another
 * class falls back to All sections; all-classes mode ignores it.
 * Fixtures are rolled back by transactions.
 */
class OwnerAttendanceReportSectionTest extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    /** Two roster students from a section, with names + adm numbers. */
    protected function sectionStudents(int $sectionId): array
    {
        return StudentRecord::where('section_id', $sectionId)
            ->where('grad', 0)
            ->orderBy('id')
            ->take(2)
            ->get()
            ->all();
    }

    protected function makeSession(int $ttId, int $sectionId, string $date, array $students, string $status)
    {
        $tt = TimeTable::findOrFail($ttId);
        $session = AttendanceSession::create([
            'date' => $date,
            'time_table_id' => $ttId,
            'my_class_id' => 13,
            'section_id' => $sectionId,
            'subject_id' => $tt->subject_id,
            'teacher_id' => 4,
            'topic' => 'Section filter probe',
        ]);
        foreach ($students as $student) {
            AttendanceSessionEntry::create([
                'session_id' => $session->id,
                'student_record_id' => $student->id,
                'status' => $status,
            ]);
        }

        return $session;
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Class 12-E (section 68, tt 788) present; Class 12-B
        // (section 65, tt 697) absent — both in October 2026.
        $this->makeSession(788, 68, '2026-10-12', $this->sectionStudents(68), 'present');
        $this->makeSession(697, 65, '2026-10-13', $this->sectionStudents(65), 'absent');
    }

    protected function names(array $students): array
    {
        return array_map(function ($s) {
            return User::find($s->user_id)->name;
        }, $students);
    }

    protected function adms(array $students): array
    {
        return array_map(function ($s) {
            return $s->adm_no;
        }, $students);
    }

    public function test_section_filter_lists_only_that_section()
    {
        $e = $this->sectionStudents(68);
        $b = $this->sectionStudents(65);

        $response = $this->actingAs($this->admin())
            ->get('/attendance/report?class_id=13&section_id=68&month=2026-10');
        $response->assertOk();
        $response->assertSee('Section');
        foreach ($this->names($e) as $name) {
            $response->assertSee($name);
        }
        foreach ($this->names($b) as $name) {
            $response->assertDontSee($name);
        }
        // Exactly the 40 section students + 1 header row.
        $this->assertSame(41, substr_count($response->getContent(), '<tr'));
    }

    public function test_section_filter_scopes_export_rows()
    {
        $e = $this->sectionStudents(68);
        $b = $this->sectionStudents(65);

        $response = $this->actingAs($this->admin())
            ->get('/attendance/report/export?class_id=13&section_id=68&month=2026-10');
        $response->assertOk();
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $response->streamedContent());
        foreach ($this->adms($e) as $adm) {
            $this->assertStringContainsString($adm, $content);
        }
        foreach ($this->adms($b) as $adm) {
            $this->assertStringNotContainsString($adm, $content);
        }

        $all = $this->actingAs($this->admin())
            ->get('/attendance/report/export?class_id=13&month=2026-10');
        $all->assertOk();
        $allContent = preg_replace('/^\xEF\xBB\xBF/', '', $all->streamedContent());
        foreach (array_merge($this->adms($e), $this->adms($b)) as $adm) {
            $this->assertStringContainsString($adm, $allContent);
        }
    }

    public function test_section_from_another_class_falls_back_to_all()
    {
        // Section 4 belongs to Class 1, not Class 12.
        $b = $this->sectionStudents(65);

        $response = $this->actingAs($this->admin())
            ->get('/attendance/report?class_id=13&section_id=4&month=2026-10');
        $response->assertOk();
        foreach ($this->names($b) as $name) {
            $response->assertSee($name);
        }
    }

    public function test_all_classes_mode_ignores_section()
    {
        $prep = StudentRecord::where('my_class_id', 1)->where('grad', 0)->orderBy('id')->firstOrFail();
        $c12 = StudentRecord::where('my_class_id', 13)->where('grad', 0)->orderBy('id')->firstOrFail();

        $response = $this->actingAs($this->admin())
            ->get('/attendance/report?class_id=all&section_id=68&month=2026-10');
        $response->assertOk();
        $response->assertSee(User::find($prep->user_id)->name);
        $response->assertSee(User::find($c12->user_id)->name);
    }

    public function test_legacy_null_section_session_matches_its_record_section()
    {
        // A legacy session with section_id NULL but a sectioned
        // record (tt 788 -> Class 12-E) belongs to that section.
        $tt = TimeTable::findOrFail(788);
        $session = AttendanceSession::create([
            'date' => '2026-10-14',
            'time_table_id' => 788,
            'my_class_id' => 13,
            'section_id' => null,
            'subject_id' => $tt->subject_id,
            'teacher_id' => 4,
            'topic' => 'Legacy null-section probe',
        ]);

        $this->assertTrue(
            AttendanceSession::forEffectiveSection(68)->where('id', $session->id)->exists()
        );
        $this->assertFalse(
            AttendanceSession::forEffectiveSection(65)->where('id', $session->id)->exists()
        );
        // And a class-grid session (record section NULL) matches none.
        $gridSession = AttendanceSession::create([
            'date' => '2026-10-15',
            'time_table_id' => 1,
            'my_class_id' => 1,
            'section_id' => null,
            'subject_id' => TimeTable::findOrFail(1)->subject_id,
            'teacher_id' => 4,
            'topic' => 'Class-grid probe',
        ]);
        $this->assertFalse(
            AttendanceSession::forEffectiveSection(1)->where('id', $gridSession->id)->exists()
        );
    }
}
