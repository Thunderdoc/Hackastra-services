<?php

namespace App\Models;

use App\User;
use Illuminate\Database\Eloquent\Model;

class SentMail extends Model
{
    protected $fillable = [
        'sender_id', 'audience', 'audience_label', 'recipient_count',
        'subject', 'body', 'driver', 'status', 'error',
    ];

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
