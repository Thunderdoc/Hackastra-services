<?php

namespace App\Support\Bulk;

use App\Models\BulkImportJob;
use App\Models\BulkImportRow;
use App\Models\UserType;
use App\Support\Audit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The staged bulk pipeline (Bulk System Phase A, audit §4.1):
 *
 *   Upload → Stage → Validate → Preview → Confirm → Apply (chunked)
 *
 * The legacy importer validated-and-created in one fused pass; here
 * nothing touches users/student_records/staff_records until a human
 * has seen the preview and confirmed with the job's single-use token.
 * Apply runs synchronously in chunks of 100 rows (there is no queue
 * infrastructure in this app — audit §6.1) with every row's state
 * persisted, so an interrupted job is resumable from the hub and can
 * never be half-applied inside a row.
 */
class BulkImportService
{
    public const CHUNK = 100;
    public const MAX_ROWS = 10000;

    protected $students;
    protected $staff;

    public function __construct(StudentBulkModule $students, StaffBulkModule $staff)
    {
        $this->students = $students;
        $this->staff = $staff;
    }

    /** The flows Phase A ships. */
    public static function flows(): array
    {
        return [
            'students:add' => ['module' => 'students', 'mode' => 'add'],
            'students:update' => ['module' => 'students', 'mode' => 'update'],
            'staff:add' => ['module' => 'staff', 'mode' => 'add'],
        ];
    }

    public function moduleFor(BulkImportJob $job)
    {
        return $job->module === 'staff' ? $this->staff : $this->students;
    }

    public function columnsFor(string $module, string $mode): array
    {
        return $module === 'staff' ? StaffBulkModule::COLUMNS : StudentBulkModule::COLUMNS;
    }

    /**
     * Store the upload on the private disk and create the job row.
     * Returns [job, sameHashWarning|null].
     */
    public function createJob($actor, string $module, string $mode, UploadedFile $file): array
    {
        $hash = hash_file('sha256', $file->getRealPath());

        $previous = BulkImportJob::where('file_hash', $hash)
            ->where('module', $module)->where('mode', $mode)
            ->where('status', '!=', 'discarded')
            ->latest('id')->first();

        $path = Storage::putFileAs('bulk-imports', $file, Str::random(24) . '.csv');

        $job = BulkImportJob::create([
            'user_id' => $actor->id,
            'module' => $module,
            'mode' => $mode,
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $path,
            'file_hash' => $hash,
            'status' => 'uploaded',
            'confirm_token' => null,
        ]);

        $warning = $previous
            ? "Heads up: this exact file was uploaded before as Job #{$previous->id} ({$previous->original_filename}). Rows it already created will be detected as duplicates in the preview."
            : null;

        return [$job, $warning];
    }

    /**
     * Parse the stored CSV into staged rows and validate every one.
     * Returns an error message, or null on success (job → validated).
     */
    public function stageAndValidate(BulkImportJob $job): ?string
    {
        $full = Storage::path($job->stored_path);
        $handle = @fopen($full, 'r');
        if ($handle === false) {
            return $this->failJob($job, 'The uploaded file could not be read back from storage.');
        }

        $headers = $this->readHeaderRow($handle);
        if ($headers === null) {
            fclose($handle);
            return $this->failJob($job, 'The CSV file is empty or has no header row.');
        }

        $module = $this->moduleFor($job);
        $missing = array_diff($module->requiredHeader($job->mode), $headers);
        if ($missing) {
            fclose($handle);
            return $this->failJob($job, 'The CSV is missing required column(s): ' . implode(', ', $missing) . '. Please use the template for this flow.');
        }

        $ctx = $this->buildContext($job);
        $columns = $this->columnsFor($job->module, $job->mode);

        $counts = ['total' => 0, 'create' => 0, 'update' => 0, 'skip' => 0, 'error' => 0];
        $line = 1;

        while (($cells = fgetcsv($handle)) !== false) {
            $line++;
            if ($cells === [null] || $this->rowIsBlank($cells)) {
                continue;
            }

            $counts['total']++;
            if ($counts['total'] > self::MAX_ROWS) {
                fclose($handle);
                $job->rows()->delete();
                return $this->failJob($job, 'The file has more than ' . self::MAX_ROWS . ' rows. Please split it into smaller files.');
            }

            $raw = $this->mapRow($headers, $cells, $columns);
            $result = $this->validateRow($job, $raw, $ctx);

            BulkImportRow::create([
                'job_id' => $job->id,
                'row_number' => $line,
                'raw' => $raw,
                'resolved' => $result['resolved'],
                'fingerprint' => $result['fingerprint'],
                'verdict' => $result['verdict'],
                'errors' => $result['errors'],
                'changes' => $result['changes'],
            ]);

            switch ($result['verdict']) {
                case 'valid_create':
                    $counts['create']++;
                    break;
                case 'valid_update':
                    // A no-change update is a skip in the counts: the
                    // preview says so, and apply performs no write.
                    if (!empty($result['changes'])) {
                        $counts['update']++;
                    } else {
                        $counts['skip']++;
                    }
                    break;
                case 'duplicate':
                case 'unmatched':
                    $counts['skip']++;
                    break;
                default:
                    $counts['error']++;
            }
        }
        fclose($handle);

        $job->update([
            'status' => 'validated',
            'total_rows' => $counts['total'],
            'create_count' => $counts['create'],
            'update_count' => $counts['update'],
            'skip_count' => $counts['skip'],
            'error_count' => $counts['error'],
            'confirm_token' => Str::random(64),
            'validated_at' => now(),
        ]);

        return null;
    }

