<?php

namespace App\Models;

use App\User;
use Eloquent;

class PtmSlot extends Eloquent
{
    protected $table = 'ptm_slots';

    protected $fillable = [
        'date', 'start_time', 'end_time', 'teacher_id', 'my_class_id', 'section_id', 'created_by',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    /** When this slot finishes (date + end time). */
    public function endsAt(): \Carbon\Carbon
    {
        return \Carbon\Carbon::parse($this->date->format('Y-m-d') . ' ' . substr((string) $this->end_time, 0, 5));
    }

    /** A slot whose end time has passed can no longer be booked
     *  (loophole closed 2026-10-30: parents could book meetings
     *  scheduled in the past). */
    public function hasEnded(): bool
    {
        return $this->endsAt()->isPast();
    }

    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function my_class()
    {
        return $this->belongsTo(MyClass::class, 'my_class_id');
    }

    public function section()
    {
        return $this->belongsTo(Section::class, 'section_id');
    }

    public function booking()
    {
        return $this->hasOne(PtmBooking::class, 'ptm_slot_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isBooked(): bool
    {
        return $this->booking !== null;
    }
}
