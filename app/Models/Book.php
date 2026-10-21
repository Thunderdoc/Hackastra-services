<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Book extends Model
{
    protected $fillable = [
        'name', 'my_class_id', 'description', 'author', 'book_type',
        'url', 'location', 'total_copies', 'issued_copies',
    ];

    protected $casts = [
        'total_copies' => 'integer',
        'issued_copies' => 'integer',
    ];

    /** Book types offered in the catalogue form. */
    public static function types(): array
    {
        return [
            'Book' => 'Book',
            'Textbook' => 'Textbook',
            'Journal' => 'Journal',
            'Magazine' => 'Magazine',
            'E-Book' => 'E-Book',
            'Other' => 'Other',
        ];
    }

    public function myClass()
    {
        return $this->belongsTo(MyClass::class, 'my_class_id');
    }

    public function requests()
    {
        return $this->hasMany(BookRequest::class, 'book_id');
    }

    /** Loans of this book that have not been returned yet. */
    public function activeRequests()
    {
        return $this->requests()->where('returned', '0');
    }

    /** Copies currently on the shelf (never below zero). */
    public function availableCopies(): int
    {
        return max(0, (int) $this->total_copies - (int) $this->issued_copies);
    }
}
