<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\BulkImportJob;
use App\Models\Section;
use App\Models\StaffRecord;
use App\Models\Subject;
use App\Support\Allocation;
use App\Support\Bulk\BulkImportService;
use App\Support\Staffing;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Agent F round 4 (2026-10-28) — ghost-teacher remediation end to
 * end + bulk-edit demotion guard for allotted teachers.
 *
 * Round 3 proved ghost/demoted/suspended teachers are FLAGGED
 * everywhere. This round proves the way OUT: an admin reassigns
 * the ghost subject to a live teacher on the Subjects page and
 * EVERY flag clears (subjects index, sections readiness, edit
 * matrix, Staffing committed, Allocation issues) — no stale flag
 * left behind. And the reverse attack: a bulk staff edit that
 * tries to demote a teacher WITH live allotments (subject +
 * override + section leadership) must leave the teacher, the
 * allotments, and the staffing truth untouched — user_type is
 * never editable in bulk (Wave 11 spec), and the benign cells in
 * the same row still apply.
 */
class OwnerAllotmentRound4Test extends TestCase
{
    use DatabaseTransactions;

    private function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    private function makeTeacher($code, $email)
    {
        return User::create([
            'name' => 'Probe Teacher R4',
            'username' => $code,
            'email' => $email,
            'password' => 'secret',
            'code' => $code,
            'user_type' => 'teacher',
        ]);
    }

    public function test_ghost_subject_remediation_clears_every_flag()
    {
        $probe = $this->makeTeacher('PROBE-R4-GH', 'probe-r4-gh@example.com');
        $subject = Subject::create([
            'name' => 'Probe Remed R4', 'my_class_id' => 1,
            'teacher_id' => $probe->id, 'slug' => 'probe-remed-r4',
            'periods_per_week' => 6,
        ]);
        // Ghost: the teacher row is deleted outright, the subject keeps the id.
        DB::table('users')->where('id', $probe->id)->delete();

        // (a) Every surface flags the ghost.
        $report = Staffing::report();
        $this->assertSame(0, $report['subjects']['Probe Remed R4']['committed']);
        $this->assertSame(6, $report['subjects']['Probe Remed R4']['unassigned']);
        $issue = collect(Allocation::recommendations())
            ->firstWhere('subject.id', $subject->id);
        $this->assertNotNull($issue);
        $this->assertSame('unassigned', $issue['issue']);

        $this->actingAs($this->admin())
            ->get(route('subjects.index', ['class_id' => 1]))
            ->assertOk()
            ->assertSee('Probe Remed R4')
            ->assertSee('<span class="badge badge-warning">Vacant</span>', false);
        $this->actingAs($this->admin())
            ->get(route('sections.index', ['class_id' => 1]))
            ->assertOk()
            ->assertDontSee('All staffed');
        $this->actingAs($this->admin())
            ->get(route('sections.edit', 1))
            ->assertOk()
            ->assertSee('No live teacher assigned');

        // (b) The admin opens the subject edit form (nothing
        // pre-selected for the ghost) and reassigns it to a live
        // teacher — Ananya (id 4), a real seeded teacher.
        $this->actingAs($this->admin())
            ->get(route('subjects.edit', $subject->id))
            ->assertOk();
        $this->actingAs($this->admin())
            ->put(route('subjects.update', $subject->id), [
                'name' => 'Probe Remed R4',
                'my_class_id' => 1,
                'teacher_id' => Qs::hash(4),
                'slug' => 'probe-remed-r4',
                'periods_per_week' => 6,
            ])->assertRedirect();

        $this->assertSame(4, (int) $subject->fresh()->teacher_id);

        // (c) Every flag clears — no stale flag anywhere.
        $after = Staffing::report();
        $this->assertSame(6, $after['subjects']['Probe Remed R4']['committed']);
        $this->assertSame(0, $after['subjects']['Probe Remed R4']['unassigned']);
        $this->assertNull(
            collect(Allocation::recommendations())->firstWhere('subject.id', $subject->id),
            'Remediated subject must need no allocation row.'
        );

        $this->actingAs($this->admin())
            ->get(route('subjects.index', ['class_id' => 1]))
            ->assertOk()
            ->assertDontSee('<span class="badge badge-warning">Vacant</span>', false);
        $this->actingAs($this->admin())
            ->get(route('sections.index', ['class_id' => 1]))
            ->assertOk()
            ->assertSee('All staffed');
        $this->actingAs($this->admin())
            ->get(route('sections.edit', 1))
            ->assertOk()
            ->assertDontSee('No live teacher assigned')
            ->assertSee('selected value="' . Qs::hash(4) . '"', false);
    }

