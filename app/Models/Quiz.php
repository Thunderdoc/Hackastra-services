<?php

namespace App\Models;

use App\User;
use Eloquent;

class Quiz extends Eloquent
{
    protected $fillable = [
        'teacher_id', 'my_class_id', 'section_id', 'subject_id', 'title', 'time_limit_minutes',
        'status', 'available_from', 'available_until',
    ];

    protected $casts = [
        'available_from' => 'datetime',
        'available_until' => 'datetime',
    ];

    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function my_class()
    {
        return $this->belongsTo(MyClass::class, 'my_class_id');
    }

    public function section()
    {
        return $this->belongsTo(Section::class, 'section_id');
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function questions()
    {
        return $this->hasMany(QuizQuestion::class, 'quiz_id');
    }

    public function attempts()
    {
        return $this->hasMany(QuizAttempt::class, 'quiz_id');
    }

    public function totalMarks()
    {
        return (int) $this->questions()->sum('marks');
    }
}
