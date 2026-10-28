<?php

namespace App\Models;

use Eloquent;

class TransportRoute extends Eloquent
{
    protected $fillable = ['name', 'description'];

    public function stops()
    {
        return $this->hasMany(TransportStop::class, 'transport_route_id')->orderBy('sequence');
    }

    public function vehicles()
    {
        return $this->hasMany(TransportVehicle::class, 'transport_route_id');
    }
}
