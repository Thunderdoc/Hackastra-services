<?php

namespace App\Models;

use App\User;
use Eloquent;

class ExamDuty extends Eloquent
{
    protected $fillable = ['exam_date_id', 'teacher_id', 'assigned_by'];

    public function exam_date()
    {
        return $this->belongsTo(ExamDate::class, 'exam_date_id');
    }

    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }
}
