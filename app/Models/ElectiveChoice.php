<?php

namespace App\Models;

use App\User;
use Eloquent;

/**
 * Student Elective Choice (Class 11/12) — 2026-10-28.
 *
 * One row per pick, in its OWN table: nothing here touches marks,
 * tabulation, or exam scope. Approved choices are displayed on the
 * electives index itself (student sees own, admin sees all).
 *
 * Duplicate rule (app-level, see controller): a student may hold only
 * ONE live (pending or approved) row per subject. A rejected row does
 * NOT block a fresh pick — re-picking creates a NEW pending row, so
 * the rejected row stays as history. That is why there is deliberately
 * NO unique DB constraint on (student_record_id, subject_id): it
 * would forbid the re-pick-after-rejection the spec requires.
 */
class ElectiveChoice extends Eloquent
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = ['student_record_id', 'subject_id', 'status', 'decided_by'];

    public function student_record()
    {
        return $this->belongsTo(StudentRecord::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function decidedBy()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
