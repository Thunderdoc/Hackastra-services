<?php

namespace App\Models;

use App\User;
use Illuminate\Database\Eloquent\Model;

class BookRequest extends Model
{
    /** Loan lifecycle values stored in the `status` column. */
    public const STATUS_ISSUED = 'issued';
    public const STATUS_RETURNED = 'returned';

    protected $fillable = [
        'book_id', 'user_id', 'start_date', 'end_date', 'returned', 'status',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'returned' => 'boolean',
    ];

    public function book()
    {
        return $this->belongsTo(Book::class, 'book_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Loans still out with a borrower. */
    public function scopeActive($query)
    {
        return $query->where('returned', '0');
    }

    /** Loans that have been brought back. */
    public function scopeReturned($query)
    {
        return $query->where('returned', '1');
    }

    /** A loan is overdue when it is still out past its due date. */
    public function isOverdue(): bool
    {
        return !$this->returned && $this->end_date && $this->end_date->isPast();
    }
}
