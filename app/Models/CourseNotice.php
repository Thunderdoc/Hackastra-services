<?php

namespace App\Models;

use App\User;
use Eloquent;

/**
 * A teacher's notice inside one subject's course (LMS course
 * build, 2026-11-08). Scoped to a subject and a class, and to a
 * section when section_id is set (null = the whole class sees
 * it). A pinned notice renders above everything else in the
 * course until the teacher unpins it.
 */
class CourseNotice extends Eloquent
{
    protected $fillable = [
        'teacher_id', 'my_class_id', 'section_id', 'subject_id', 'body',
        'is_pinned', 'seq',
    ];

    protected $casts = [
        'is_pinned' => 'boolean',
        'seq' => 'integer',
    ];

    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function my_class()
    {
        return $this->belongsTo(MyClass::class);
    }

    /** CamelCase alias, matching the other LMS models. */
    public function myClass()
    {
        return $this->my_class();
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
