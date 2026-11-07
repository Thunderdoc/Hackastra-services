<?php

namespace App\Models;

use App\User;
use Eloquent;

class FeeReminderLog extends Eloquent
{
    protected $fillable = [
        'student_id', 'student_record_id', 'parent_user_id', 'year',
        'channel', 'trigger', 'rule_offset', 'due_date', 'fee_head_id',
        'installment_id', 'amount_due', 'sent_by', 'status',
        'provider_ref', 'dedupe_key',
    ];

    protected $casts = [
        'due_date' => 'date',
        'rule_offset' => 'integer',
        'amount_due' => 'integer',
    ];

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function student_record()
    {
        return $this->belongsTo(StudentRecord::class, 'student_record_id');
    }

    public function parent_user()
    {
        return $this->belongsTo(User::class, 'parent_user_id');
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
