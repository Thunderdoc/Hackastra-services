<?php

namespace App\Models;

use Eloquent;

/**
 * F3 — Exam Seating plan (design §3.2): one row per exam per slot
 * (exam_date + start_time shared by that exam's sittings). Status
 * lives on the row: draft -> submitted -> published, mirroring the
 * ExamDateSheetState workflow at slot grain (Admin generates and
 * submits, the Principal alone publishes).
 */
class SeatingPlan extends Eloquent
{
    public const DRAFT = 'draft';
    public const SUBMITTED = 'submitted';
    public const PUBLISHED = 'published';

    public const MODE_SEPARATE = 'separate';
    public const MODE_MIXED = 'mixed';

    protected $table = 'exam_seating_plans';

    protected $fillable = [
        'exam_id', 'exam_date', 'start_time', 'status', 'mode',
        'total_students', 'total_seats', 'source_hash',
        'generated_by', 'generated_at', 'submitted_by', 'submitted_at',
        'approved_by', 'approved_at',
    ];

    protected $casts = [
        'total_students' => 'integer',
        'total_seats' => 'integer',
        'generated_at' => 'datetime',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }

    public function seats()
    {
        return $this->hasMany(SeatingSeat::class, 'plan_id');
    }

    /** Slot key as the planner groups it: 'Y-m-d|HH:MM' ('' time = untimed). */
    public function slotKey(): string
    {
        return $this->exam_date . '|' . ($this->start_time ? substr((string) $this->start_time, 0, 5) : '');
    }
}
