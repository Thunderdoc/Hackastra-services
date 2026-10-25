<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Staff attendance register (Wave 13, HR-lite): one row per staff
 * member per day, marked by the Admin — the same present / absent /
 * late vocabulary as the student register, and the same lock rule:
 * once a day is marked it cannot be re-marked (unique date+user).
 */
class StaffAttendance extends Model
{
    public const STATUSES = ['present', 'absent', 'late'];

    protected $fillable = ['user_id', 'date', 'status', 'marked_by'];

    public function user()
    {
        return $this->belongsTo(\App\User::class, 'user_id');
    }
}
