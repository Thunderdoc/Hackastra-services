<?php

namespace App\Models;

use App\User;
use Eloquent;

class StudyMaterial extends Eloquent
{
    protected $fillable = [
        'teacher_id', 'my_class_id', 'section_id', 'subject_id', 'title',
        'description', 'file_path', 'link_url',
    ];

    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function my_class()
    {
        return $this->belongsTo(MyClass::class);
    }

    /** CamelCase alias — CS2's blades read $material->myClass. */
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
