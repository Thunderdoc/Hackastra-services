<?php

namespace App\Models;

use App\Helpers\Qs;
use Eloquent;

class Circular extends Eloquent
{
    protected $table = 'circulars';

    protected $fillable = [
        'title', 'body', 'audience', 'priority', 'published_by',
        'starts_at', 'expires_at', 'is_published',
        'official_pdf_path', 'official_pdf_generated_at',
    ];

    protected $casts = [
        'starts_at' => 'date',
        'expires_at' => 'date',
        'is_published' => 'boolean',
        'official_pdf_generated_at' => 'datetime',
    ];

    /** Audiences a circular can target, with their display labels. */
    public static function audiences(): array
    {
        return [
            'all' => 'Everyone',
            'staff' => 'All Staff',
            'teachers' => 'Teachers',
            'students' => 'Students',
            'parents' => 'Parents',
        ];
    }

    /** Priorities, with their display labels. */
    public static function priorities(): array
    {
        return [
            'normal' => 'Normal',
            'important' => 'Important',
            'urgent' => 'Urgent',
        ];
    }

    /**
     * Which stored audiences a given user type may read.
     * Admins manage circulars rather than read a feed, so they map to
     * every audience; unknown types fall back to 'all' only.
     */
    public static function audiencesFor($userType): array
    {
        switch ($userType) {
            case 'student':
                return ['all', 'students'];
            case 'parent':
                return ['all', 'parents'];
            case 'teacher':
                return ['all', 'teachers', 'staff'];
            case 'accountant':
            case 'librarian':
                return ['all', 'staff'];
            case 'admin':
            case 'super_admin':
                return array_keys(self::audiences());
            default:
                return ['all'];
        }
    }

    public function publisher()
    {
        return $this->belongsTo(\App\User::class, 'published_by');
    }

    public function attachments()
    {
        return $this->hasMany(CircularAttachment::class, 'circular_id');
    }

    /**
     * The official reference number printed on the document and
     * shown in the list and detail views (Loop 105):
     * <APPCODE>/CIR/<session>/<zero-padded id>, e.g.
     * HA/CIR/2018-2019/0007. Derived deterministically from the
     * record plus the CURRENT settings (school app code + current
     * session), so every surface that renders it — list, detail,
     * print, PDF — computes the identical string; nothing is
     * stored, so it can never drift out of sync.
     */
    public function reference(): string
    {
        return Qs::getAppCode() . '/CIR/' . Qs::getCurrentSession()
            . '/' . str_pad((string) $this->id, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Live circulars: published and inside their display window.
     * A null start means "from now", a null expiry means "no end date".
     */
    public function scopePublished($query)
    {
        $today = now()->toDateString();

        return $query->where('is_published', true)
            ->where(function ($q) use ($today) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $today);
            })
            ->where(function ($q) use ($today) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>=', $today);
            });
    }

    /** Circulars addressed to the given user type. */
    public function scopeForAudience($query, $userType)
    {
        return $query->whereIn('audience', self::audiencesFor($userType));
    }

    /** Whether a reader of the given type may open this circular now. */
    public function isVisibleTo($userType): bool
    {
        if (!$this->is_published) {
            return false;
        }

        $today = now()->toDateString();

        if ($this->starts_at && $this->starts_at->toDateString() > $today) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->toDateString() < $today) {
            return false;
        }

        return in_array($this->audience, self::audiencesFor($userType), true);
    }

    /** Display status for the management table. */
    public function statusLabel(): string
    {
        if (!$this->is_published) {
            return 'Draft';
        }

        $today = now()->toDateString();

        if ($this->starts_at && $this->starts_at->toDateString() > $today) {
            return 'Scheduled';
        }

        if ($this->expires_at && $this->expires_at->toDateString() < $today) {
            return 'Expired';
        }

        return 'Live';
    }
}
