<?php

namespace App\Models;

use App\User;
use Eloquent;

/**
 * An owner-private saved report configuration (wave 31b).
 * Templates are never shared: there is no sharing permission model,
 * so a template belongs to exactly one user and only that user may
 * load, edit, duplicate, or delete it. The config is a plain array
 * rendered by ReportEngine — the same shape presets use.
 */
class ReportTemplate extends Eloquent
{
    protected $fillable = ['user_id', 'name', 'config'];

    protected $casts = ['config' => 'array'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
