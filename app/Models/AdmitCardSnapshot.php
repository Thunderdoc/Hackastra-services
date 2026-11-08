<?php

namespace App\Models;

use Eloquent;

/**
 * ONE-TIME GENERATION freeze (owner standing rule 2026-11-08):
 * one frozen admit-card payload per (exam, student user id),
 * written by App\Support\AdmitCardFreezer at the moment the
 * Principal publishes the exam's admit cards. Student/parent
 * self-service renders THIS payload, never live data, so a card
 * the school has released cannot change underneath the family.
 * The fee gate is deliberately not frozen — it is evaluated live
 * on every view, before the snapshot is read.
 */
class AdmitCardSnapshot extends Eloquent
{
    protected $table = 'admit_card_snapshots';

    protected $fillable = [
        'exam_id', 'student_id', 'payload', 'frozen_by',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }

    public function student()
    {
        return $this->belongsTo(\App\User::class, 'student_id');
    }
}
