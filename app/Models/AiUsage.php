<?php

namespace App\Models;

use App\User;
use Eloquent;

class AiUsage extends Eloquent
{
    protected $table = 'ai_usage';

    protected $fillable = [
        'user_id', 'task', 'model', 'prompt_tokens', 'completion_tokens', 'status',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
