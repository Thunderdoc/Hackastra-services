<?php

namespace App\Models;

use App\User;
use Eloquent;

class AssignmentSubmission extends Eloquent
{
    protected $fillable = [
        'assignment_id', 'student_id', 'attachment_path', 'text_answer',
        'submitted_at', 'is_late', 'marks_awarded', 'feedback',
        'graded_by', 'graded_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'graded_at' => 'datetime',
        'is_late' => 'boolean',
        'marks_awarded' => 'decimal:2',
    ];

    public function assignment()
    {
        return $this->belongsTo(Assignment::class);
    }

    /** The student is a users row — the convention marks.student_id uses. */
    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function grader()
    {
        return $this->belongsTo(User::class, 'graded_by');
    }

    public function isGraded(): bool
    {
        return $this->marks_awarded !== null;
    }
}
