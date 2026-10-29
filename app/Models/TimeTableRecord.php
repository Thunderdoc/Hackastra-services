<?php

namespace App\Models;

use Eloquent;

class TimeTableRecord extends Eloquent
{
    protected $fillable = ['name', 'my_class_id', 'section_id', 'exam_id', 'year',
        'approval_status', 'approval_submitted_by', 'approval_submitted_at', 'approved_by', 'approved_at'];

    /** In force = the Principal has approved this timetable. */
    public function isInForce(): bool
    {
        return ($this->approval_status ?? 'approved') === 'approved';
    }

    public function my_class()
    {
        return $this->belongsTo(MyClass::class);
    }

    public function section()
    {
        return $this->belongsTo(Section::class);
    }

    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }
}
