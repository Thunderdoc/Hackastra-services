<?php

namespace App\Models;

use Eloquent;

class QuizAttempt extends Eloquent
{
    protected $fillable = ['quiz_id', 'student_record_id', 'score', 'total', 'submitted_at'];

    protected $casts = [
        'score' => 'integer',
        'total' => 'integer',
        'submitted_at' => 'datetime',
    ];

    public function quiz()
    {
        return $this->belongsTo(Quiz::class, 'quiz_id');
    }

    public function student_record()
    {
        return $this->belongsTo(StudentRecord::class, 'student_record_id');
    }

    public function answers()
    {
        return $this->hasMany(QuizAnswer::class, 'attempt_id');
    }
}
