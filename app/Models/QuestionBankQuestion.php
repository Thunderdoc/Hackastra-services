<?php

namespace App\Models;

use App\User;
use Eloquent;

class QuestionBankQuestion extends Eloquent
{
    protected $fillable = [
        'teacher_id', 'my_class_id', 'section_id', 'subject_id',
        'question', 'options', 'correct_index', 'marks',
        'chapter', 'difficulty', 'question_type', 'model_answer',
    ];

    protected $casts = [
        'options' => 'array',
        'correct_index' => 'integer',
        'marks' => 'integer',
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
}
