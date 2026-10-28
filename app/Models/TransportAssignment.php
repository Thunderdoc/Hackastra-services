<?php

namespace App\Models;

use Eloquent;

class TransportAssignment extends Eloquent
{
    protected $fillable = ['student_record_id', 'transport_vehicle_id', 'transport_stop_id'];

    public function studentRecord()
    {
        return $this->belongsTo(StudentRecord::class, 'student_record_id');
    }

    public function vehicle()
    {
        return $this->belongsTo(TransportVehicle::class, 'transport_vehicle_id');
    }

    public function stop()
    {
        return $this->belongsTo(TransportStop::class, 'transport_stop_id');
    }

    public function fees()
    {
        return $this->hasMany(TransportFee::class, 'transport_assignment_id');
    }
}
