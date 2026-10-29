<?php

namespace App\Models;

use App\User;
use Eloquent;

class Substitution extends Eloquent
{
    protected $fillable = [
        'time_table_id', 'date', 'absent_teacher_id',
        'substitute_teacher_id', 'created_by', 'reason', 'status',
    ];

    /** System-arranged, awaiting the Admin's submit (owner order
     *  2026-10-29): no access and no substitute bell until then. */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isConfirmed(): bool
    {
        return $this->status !== 'pending';
    }

    public function scopeConfirmed($query)
    {
        return $query->where('status', 'confirmed');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    protected $casts = [
        'date' => 'date',
    ];

    public function time_table()
    {
        return $this->belongsTo(TimeTable::class, 'time_table_id');
    }

    public function absent_teacher()
    {
        return $this->belongsTo(User::class, 'absent_teacher_id');
    }

    public function substitute()
    {
        return $this->belongsTo(User::class, 'substitute_teacher_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
