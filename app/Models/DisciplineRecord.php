<?php

namespace App\Models;

use App\User;
use Eloquent;

/**
 * Discipline Records (2026-10-28): a behaviour / incident log, one
 * row per incident for one student.
 *
 * Columns: student_id (users.id of the student), recorded_by
 * (users.id of the admin/teacher who wrote it), incident_date,
 * type, description, action_taken.
 *
 * TYPE — a small fixed set, nothing free-form, so the list page can
 * filter on it and reports stay comparable:
 *   late        — late arrival / late submission of work
 *   homework    — homework not done / incomplete
 *   misconduct  — behaviour in class, corridors or grounds
 *   attendance  — truancy / unexplained absence pattern
 *   uniform     — uniform / dress-code breach
 *   other       — anything else (details live in description)
 * Add a value here ONLY with a matching label below and a
 * migration-safe backfill plan — old rows keep their stored value.
 */
class DisciplineRecord extends Eloquent
{
    const TYPES = [
        'late' => 'Late',
        'homework' => 'Homework',
        'misconduct' => 'Misconduct',
        'attendance' => 'Attendance',
        'uniform' => 'Uniform',
        'other' => 'Other',
    ];

    protected $fillable = [
        'student_id', 'recorded_by', 'incident_date', 'type',
        'description', 'action_taken',
    ];

    protected $casts = [
        'incident_date' => 'date',
    ];

    public static function typeLabels(): array
    {
        return self::TYPES;
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** The student's enrolment row (class / section / parent). */
    public function student_record()
    {
        return $this->hasOne(StudentRecord::class, 'user_id', 'student_id');
    }
}
