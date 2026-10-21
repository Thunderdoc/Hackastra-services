<?php

namespace App\Models;

use Eloquent;

class SchoolEvent extends Eloquent
{
    protected $table = 'school_events';

    protected $fillable = [
        'title', 'type', 'description', 'starts_at', 'ends_at', 'all_day', 'created_by',
    ];

    protected $casts = [
        'starts_at' => 'date',
        'ends_at' => 'date',
        'all_day' => 'boolean',
    ];

    /** Types shown on the dashboard, with their badge labels. */
    public static function types(): array
    {
        return [
            'examination' => 'Examination',
            'meeting' => 'Meeting',
            'fee' => 'Fee',
            'holiday' => 'Holiday',
            'event' => 'Event',
        ];
    }

    public function creator()
    {
        return $this->belongsTo(\App\User::class, 'created_by');
    }
}
