<?php

namespace App\Models;

use App\User;
use Eloquent;

/**
 * Student Documents Vault: one row per uploaded student document
 * (certificate, ID proof, etc.). The file itself lives on the public
 * disk under documents/ with a random, non-guessable stored name —
 * stored_path is NEVER the raw original name, and downloads are
 * served through the controller with an ownership check, never via
 * a raw /storage URL.
 */
class StudentDocument extends Eloquent
{
    protected $fillable = [
        'student_record_id', 'uploaded_by', 'title', 'original_name',
        'stored_path', 'mime_type', 'size_bytes',
    ];

    public function studentRecord()
    {
        return $this->belongsTo(StudentRecord::class);
    }

    public function uploadedBy()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
