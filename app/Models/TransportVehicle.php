<?php

namespace App\Models;

use Eloquent;

class TransportVehicle extends Eloquent
{
    protected $fillable = ['vehicle_no', 'capacity', 'transport_route_id'];

    public function route()
    {
        return $this->belongsTo(TransportRoute::class, 'transport_route_id');
    }

    public function assignments()
    {
        return $this->hasMany(TransportAssignment::class, 'transport_vehicle_id');
    }
}
