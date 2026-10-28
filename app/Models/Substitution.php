<?php

namespace App\Models;

use App\User;
use Eloquent;

class Substitution extends Eloquent
{
    protected $fillable = [
        'time_table_id', 'date', 'absent_teacher_id',
        'substitute_teacher_id', 'created_by', 'reason',
    ];

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
