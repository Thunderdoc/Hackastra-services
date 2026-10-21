<?php

namespace App\Models;

use Eloquent;

class MarkComponent extends Eloquent
{
    protected $fillable = ['mark_id', 'component_id', 'score'];

    protected $casts = [
        'score' => 'integer',
    ];

    public function mark()
    {
        return $this->belongsTo(Mark::class);
    }

    public function component()
    {
        return $this->belongsTo(ExamSubjectComponent::class, 'component_id');
    }
}
