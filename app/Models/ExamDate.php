<?php

namespace App\Models;

use Eloquent;

class ExamDate extends Eloquent
{
    protected $fillable = ['exam_id', 'my_class_id', 'subject_id', 'exam_date', 'start_time', 'note'];

    /* exam_date / start_time stay plain strings ('Y-m-d' / 'H:i:s' as
       stored): the date-sheet views print them into date/time inputs
       and sort keys, and format them through Carbon at render time. */

    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }

    public function my_class()
    {
        return $this->belongsTo(MyClass::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }
}
