<?php

namespace App\Models;

use App\User;
use Eloquent;

class Subject extends Eloquent
{
    protected $fillable = ['name', 'my_class_id', 'teacher_id', 'slug', 'periods_per_week', 'subject_type', 'needs_double', 'needs_lab', 'stream'];

    public function my_class()
    {
        return $this->belongsTo(MyClass::class);
    }

    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function examComponents()
    {
        return $this->hasMany(ExamSubjectComponent::class);
    }
}
