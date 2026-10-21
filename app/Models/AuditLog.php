<?php

namespace App\Models;

use App\User;
use Eloquent;

class AuditLog extends Eloquent
{
    protected $table = 'audit_logs';

    protected $fillable = [
        'user_id', 'action', 'subject_type', 'subject_id', 'description', 'ip_address',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
