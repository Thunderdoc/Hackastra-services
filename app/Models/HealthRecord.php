<?php

namespace App\Models;

use App\User;
use Eloquent;

class HealthRecord extends Eloquent
{
    protected $fillable = [
        'student_id', 'visit_date', 'complaint', 'treatment',
        'is_referred', 'referred_to', 'recorded_by',
    ];

    protected $casts = [
        'visit_date' => 'date',
        'is_referred' => 'boolean',
    ];

    /**
     * student_id is a users id (the same convention marks.student_id
     * and assignment_submissions.student_id use), NOT a
     * student_records id. The student's class/section/parent are
     * resolved through their StudentRecord.
     */
    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function studentRecord()
    {
        return $this->hasOne(StudentRecord::class, 'user_id', 'student_id');
    }
}
