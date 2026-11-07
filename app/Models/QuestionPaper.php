<?php

namespace App\Models;

use App\User;
use Eloquent;

class QuestionPaper extends Eloquent
{
    protected $fillable = [
        'teacher_id', 'created_by', 'my_class_id', 'section_id', 'subject_id',
        'exam_id', 'title', 'exam_label', 'duration_minutes', 'total_marks',
        'seed', 'spec', 'status', 'allow_repeat_questions',
        'pdf_path', 'pdf_generated_at', 'answer_key_pdf_path',
    ];

    protected $casts = [
        'spec' => 'array',
        'allow_repeat_questions' => 'boolean',
        'pdf_generated_at' => 'datetime',
        'total_marks' => 'integer',
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

    public function exam()
    {
        return $this->belongsTo(Exam::class, 'exam_id');
    }

    public function questions()
    {
        return $this->hasMany(QuestionPaperQuestion::class, 'question_paper_id')
            ->orderBy('section_key')->orderBy('position')->orderBy('id');
    }

    public function suggestions()
    {
        return $this->hasMany(AiQuestionSuggestion::class, 'question_paper_id');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /** The exam name a printed paper carries: the linked exam, else the free-text label. */
    public function examName(): ?string
    {
        if ($this->exam) {
            return $this->exam->name;
        }
        return $this->exam_label ?: null;
    }

    public function hasShortfall(): bool
    {
        return !empty($this->spec['shortfall'] ?? null);
    }
}
