<?php

namespace App\Models;

use Eloquent;

class Exam extends Eloquent
{
    protected $fillable = ['name', 'term', 'year', 'results_published', 'my_class_id'];

    protected $casts = [
        'results_published' => 'boolean',
    ];

    public function my_class()
    {
        return $this->belongsTo(MyClass::class);
    }

    public function subjectComponents()
    {
        return $this->hasMany(ExamSubjectComponent::class);
    }
}
