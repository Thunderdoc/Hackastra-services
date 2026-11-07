<?php

namespace App\Models;

use App\User;
use Eloquent;

class AiQuestionSuggestion extends Eloquent
{
    protected $fillable = [
        'teacher_id', 'my_class_id', 'subject_id', 'section_id', 'question_paper_id',
        'question_type', 'question', 'options', 'correct_index', 'model_answer',
        'marks', 'chapter', 'difficulty', 'status', 'ai_model', 'prompt_context',
        'reviewed_by', 'reviewed_at', 'bank_question_id',
    ];

    protected $casts = [
        'options' => 'array',
        'prompt_context' => 'array',
        'correct_index' => 'integer',
        'marks' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function my_class()
    {
        return $this->belongsTo(MyClass::class, 'my_class_id');
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function section()
    {
        return $this->belongsTo(Section::class, 'section_id');
    }

    public function paper()
    {
        return $this->belongsTo(QuestionPaper::class, 'question_paper_id');
    }

    public function bank_question()
    {
        return $this->belongsTo(QuestionBankQuestion::class, 'bank_question_id');
    }
}
