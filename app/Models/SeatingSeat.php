<?php

namespace App\Models;

use Eloquent;

/**
 * F3 — one seated student in one plan (design §3.3). Snapshot row:
 * name / adm no / class / section / subject / room are stored AS
 * GENERATED so later roster or room edits never rewrite a printed
 * hall sheet; staleness is surfaced via the plan's source_hash.
 */
class SeatingSeat extends Eloquent
{
    protected $table = 'exam_seating_seats';

    protected $fillable = [
        'plan_id', 'room_id', 'exam_date_id', 'student_record_id',
        'my_class_id', 'section_id', 'student_name', 'adm_no',
        'class_name', 'section_name', 'subject_name', 'room_name', 'seat_no',
    ];

    protected $casts = [
        'seat_no' => 'integer',
    ];

    public function plan()
    {
        return $this->belongsTo(SeatingPlan::class, 'plan_id');
    }

    public function room()
    {
        return $this->belongsTo(Room::class, 'room_id');
    }
}
