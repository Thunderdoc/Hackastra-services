<?php

namespace App\Models;

use App\User;
use Illuminate\Database\Eloquent\Model;

/**
 * T4 — a frozen copy of one timetable record's grid (slots +
 * cells) as JSON. Created on Principal approval and before a
 * regenerate replaces the grid; restored as a new draft.
 */
class TimetableSnapshot extends Model
{
    protected $fillable = ['ttr_id', 'label', 'payload', 'created_by'];

    protected $casts = ['payload' => 'array'];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cellCount(): int
    {
        return count($this->payload['cells'] ?? []);
    }
}
