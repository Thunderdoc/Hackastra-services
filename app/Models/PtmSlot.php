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
