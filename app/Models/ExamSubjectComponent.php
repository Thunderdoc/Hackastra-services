<?php

namespace App\Models;

use Eloquent;

class ExamSubjectComponent extends Eloquent
{
    protected $fillable = ['exam_id', 'subject_id', 'name', 'short_code', 'max_marks', 'sort'];

    protected $casts = [
        'max_marks' => 'integer',
        'sort' => 'integer',
    ];

    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function markComponents()
    {
        return $this->hasMany(MarkComponent::class, 'component_id');
    }
}