    /**
     * Burn the single-use confirm token and flip the job to applying.
     * A replayed, tampered, or mistimed confirm returns false and
     * applies nothing (audit §6.2 rail 2).
     */
    public function confirm(BulkImportJob $job, ?string $token): bool
    {
        if ($job->status !== 'validated' || empty($job->confirm_token) || empty($token)) {
            return false;
        }
        if (!hash_equals($job->confirm_token, $token)) {
            return false;
        }

        $job->update(['confirm_token' => null, 'status' => 'applying']);
        return true;
    }

    /**
     * Apply at most CHUNK pending rows. Each row is applied through
     * the module's per-row transaction; a failure flips only that row
     * to `failed` with the (truncated) reason. Returns the number of
     * rows still pending afterwards.
     */
    public function applyChunk(BulkImportJob $job): int
    {
        if ($job->status !== 'applying') {
            return (int) $job->pendingRows()->count();
        }

        $module = $this->moduleFor($job);
        $rows = $job->pendingRows()->orderBy('row_number')->limit(self::CHUNK)->get();

        foreach ($rows as $row) {
            try {
                if ($row->verdict === 'valid_create') {
                    $recordId = $module->applyAdd($row->resolved);
                    $job->increment('applied_create_count');
                } else {
                    $hadChanges = !empty($row->changes);
                    $recordId = $module->applyUpdate($row->resolved);
                    if ($hadChanges) {
                        $job->increment('applied_update_count');
                    }
                }
                $row->update(['verdict' => 'applied', 'record_id' => $recordId, 'failure' => null]);
            } catch (\Throwable $e) {
                $row->update([
                    'verdict' => 'failed',
                    'failure' => Str::limit($e->getMessage(), 500),
                ]);
                $job->increment('failed_count');
            }
        }

        $remaining = (int) $job->pendingRows()->count();

        if ($remaining === 0) {
            $job->update(['status' => 'completed', 'applied_at' => now()]);
            Audit::log('bulk.applied', BulkImportJob::class,
                "Bulk {$job->mode} ({$job->module}) Job #{$job->id} ({$job->original_filename}): "
                . "{$job->applied_create_count} created, {$job->applied_update_count} updated, "
                . "{$job->skip_count} skipped, {$job->failed_count} failed of {$job->total_rows} rows.");
            if ($job->module === 'students' && $job->mode === 'add') {
                // Same audit entry the legacy one-pass importer writes.
                Audit::log('student.imported', \App\Models\StudentRecord::class,
                    "CSV import: {$job->applied_create_count} student(s) imported, "
                    . ($job->skip_count + $job->error_count + $job->failed_count) . ' row(s) skipped.');
            }
        }

        return $remaining;
    }

    /** Stop a job that has not finished. Already-applied rows stay. */
    public function discard(BulkImportJob $job): void
    {
        if (in_array($job->status, ['uploaded', 'staged', 'validated', 'applying'], true)) {
            $job->update(['status' => 'discarded', 'confirm_token' => null]);
        }
    }

    /**************** INTERNALS ****************/

    protected function validateRow(BulkImportJob $job, array $raw, BulkContext $ctx): array
    {
        $module = $this->moduleFor($job);
        if ($job->mode === 'update') {
            return $module->validateUpdate($raw, $ctx);
        }
        return $module->validateAdd($raw, $ctx);
    }

    protected function buildContext(BulkImportJob $job): BulkContext
    {
        $ctx = new BulkContext();
        $ctx->actor = $job->user;
        $ctx->lookups = $this->students->toolkit()->buildLookups();

        // Fingerprints of rows applied by earlier jobs of the same
        // flow — the re-upload rail (audit §6.2 rail 3).
        if ($job->mode === 'add') {
            $fps = BulkImportRow::where('verdict', 'applied')
                ->whereHas('job', function ($q) use ($job) {
                    $q->where('module', $job->module)->where('mode', 'add');
                })
                ->pluck('fingerprint');
            foreach ($fps as $fp) {
                $ctx->priorAppliedFingerprints[$fp] = true;
            }
        }

        // Staff level check inputs.
        foreach (UserType::all() as $t) {
            $ctx->typeMap[strtolower($t->title)] = $t;
            $ctx->typeMap[strtolower(str_replace(' ', '_', $t->name))] = $t;
            if ($job->user && $t->title === $job->user->user_type) {
                $ctx->actorType = $t;
            }
        }

        return $ctx;
    }

    protected function failJob(BulkImportJob $job, string $message): string
    {
        $job->update(['status' => 'failed', 'confirm_token' => null]);
        return $message;
    }

    /***** CSV reading — same normalisation as the legacy importer's
           readHeaderRow/rowIsBlank/mapRow (BOM-safe header row,
           unknown columns ignored), shared by both modules. *****/

    protected function readHeaderRow($handle): ?array
    {
        $headers = fgetcsv($handle);
        if ($headers === false || $headers === [null]) {
            return null;
        }
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);

        return array_map(function ($h) {
            $h = strtolower(trim((string) $h));
            $h = preg_replace('/[^a-z0-9]+/', '_', $h);
            return trim($h, '_');
        }, $headers);
    }

    protected function rowIsBlank(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }
        return true;
    }

    protected function mapRow(array $headers, array $cells, array $columns): array
    {
        $row = [];
        foreach ($headers as $i => $name) {
            if ($name !== '' && in_array($name, $columns, true)) {
                $row[$name] = isset($cells[$i]) ? trim((string) $cells[$i]) : '';
            }
        }
        return $row;
    }
}
