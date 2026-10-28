<?php

namespace App\Models;

use App\User;
use Eloquent;

class PtmBooking extends Eloquent
{
    protected $table = 'ptm_bookings';

    protected $fillable = [
        'ptm_slot_id', 'parent_id', 'student_user_id',
    ];

    public function slot()
    {
        return $this->belongsTo(PtmSlot::class, 'ptm_slot_id');
    }

    public function parent()
    {
        return $this->belongsTo(User::class, 'parent_id');
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_user_id');
    }
}
