<?php

namespace App\Models;

use App\User;
use Eloquent;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * One uploaded file in the Bulk Management pipeline (Phase A).
 * The job row is the import history: counts reconcile by construction
 * (created + updated + skipped + failed = total).
 */
class BulkImportJob extends Eloquent
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'module', 'mode', 'original_filename', 'stored_path',
        'file_hash', 'status', 'total_rows', 'create_count', 'update_count',
        'skip_count', 'error_count', 'applied_create_count',
        'applied_update_count', 'failed_count', 'confirm_token',
        'validated_at', 'applied_at',
    ];

    protected $casts = [
        'validated_at' => 'datetime',
        'applied_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function rows()
    {
        return $this->hasMany(BulkImportRow::class, 'job_id');
    }

    /** Rows still waiting to be applied (the resumable remainder). */
    public function pendingRows()
    {
        return $this->rows()->whereIn('verdict', ['valid_create', 'valid_update']);
    }

    public function moduleLabel(): string
    {
        return $this->module === 'staff' ? 'Staff' : 'Students';
    }

    public function modeLabel(): string
    {
        return $this->mode === 'update' ? 'Bulk Edit' : 'Bulk Add';
    }

    public function title(): string
    {
        return $this->modeLabel() . ' — ' . $this->moduleLabel();
    }
}
