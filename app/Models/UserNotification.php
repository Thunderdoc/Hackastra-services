<?php

namespace App\Models;

use App\User;
use Eloquent;

class UserNotification extends Eloquent
{
    protected $table = 'user_notifications';

    protected $fillable = [
        'user_id', 'type', 'title', 'body', 'link', 'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    public function isUnread(): bool
    {
        return $this->read_at === null;
    }
}
