<?php

namespace App\Models;

use App\User;
use Eloquent;

class Concession extends Eloquent
{
    protected $fillable = ['student_record_id', 'fee_head_id', 'type', 'value', 'reason', 'approved_by'];

    public function student_record()
    {
        return $this->belongsTo(StudentRecord::class);
    }

    public function fee_head()
    {
        return $this->belongsTo(FeeHead::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Discount this single concession gives on its head, computed
     * live: amount-type is the value itself; percent-type is that
     * percent of the head amount.
     */
    public function discountAmount()
    {
        $headAmount = (float) optional($this->fee_head)->amount;
        if ($this->type === 'percent') {
            return $headAmount * ((float) $this->value) / 100;
        }

        return (float) $this->value;
    }
}
