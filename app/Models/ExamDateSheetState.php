<?php

namespace App\Models;

use Eloquent;

/**
 * Date sheet approval state (overnight menus 2026-10-29, item 22).
 *
 * One row per (exam, class): draft (admin editing) -> submitted
 * (awaiting the Principal) -> published (Principal approved; the
 * sheet students, parents and teachers see). No row means draft.
 * This is a DEDICATED date-sheet flag — it is not results_published
 * (that switch publishes marks, not date sheets).
 */
class ExamDateSheetState extends Eloquent
{
    public const DRAFT = 'draft';
    public const SUBMITTED = 'submitted';
    public const PUBLISHED = 'published';

    protected $fillable = ['exam_id', 'my_class_id', 'status', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at'];

    protected $casts = [
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    /** Status of one (exam, class) sheet; no row means draft. */
    public static function statusFor($examId, $classId): string
    {
        $state = static::where('exam_id', $examId)->where('my_class_id', $classId)->first();

        return $state ? $state->status : static::DRAFT;
    }

    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }

    public function my_class()
    {
        return $this->belongsTo(MyClass::class);
    }
}
