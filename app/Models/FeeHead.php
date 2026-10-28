<?php

namespace App\Models;

use Eloquent;

class FeeHead extends Eloquent
{
    protected $fillable = ['my_class_id', 'title', 'amount', 'year'];

    public function my_class()
    {
        return $this->belongsTo(MyClass::class);
    }

    public function installments()
    {
        return $this->hasMany(FeeInstallment::class);
    }

    public function concessions()
    {
        return $this->hasMany(Concession::class);
    }

    /**
     * Sum of this head's installments. Compared against amount by the
     * controller: a head saved with installments must balance exactly.
     */
    public function installmentsTotal()
    {
        return (int) $this->installments()->sum('amount');
    }

    /**
     * Total concession discount for one student on this head, computed
     * live (never stored): amount-type concessions subtract their
     * value; percent-type concessions subtract that percent of the
     * head amount. Multiple concessions on the same student + head
     * add up; the discount never exceeds the head amount.
     */
    public function concessionDiscountFor($studentRecordId)
    {
        $discount = 0.0;
        foreach ($this->concessions()->where('student_record_id', $studentRecordId)->get() as $c) {
            if ($c->type === 'percent') {
                $discount += ((float) $this->amount) * ((float) $c->value) / 100;
            } else {
                $discount += (float) $c->value;
            }
        }

        return min($discount, (float) $this->amount);
    }

    /**
     * Net payable for one student on this head: head amount minus
     * concession discount, computed live, floored at zero.
     */
    public function netFor($studentRecordId)
    {
        return max(0, (float) $this->amount - $this->concessionDiscountFor($studentRecordId));
    }
}
