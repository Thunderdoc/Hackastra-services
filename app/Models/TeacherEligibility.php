<?php

namespace App\Models;

use App\User;
use Eloquent;

class TeacherEligibility extends Eloquent
{
    protected $fillable = ['teacher_id', 'subject_name'];

    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }
}
