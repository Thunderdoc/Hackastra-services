<?php

namespace App\Models;

use Eloquent;

class TransportFee extends Eloquent
{
    protected $fillable = ['transport_assignment_id', 'month', 'amount', 'paid', 'paid_at'];

    protected $casts = [
        'paid' => 'boolean',
        'paid_at' => 'datetime',
    ];

    public function assignment()
    {
        return $this->belongsTo(TransportAssignment::class, 'transport_assignment_id');
    }
}
