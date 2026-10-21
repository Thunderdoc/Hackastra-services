<?php

namespace App\Models;

use Eloquent;

class Exam extends Eloquent
{
    protected $fillable = ['name', 'term', 'year', 'results_published'];

    protected $casts = [
        'results_published' => 'boolean',
    ];

    public function subjectComponents()
    {
        return $this->hasMany(ExamSubjectComponent::class);
    }
}
