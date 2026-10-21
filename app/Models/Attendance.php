<?php

namespace App\Models;

use Eloquent;

class Attendance extends Eloquent
{
    protected $table = 'attendances';

    public const STATUS_PRESENT = 'present';
    public const STATUS_ABSENT = 'absent';
    public const STATUS_LATE = 'late';

    protected $fillable = [
        'student_record_id', 'date', 'status', 'remarks', 'recorded_by',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public static function statuses(): array
    {
        return [self::STATUS_PRESENT, self::STATUS_ABSENT, self::STATUS_LATE];
    }

    public function student_record()
    {
        return $this->belongsTo(StudentRecord::class);
    }

    public function recorder()
    {
        return $this->belongsTo(\App\User::class, 'recorded_by');
    }
}
