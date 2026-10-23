<?php

namespace App\Models;

use App\User;
use Eloquent;

class LibraryFine extends Eloquent
{
    protected $fillable = [
        'book_request_id', 'user_id', 'amount', 'days_overdue', 'reason',
        'status', 'payment_record_id', 'imposed_by',
    ];

    public function book_request()
    {
        return $this->belongsTo(BookRequest::class, 'book_request_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function payment_record()
    {
        return $this->belongsTo(PaymentRecord::class, 'payment_record_id');
    }
}