    public function test_bulk_edit_cannot_demote_teacher_with_live_allotments()
    {
        $teacher = $this->makeTeacher('PROBE-R4-BK', 'probe-r4-bk@example.com');
        StaffRecord::create([
            'user_id' => $teacher->id,
            'code' => 'TEST/R4/' . strtoupper(\Illuminate\Support\Str::random(6)),
            'emp_date' => '2024-09-01',
        ]);
        // Live allotments on three axes: class subject, override, section leader.
        $subject = Subject::create([
            'name' => 'Probe Bulk R4', 'my_class_id' => 1,
            'teacher_id' => $teacher->id, 'slug' => 'probe-bulk-r4',
            'periods_per_week' => 6,
        ]);
        DB::table('section_subject_teachers')->insert([
            'section_id' => 1, 'subject_id' => 2, 'teacher_id' => $teacher->id,
        ]);
        Section::find(1)->update(['teacher_id' => $teacher->id]);

        $before = Staffing::report();
        $this->assertSame(6, $before['subjects']['Probe Bulk R4']['committed']);
        $statusBefore = $teacher->fresh()->status;

        // Bulk edit row: demote to Accountant + change the phone.
        $csv = 'name,user_type,email,gender,phone,phone2,dob,address,nationality,state,lga,blood_group,emp_date' . "\n"
            . ',Accountant,probe-r4-bk@example.com,,08039994444,,,,,,,,' . "\n";
        $rel = 'bulk-imports-test/r4-' . \Illuminate\Support\Str::random(8) . '.csv';
        $tmp = sys_get_temp_dir() . '/' . basename($rel);
        file_put_contents($tmp, $csv);
        Storage::put($rel, $csv);
        $job = BulkImportJob::create([
            'user_id' => $this->admin()->id,
            'module' => 'staff',
            'mode' => 'update',
            'original_filename' => 'staff.csv',
            'stored_path' => $rel,
            'file_hash' => hash_file('sha256', $tmp),
            'status' => 'uploaded',
        ]);
        try {
            $error = app(BulkImportService::class)->stageAndValidate($job);
            $this->assertNull($error, $error ?? '');
            $job = $job->fresh();
            $row = $job->rows()->first();
            $this->assertSame('valid_update', $row->verdict);
            $this->assertArrayNotHasKey('user_type', $row->changes);

            $svc = app(BulkImportService::class);
            $this->assertTrue($svc->confirm($job->fresh(), $job->fresh()->confirm_token));
            while ($job->fresh()->status === 'applying') {
                $svc->applyChunk($job->fresh());
            }
            $this->assertSame('completed', $job->fresh()->status);
        } finally {
            Storage::delete($rel);
            @unlink($tmp);
        }

        // The benign cell applied; the demotion did not.
        $this->assertSame('08039994444', $teacher->fresh()->phone);
        $this->assertSame('teacher', $teacher->fresh()->user_type);
        $this->assertSame($statusBefore, $teacher->fresh()->status);

        // All three allotment axes intact, staffing truth unchanged.
        $this->assertSame($teacher->id, (int) $subject->fresh()->teacher_id);
        $this->assertTrue(DB::table('section_subject_teachers')
            ->where('section_id', 1)->where('subject_id', 2)
            ->where('teacher_id', $teacher->id)->exists());
        $this->assertSame($teacher->id, (int) Section::find(1)->teacher_id);
        $after = Staffing::report();
        $this->assertSame(6, $after['subjects']['Probe Bulk R4']['committed']);

        $this->actingAs($this->admin())
            ->get(route('sections.index', ['class_id' => 1]))
            ->assertOk()
            ->assertSee('All staffed');
    }
}
