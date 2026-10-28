<?php

namespace App\Models;

use Eloquent;

class HostelRoom extends Eloquent
{
    protected $fillable = ['dorm_id', 'room_no', 'capacity'];

    public function dorm()
    {
        return $this->belongsTo(Dorm::class);
    }

    /**
     * Occupancy is always computed from StudentRecord rows carrying
     * this dorm_id + room_no — never stored on the room.
     */
    public function occupancy(): int
    {
        return StudentRecord::where('dorm_id', $this->dorm_id)
            ->where('dorm_room_no', $this->room_no)
            ->count();
    }

    public function isFull(): bool
    {
        return $this->occupancy() >= $this->capacity;
    }

    public function available(): int
    {
        return max(0, $this->capacity - $this->occupancy());
    }
}
