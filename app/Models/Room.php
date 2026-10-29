<?php

namespace App\Models;

use Eloquent;

/**
 * T3: a physical teaching space. kind 'lab' rooms are the only
 * rooms a needs_lab subject may be placed in; any room may host
 * a regular period. Capacity is advisory for placement (the
 * generator warns when a class is bigger than the room) and is
 * shown to the Admin on the Rooms page.
 */
class Room extends Eloquent
{
    protected $table = 'rooms';

    protected $fillable = ['name', 'kind', 'capacity'];

    protected $casts = ['capacity' => 'integer'];

    public static function kinds(): array
    {
        return ['classroom' => 'Classroom', 'lab' => 'Lab'];
    }
}
