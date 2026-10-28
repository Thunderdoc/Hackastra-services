<?php

namespace App\Models;

use App\User;
use Eloquent;

/**
 * One submitted period-attendance register: a teacher took attendance
 * for one timetable period on one date, recorded the topic taught,
 * and submitted. Submission is final — v1 ships no update or delete
 * path, and the (date, time_table_id) unique key backs that up at
 * the database level.
 */
class AttendanceSession extends Eloquent
{
    public const STATUS_SUBMITTED = 'submitted';

    protected $fillable = [
        'date', 'time_table_id', 'my_class_id', 'section_id',
        'subject_id', 'teacher_id', 'topic', 'status', 'submitted_at',
    ];

    protected $casts = [
        'date' => 'date',
        'submitted_at' => 'datetime',
    ];

    public function time_table()
    {
        return $this->belongsTo(TimeTable::class, 'time_table_id');
    }

    public function my_class()
    {
        return $this->belongsTo(MyClass::class);
    }

    public function section()
    {
        return $this->belongsTo(Section::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function entries()
    {
        return $this->hasMany(AttendanceSessionEntry::class, 'session_id');
    }

    public function scopeForTeacher($query, $teacherId)
    {
        return $query->where('teacher_id', $teacherId);
    }

    public function scopeForClass($query, $classId)
    {
        return $query->where('my_class_id', $classId);
    }

    public function scopeForSubject($query, $subjectId)
    {
        return $query->where('subject_id', $subjectId);
    }

    public function scopeBetweenDates($query, $from, $to)
    {
        return $query->whereBetween('date', [
            $from instanceof \DateTimeInterface ? $from->format('Y-m-d') : $from,
            $to instanceof \DateTimeInterface ? $to->format('Y-m-d') : $to,
        ]);
    }

    /**
     * Effective-section scope — the SAME rule the submitted-register
     * view uses: a session belongs to a section when its own
     * section_id is set, else when its timetable record carries
     * that section. Class-grid sessions (record section NULL)
     * match no section filter.
     */
    public function scopeForEffectiveSection($query, $sectionId)
    {
        return $query->where(function ($q) use ($sectionId) {
            $q->where('section_id', $sectionId)
                ->orWhere(function ($q2) use ($sectionId) {
                    $q2->whereNull('section_id')
                        ->whereHas('time_table.tt_record', function ($q3) use ($sectionId) {
                            $q3->where('section_id', $sectionId);
                        });
                });
        });
    }

    /** Count of entries with the given status (present/absent/late). */
    public function countByStatus(string $status): int
    {
        if ($this->relationLoaded('entries')) {
            return $this->entries->where('status', $status)->count();
        }

        return $this->entries()->where('status', $status)->count();
    }
}
