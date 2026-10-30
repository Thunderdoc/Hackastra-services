<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The server-side start stamp for one student's run at one
 * timed quiz (see the 2026_10_30_000018 migration). Created the
 * first time the attempt page opens; never refreshed, so the
 * deadline survives page reloads.
 */
class QuizAttemptStart extends Model
{
    protected $fillable = ['quiz_id', 'student_record_id', 'started_at'];

    protected $casts = [
        'started_at' => 'datetime',
    ];

    public function quiz()
    {
        return $this->belongsTo(Quiz::class);
    }
}
