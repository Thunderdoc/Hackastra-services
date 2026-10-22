<?php

namespace App\Models;

use App\User;
use Eloquent;

class GatewayTransaction extends Eloquent
{
    protected $fillable = [
        'gateway', 'order_id', 'payment_id', 'student_id', 'pr_id',
        'invoice_ref', 'amount', 'status', 'receipt_id', 'payload_hash',
    ];

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function paymentRecord()
    {
        return $this->belongsTo(PaymentRecord::class, 'pr_id');
    }

    public function receipt()
    {
        return $this->belongsTo(Receipt::class, 'receipt_id');
    }
}
