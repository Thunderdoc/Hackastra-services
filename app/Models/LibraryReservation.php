<?php

namespace App\Models;

use App\User;
use Illuminate\Database\Eloquent\Model;

class LibraryReservation extends Model
{
    /** Reservation lifecycle values stored in the `status` column. */
    public const STATUS_RESERVED = 'reserved';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_COLLECTED = 'collected';

    protected $fillable = [
        'book_id', 'user_id', 'student_record_id', 'status',
    ];

    public function book()
    {
        return $this->belongsTo(Book::class, 'book_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function studentRecord()
    {
        return $this->belongsTo(StudentRecord::class, 'student_record_id');
    }

    /** Only a still-open reservation blocks a duplicate and counts as active. */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_RESERVED);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_RESERVED;
    }
}
