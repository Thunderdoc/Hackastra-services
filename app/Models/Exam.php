<?php

namespace App\Models;

use Eloquent;

class Exam extends Eloquent
{
    protected $fillable = ['name', 'term', 'year'];

    public function subjectComponents()
    {
        return $this->hasMany(ExamSubjectComponent::class);
    }
}
