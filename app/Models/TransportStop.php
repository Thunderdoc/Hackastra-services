<?php

namespace App\Models;

use Eloquent;

class TransportStop extends Eloquent
{
    protected $fillable = ['transport_route_id', 'name', 'sequence'];

    public function route()
    {
        return $this->belongsTo(TransportRoute::class, 'transport_route_id');
    }

    public function assignments()
    {
        return $this->hasMany(TransportAssignment::class, 'transport_stop_id');
    }
}
