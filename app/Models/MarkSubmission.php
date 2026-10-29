<?php

namespace App\Models;

use App\User;
use Eloquent;

/**
 * A teacher's submission of one (exam, section, subject) marks
 * unit (owner order 2026-10-29). The row's existence IS the lock —
 * see App\Support\MarkLifecycle for the full state machine
 * (date-gated entry → draft saves → submitted/locked → admin
 * reopen by deleting the row, audited in the controller).
 */
class MarkSubmission extends Eloquent
{
    protected $fillable = [
        'exam_id', 'my_class_id', 'section_id', 'subject_id', 'submitted_by',
    ];

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** The submission locking one unit, or null while it is a draft. */
    public static function forUnit($examId, $sectionId, $subjectId): ?self
    {
        return static::where('exam_id', $examId)
            ->where('section_id', $sectionId)
            ->where('subject_id', $subjectId)
            ->first();
    }
}
