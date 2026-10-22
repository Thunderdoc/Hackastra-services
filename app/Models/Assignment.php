<?php

namespace App\Models;

use App\User;
use Eloquent;

class Assignment extends Eloquent
{
    protected $fillable = [
        'teacher_id', 'my_class_id', 'section_id', 'subject_id', 'title',
        'description', 'attachment_path', 'max_marks', 'due_at', 'allow_late',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'allow_late' => 'boolean',
        'max_marks' => 'decimal:2',
    ];

    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function my_class()
    {
        return $this->belongsTo(MyClass::class);
    }

    /** CamelCase alias — CS2's blades read $assignment->myClass. */
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

    public function submissions()
    {
        return $this->hasMany(AssignmentSubmission::class);
    }

    /** Has the due moment passed (late submissions closed unless allowed)? */
    public function isPastDue(): bool
    {
        return $this->due_at !== null && now()->greaterThan($this->due_at);
    }
}
