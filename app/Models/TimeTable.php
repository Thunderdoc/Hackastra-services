<?php

namespace App\Models;

use Eloquent;

class TimeTable extends Eloquent
{
    /* Agent D round 2: day_num is the weekday number the validator
       and the generator's busy map key on. It was missing here, so
       manually added cells (Manage store/update) silently stored
       NULL and never blocked generation or clashed correctly. */
    protected $fillable = ['ttr_id', 'ts_id', 'exam_date', 'day', 'day_num', 'timestamp_from', 'timestamp_to', 'subject_id',];

    public function time_slot()
    {
        return $this->belongsTo(TimeSlot::class, 'ts_id');
    }

    public function tt_record()
    {
        return $this->belongsTo(TimeTableRecord::class, 'ttr_id');
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }
}
