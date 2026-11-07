<?php

namespace App\Models;

use Eloquent;

class QuestionPaperQuestion extends Eloquent
{
    protected $fillable = [
        'question_paper_id', 'bank_question_id', 'source', 'section_key', 'position',
        'question_type', 'question', 'options', 'correct_index', 'model_answer',
        'marks', 'chapter', 'difficulty', 'text_hash',
    ];

    protected $casts = [
        'options' => 'array',
        'correct_index' => 'integer',
        'marks' => 'integer',
        'position' => 'integer',
    ];

    public function paper()
    {
        return $this->belongsTo(QuestionPaper::class, 'question_paper_id');
    }

    public function bank_question()
    {
        return $this->belongsTo(QuestionBankQuestion::class, 'bank_question_id');
    }
}
