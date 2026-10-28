<?php

namespace App\Models;

use Eloquent;

class FeeInstallment extends Eloquent
{
    protected $fillable = ['fee_head_id', 'installment_no', 'due_date', 'amount'];

    protected $casts = [
        'due_date' => 'date',
    ];

    public function fee_head()
    {
        return $this->belongsTo(FeeHead::class);
    }
}
