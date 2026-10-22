<?php

namespace App\Models;

use Eloquent;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * One spreadsheet row inside a bulk import job: the raw cells as
 * uploaded, the resolved (names → ids) payload the applier consumes,
 * the preview verdict, structured per-field errors, and — in update
 * mode — the old → new diff the preview showed.
 */
class BulkImportRow extends Eloquent
{
    use HasFactory;

    protected $fillable = [
        'job_id', 'row_number', 'raw', 'resolved', 'fingerprint',
        'verdict', 'errors', 'changes', 'record_id', 'failure',
    ];

    protected $casts = [
        'raw' => 'array',
        'resolved' => 'array',
        'errors' => 'array',
        'changes' => 'array',
    ];

    public function job()
    {
        return $this->belongsTo(BulkImportJob::class, 'job_id');
    }
}
