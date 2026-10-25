<?php

namespace App\Models;

use Eloquent;

class FeedbackItem extends Eloquent
{
    protected $table = 'feedback_items';

    protected $fillable = [
        'reporter_id', 'reporter_name', 'reporter_role', 'type', 'category',
        'priority', 'title', 'description', 'context', 'page_url',
        'user_agent', 'status', 'duplicate_of_id', 'admin_note',
        'forwarded_at', 'forwarded_by', 'sa_status', 'sa_reply',
        'reporter_seen_at',
    ];

    protected $casts = [
        'forwarded_at' => 'datetime',
        'reporter_seen_at' => 'datetime',
    ];

    /** Report types (what kind of message this is), with labels. */
    public static function types(): array
    {
        return [
            'problem' => 'Problem',
            'suggestion' => 'Suggestion',
            'complaint' => 'Complaint',
            'appreciation' => 'Appreciation',
        ];
    }

    /** Reporter-assessed priorities, with display labels. */
    public static function priorities(): array
    {
        return [
            'low' => 'Low',
            'medium' => 'Medium',
            'high' => 'High',
            'urgent' => 'Urgent',
        ];
    }

    /** Categories a report can fall under, with display labels. */
    public static function categories(): array
    {
        return [
            'facility' => 'Facility / Building',
            'academics' => 'Academics',
            'fees' => 'Fees & Payments',
            'transport' => 'Transport',
            'food' => 'Food / Canteen',
            'safety' => 'Safety',
            'app_bug' => 'App / Software Issue',
            'staff' => 'Staff',
            'other' => 'Other',
        ];
    }

    /** School-side (Admin) workflow statuses, with display labels. */
    public static function statuses(): array
    {
        return [
            'new' => 'New',
            'acknowledged' => 'Acknowledged',
            'in_progress' => 'In Progress',
            'resolved' => 'Resolved',
            'wont_fix' => "Won't Fix",
            'duplicate' => 'Duplicate',
        ];
    }

    /** Super Admin handling statuses, with display labels. */
    public static function saStatuses(): array
    {
        return [
            'received' => 'Received',
            'in_progress' => 'In Progress',
            'fixed' => 'Fixed',
            'declined' => 'Declined',
        ];
    }

    /** Statuses the Admin may set directly (duplicate is set by merging). */
    public static function adminSettableStatuses(): array
    {
        return array_diff_key(self::statuses(), ['duplicate' => true]);
    }

    public function reporter()
    {
        return $this->belongsTo(\App\User::class, 'reporter_id');
    }

    /** Up to 3 screenshots / files the reporter attached. */
    public function attachments()
    {
        return $this->hasMany(FeedbackAttachment::class, 'feedback_id');
    }

    public function forwarder()
    {
        return $this->belongsTo(\App\User::class, 'forwarded_by');
    }

    /** The canonical item this report was merged into (when duplicate). */
    public function duplicateOf()
    {
        return $this->belongsTo(self::class, 'duplicate_of_id');
    }

    /** Reports merged into this one. */
    public function duplicates()
    {
        return $this->hasMany(self::class, 'duplicate_of_id');
    }

    public function scopeForwarded($query)
    {
        return $query->whereNotNull('forwarded_at');
    }

    public function isForwarded(): bool
    {
        return !is_null($this->forwarded_at);
    }

    /**
     * Ticket rule (Wave 16, owner order 2026-10-25): once the Super
     * Admin closes a report (sa_status Fixed), the closure is
     * terminal on the platform side — the SA cannot reopen it, in
     * the UI or via the API. Only the school Admin can reopen it
     * (re-escalation), which puts it back to Received for the SA.
     */
    public function isClosedForSa(): bool
    {
        return $this->sa_status === 'fixed';
    }

    public function isDuplicate(): bool
    {
        return $this->status === 'duplicate';
    }

    public function categoryLabel(): string
    {
        return self::categories()[$this->category] ?? $this->category;
    }

    public function typeLabel(): string
    {
        return self::types()[$this->type] ?? $this->type;
    }

    public function priorityLabel(): string
    {
        return self::priorities()[$this->priority] ?? $this->priority;
    }

    /**
     * One-line auto-log summary for the detail views, eg:
     * "Reported from: /payments/manage · Android Chrome · by Kabir Anand (Parent)".
     * The browser/device is a light parse of the stored user agent —
     * enough for triage, never a full UA library.
     */
    public function autoLogLine(): string
    {
        $bits = [];
        if ($this->page_url) {
            $bits[] = 'from: ' . $this->page_url;
        }
        if ($ua = $this->browserHint()) {
            $bits[] = $ua;
        }
        $bits[] = 'by ' . $this->reporter_name . ' (' . ucwords(str_replace('_', ' ', $this->reporter_role)) . ')';

        return implode(' · ', $bits);
    }

    public function browserHint(): ?string
    {
        $ua = (string) $this->user_agent;
        if ($ua === '') {
            return null;
        }

        $device = strpos($ua, 'Android') !== false ? 'Android'
            : ((strpos($ua, 'iPhone') !== false || strpos($ua, 'iPad') !== false) ? 'iOS'
            : (strpos($ua, 'Windows') !== false ? 'Windows'
            : (strpos($ua, 'Macintosh') !== false ? 'Mac' : null)));
        $browser = strpos($ua, 'Edg/') !== false ? 'Edge'
            : (strpos($ua, 'Chrome') !== false ? 'Chrome'
            : (strpos($ua, 'Firefox') !== false ? 'Firefox'
            : (strpos($ua, 'Safari') !== false ? 'Safari' : null)));

        $hint = trim(($device ? $device . ' ' : '') . ($browser ?: ''));
        return $hint !== '' ? $hint : 'Unknown browser';
    }

    public function statusLabel(): string
    {
        return self::statuses()[$this->status] ?? $this->status;
    }

    public function saStatusLabel(): string
    {
        return $this->sa_status ? (self::saStatuses()[$this->sa_status] ?? $this->sa_status) : '—';
    }

    /**
     * The status a reporter should read for this item: a duplicate
     * follows its canonical item's live status (school-side, and the
     * Super Admin's once the canonical item has been forwarded).
     */
    public function effectiveStatusLabel(): string
    {
        if ($this->isDuplicate() && $this->duplicateOf) {
            return $this->duplicateOf->effectiveStatusLabel();
        }

        if ($this->isForwarded() && $this->sa_status) {
            return 'Super Admin: ' . $this->saStatusLabel();
        }

        return $this->statusLabel();
    }

    /**
     * Topbar bell hook: how many of the user's own reports changed
     * since they last opened My Feedback (their own fresh submissions
     * in 'new' state do not count — nothing has happened to them yet).
     */
    public static function unseenUpdatesCountFor($userId): int
    {
        return static::where('reporter_id', $userId)
            ->where('status', '<>', 'new')
            ->where(function ($q) {
                $q->whereNull('reporter_seen_at')
                    ->orWhereColumn('updated_at', '>', 'reporter_seen_at');
            })
            ->count();
    }
}
