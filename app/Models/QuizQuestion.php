<?php

namespace App\Models;

use Eloquent;

class QuizQuestion extends Eloquent
{
    protected $fillable = ['quiz_id', 'question', 'options', 'correct_index', 'marks'];

    protected $casts = [
        'options' => 'array',
        'correct_index' => 'integer',
        'marks' => 'integer',
    ];

    public function quiz()
    {
        return $this->belongsTo(Quiz::class, 'quiz_id');
    }

    public function answers()
    {
        return $this->hasMany(QuizAnswer::class, 'question_id');
    }
}
