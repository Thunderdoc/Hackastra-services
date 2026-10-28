<?php

namespace App\Models;

use Eloquent;

class QuizAnswer extends Eloquent
{
    protected $fillable = ['attempt_id', 'question_id', 'selected_index', 'is_correct'];

    protected $casts = [
        'selected_index' => 'integer',
        'is_correct' => 'boolean',
    ];

    public function attempt()
    {
        return $this->belongsTo(QuizAttempt::class, 'attempt_id');
    }

    public function question()
    {
        return $this->belongsTo(QuizQuestion::class, 'question_id');
    }
}
