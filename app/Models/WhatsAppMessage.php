<?php

namespace App\Models;

use App\User;
use Eloquent;

/**
 * F1 (Round 4, design §2.1) — one WhatsApp message: outbox row
 * while queued, sent-log row forever after. Statuses: queued |
 * sent | mocked | delivered | read | failed | skipped_no_phone |
 * skipped_opt_out | skipped_disabled | suppressed_cooldown.
 */
class WhatsAppMessage extends Eloquent
{
    protected $table = 'whatsapp_messages';

    protected $fillable = [
        'parent_user_id', 'student_record_id', 'recipient_phone',
        'event', 'template_name', 'template_language', 'params',
        'body_preview', 'related_type', 'related_id', 'dedupe_key',
        'status', 'provider_message_id', 'error_code', 'error_message',
        'attempts', 'triggered_by', 'sent_at',
    ];

    protected $casts = [
        'params' => 'array',
        'sent_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function parent()
    {
        return $this->belongsTo(User::class, 'parent_user_id');
    }

    public function studentRecord()
    {
        return $this->belongsTo(StudentRecord::class, 'student_record_id');
    }

    /** Masked phone for every UI surface: +91 •••• ••{last4}. */
    public function maskedPhone(): string
    {
        $phone = preg_replace('/\D+/', '', (string) $this->recipient_phone);
        if (!$phone) {
            return '—';
        }
        $cc = strlen($phone) > 10 ? substr($phone, 0, strlen($phone) - 10) : '';

        return ($cc !== '' ? '+' . $cc : '') . ' •••• ••' . substr($phone, -4);
    }
}
