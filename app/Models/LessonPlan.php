<?php

namespace App\Models;

use App\User;
use Eloquent;

class LessonPlan extends Eloquent
{
    protected $fillable = [
        'teacher_id', 'my_class_id', 'section_id', 'subject_id',
        'week_start', 'topic', 'notes',
    ];

    protected $casts = [
        'week_start' => 'date',
    ];

    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function my_class()
    {
        return $this->belongsTo(MyClass::class);
    }

    public function section()
    {
        return $this->belongsTo(Section::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }
}
