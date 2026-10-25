<?php

namespace Tests\Feature;

use App\Models\BulkImportJob;
use App\Models\Lga;
use App\Models\Nationality;
use App\Models\State;
use App\Support\Bulk\BulkImportService;
use App\Support\Bulk\XlsxReader;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * Regression tests for the 2026-10-25 owner wave 10 (bulk xlsx
 * import): the bulk pipeline accepts real .xlsx workbooks, not
 * just CSV — a dependency-free reader (ZipArchive + XMLReader)
 * turns the first sheet into rows, resolves shared strings, pads
 * sparse cells, and converts date-styled serials to Y-m-d.
 */
class OwnerWave10Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * Build a minimal .xlsx. $headers and each $row are cell lists:
     * ['s' => string] shared string, ['n' => number] raw number,
     * ['d' => serial] date-styled serial, ['i' => string] inline
     * string, null = gap (no <c> element, tests sparse padding).
     */
    protected function makeXlsx(string $path, array $headers, array $rows): void
    {
        $shared = [];
        $indexOf = function ($value) use (&$shared) {
            $i = array_search($value, $shared, true);
            if ($i === false) {
                $shared[] = $value;
                return count($shared) - 1;
            }
            return $i;
        };

        $colLetter = function ($i) {
            $letters = '';
            $i++;
            while ($i > 0) {
                $i--;
                $letters = chr(ord('A') + ($i % 26)) . $letters;
                $i = (int) ($i / 26);
            }
            return $letters;
        };

        $rowXml = function ($rowNum, $cells) use ($colLetter, $indexOf) {
            $xml = "<row r=\"{$rowNum}\">";
            foreach ($cells as $j => $cell) {
                if ($cell === null) {
                    continue;
                }
                $ref = $colLetter($j) . $rowNum;
                if (isset($cell['s'])) {
                    $xml .= "<c r=\"{$ref}\" t=\"s\"><v>" . $indexOf($cell['s']) . '</v></c>';
                } elseif (isset($cell['n'])) {
                    $xml .= "<c r=\"{$ref}\"><v>{$cell['n']}</v></c>";
                } elseif (isset($cell['d'])) {
                    $xml .= "<c r=\"{$ref}\" s=\"1\"><v>{$cell['d']}</v></c>";
                } elseif (isset($cell['i'])) {
                    $xml .= "<c r=\"{$ref}\" t=\"inlineStr\"><is><t>" . htmlspecialchars($cell['i']) . '</t></is></c>';
                }
            }
            return $xml . '</row>';
        };

        $sheet = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        $sheet .= $rowXml(1, array_map(function ($h) { return ['s' => $h]; }, $headers));
        foreach ($rows as $i => $row) {
            $sheet .= $rowXml($i + 2, $row);
        }
        $sheet .= '</sheetData></worksheet>';

        $si = '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($shared) . '" uniqueCount="' . count($shared) . '">';
        foreach ($shared as $s) {
            $si .= '<si><t>' . htmlspecialchars($s) . '</t></si>';
        }
        $si .= '</sst>';

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cellXfs count="2"><xf numFmtId="0"/><xf numFmtId="14"/></cellXfs></styleSheet>');
        $zip->addFromString('xl/sharedStrings.xml', $si);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();
    }

    public function test_reader_resolves_strings_dates_and_gaps()
    {
        $path = sys_get_temp_dir() . '/wave10-reader.xlsx';
        try {
            $this->makeXlsx($path,
                ['name', 'gender', 'class', 'section', 'year_admitted', 'dob', 'note'],
                [[
                    ['s' => 'Xlsx Test Student'],
                    ['s' => 'Male'],
                    null,
                    ['s' => 'A'],
                    ['n' => '2026'],
                    ['d' => '45658'],
                    ['i' => 'inline-note'],
                ]]
            );

            $this->assertTrue(XlsxReader::looksLikeXlsx($path));
            $rows = XlsxReader::rows($path);
            $this->assertSame(
                ['name', 'gender', 'class', 'section', 'year_admitted', 'dob', 'note'],
                $rows[0]
            );
            $this->assertSame(
                ['Xlsx Test Student', 'Male', '', 'A', '2026', '2025-01-01', 'inline-note'],
                $rows[1]
            );
        } finally {
            @unlink($path);
        }
    }

    public function test_reader_rejects_non_xlsx()
    {
        $path = sys_get_temp_dir() . '/wave10-plain.csv';
        try {
            file_put_contents($path, "name,gender\nTest,Male\n");
            $this->assertFalse(XlsxReader::looksLikeXlsx($path));
        } finally {
            @unlink($path);
        }
    }

    protected function validStudentXlsx(string $path): void
    {
        $nat = Nationality::first()->name;
        $state = State::first();
        $lga = Lga::where('state_id', $state->id)->first()->name;
        $this->makeXlsx($path,
            ['name', 'gender', 'address', 'nationality', 'state', 'lga', 'class', 'section', 'year_admitted', 'dob', 'email'],
            [[
                ['s' => 'Xlsx Service Student'],
                ['s' => 'Male'],
                ['s' => 'Test Address'],
                ['s' => $nat],
                ['s' => $state->name],
                ['s' => $lga],
                ['s' => 'Prep'],
                ['s' => 'A'],
                ['s' => '2026'],
                ['s' => '2015-05-01'],
                ['s' => 'xlsx-service-test@example.com'],
            ]]
        );
    }

    public function test_service_stages_an_xlsx_job()
    {
        $rel = 'bulk-imports-test/wave10-service.xlsx';
        $tmp = sys_get_temp_dir() . '/wave10-service.xlsx';
        try {
            $this->validStudentXlsx($tmp);
            Storage::put($rel, file_get_contents($tmp));

            $job = BulkImportJob::create([
                'user_id' => User::where('email', 'admin@hackstra.app')->first()->id,
                'module' => 'students',
                'mode' => 'add',
                'original_filename' => 'students.xlsx',
                'stored_path' => $rel,
                'file_hash' => hash_file('sha256', $tmp),
                'status' => 'uploaded',
            ]);

            $error = app(BulkImportService::class)->stageAndValidate($job);
            $this->assertNull($error);
            $this->assertSame('validated', $job->fresh()->status);
            $this->assertSame(1, (int) $job->fresh()->create_count);
        } finally {
            Storage::delete($rel);
            @unlink($tmp);
        }
    }

    public function test_upload_accepts_xlsx()
    {
        $tmp = sys_get_temp_dir() . '/wave10-upload.xlsx';
        $stored = null;
        try {
            $this->validStudentXlsx($tmp);
            $file = new UploadedFile($tmp, 'students.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

            $this->actingAs(User::where('email', 'admin@hackstra.app')->first())
                ->post(route('bulk.upload'), [
                    'module' => 'students',
                    'mode' => 'add',
                    'bulk_file' => $file,
                ])
                ->assertSessionHas('flash_success');

            $job = BulkImportJob::where('original_filename', 'students.xlsx')->latest('id')->first();
            $this->assertNotNull($job);
            $this->assertSame('validated', $job->status);
            $stored = $job->stored_path;
        } finally {
            if ($stored) {
                Storage::delete($stored);
            }
            @unlink($tmp);
        }
    }
}
