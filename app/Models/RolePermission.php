<?php

namespace App\Models;

use App\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Custom permission grant (2026-10-28): one row = one user may do
 * one whitelisted action they could not do by user_type alone.
 * Per-user and revocable (delete the row). Duplicates (same user +
 * same key) are refused in RolePermissionController@store and by the
 * unique index on the table.
 */
class RolePermission extends Model
{
    protected $fillable = ['user_id', 'permission', 'granted_by'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function granter()
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
