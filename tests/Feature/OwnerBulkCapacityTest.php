<?php

namespace Tests\Feature;

use App\Models\BulkImportJob;
use App\Models\Section;
use App\Models\StudentRecord;
use App\Support\Bulk\BulkImportService;
use App\Support\SectionCapacity;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Owner loophole review 2026-10-30: single admits have
 * respected section capacity since 2026-10-29, but bulk ADD
 * imports walked straight past it — a 40-seat section could
 * receive 200 imported students. Bulk ADD rows now claim seats
 * during validation, counting seats claimed by earlier rows of
 * the same file: full section → the row moves to the next
 * section with room (preview says so); whole class full → the
 * row is refused in the preview and never imported.
 */
class OwnerBulkCapacityTest extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function stageJob(string $csv): BulkImportJob
    {
        $rel = 'bulk-imports-test/capacity-' . \Illuminate\Support\Str::random(8) . '.csv';
        $tmp = sys_get_temp_dir() . '/' . basename($rel);
        file_put_contents($tmp, $csv);
        Storage::put($rel, $csv);

        $job = BulkImportJob::create([
            'user_id' => $this->admin()->id,
            'module' => 'students',
            'mode' => 'add',
            'original_filename' => 'students.csv',
            'stored_path' => $rel,
            'file_hash' => hash_file('sha256', $tmp),
            'status' => 'uploaded',
        ]);
        $error = app(BulkImportService::class)->stageAndValidate($job);
        $this->assertNull($error, $error ?? '');
        return $job->fresh();
    }

    protected function applyJob(BulkImportJob $job): void
    {
        $svc = app(BulkImportService::class);
        $this->assertTrue($svc->confirm($job->fresh(), $job->fresh()->confirm_token));
        while ($job->fresh()->status === 'applying') {
            $svc->applyChunk($job->fresh());
        }
        $this->assertSame('completed', $job->fresh()->status);
    }

    protected function csvFor(array $names): string
    {
        $header = 'name,email,gender,phone,phone2,dob,address,nationality,state,lga,blood_group,class,section,parent_email,year_admitted';
        $lines = [$header];
        foreach ($names as $i => $name) {
            $lines[] = implode(',', [
                $name, 'cap.test' . $i . '@example.com', 'Female', '0803555000' . $i, '',
                '2015-05-01', '1 Test Lane', 'Nigerian', 'Lagos', 'Ikeja', 'O+',
                'Prep', 'A', '', '2026',
            ]);
        }
        return implode("\n", $lines) . "\n";
    }

    public function test_bulk_add_moves_rows_when_the_section_fills_mid_file()
    {
        $strengthA = SectionCapacity::strength(1);
        Section::where('id', 1)->update(['capacity' => $strengthA + 1]);

        $job = $this->stageJob($this->csvFor(['Cap Child One', 'Cap Child Two', 'Cap Child Three']));
        $rows = $job->rows()->orderBy('row_number')->get();
        $this->assertSame(['valid_create', 'valid_create', 'valid_create'], $rows->pluck('verdict')->all());
        $this->assertStringContainsString('placed in Section B', json_encode($rows[1]->errors));
        $this->assertStringContainsString('placed in Section B', json_encode($rows[2]->errors));

        $strengthB = SectionCapacity::strength(2);
        $this->applyJob($job);

        // Section A took exactly its one remaining seat — never
        // over capacity — and the other two children landed in B.
        $this->assertSame($strengthA + 1, SectionCapacity::strength(1));
        $this->assertSame($strengthB + 2, SectionCapacity::strength(2));
        $moved = StudentRecord::whereHas('user', function ($u) {
            $u->whereIn('name', ['Cap Child Two', 'Cap Child Three']);
        })->get();
        $this->assertTrue($moved->every(function ($sr) {
            return (int) $sr->section_id === 2;
        }));
    }

    public function test_bulk_add_refuses_rows_when_the_whole_class_is_full()
    {
        foreach ([1, 2, 3] as $sectionId) {
            Section::where('id', $sectionId)->update([
                'capacity' => SectionCapacity::strength($sectionId),
            ]);
        }
        $before = StudentRecord::count();

        $job = $this->stageJob($this->csvFor(['Cap Refused Child']));
        $row = $job->rows()->firstOrFail();
        $this->assertSame('invalid', $row->verdict);
        $this->assertStringContainsString('Every section of Prep is full', json_encode($row->errors));

        $this->applyJob($job);
        $this->assertSame($before, StudentRecord::count());
    }
}
