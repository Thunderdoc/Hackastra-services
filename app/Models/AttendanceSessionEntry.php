<?php

namespace App\Models;

use Eloquent;

/**
 * One student's mark inside one period-attendance session.
 * Statuses mirror the day-wise Attendance model so reports can use
 * the same (present + late) / marked counting rule everywhere.
 */
class AttendanceSessionEntry extends Eloquent
{
    public const STATUS_PRESENT = 'present';
    public const STATUS_ABSENT = 'absent';
    public const STATUS_LATE = 'late';

    protected $fillable = [
        'session_id', 'student_record_id', 'status',
    ];

    public static function statuses(): array
    {
        return [self::STATUS_PRESENT, self::STATUS_ABSENT, self::STATUS_LATE];
    }

    public function session()
    {
        return $this->belongsTo(AttendanceSession::class, 'session_id');
    }

    public function student_record()
    {
        return $this->belongsTo(StudentRecord::class);
    }
}
