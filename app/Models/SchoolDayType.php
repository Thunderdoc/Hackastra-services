<?php

namespace App\Models;

use Eloquent;

/**
 * One special date on the school calendar (T2). A date with no
 * row is a normal day. Managed by the school Admin from the
 * School Events page; read through App\Support\DayType.
 */
class SchoolDayType extends Eloquent
{
    protected $table = 'school_day_types';

    protected $fillable = [
        'date', 'type', 'half_day_periods', 'note', 'updated_by',
    ];

    protected $casts = [
        'date' => 'date',
        'half_day_periods' => 'integer',
    ];
}
