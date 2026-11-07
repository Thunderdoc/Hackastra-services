<?php

namespace App\Models;

use Eloquent;

/**
 * The school's licence record (Round 5 Slice 4, P14) — an
 * SA-maintained record of the commercial relationship exactly
 * as sold: plan name as free text, a status, and dates. It is a
 * RECORD only: nothing in the app gates, limits or bills from
 * it, and there are deliberately no price fields.
 *
 * Single-school deployment: at most one CURRENT record — the
 * latest row. LicenceController edits that row or creates the
 * first one.
 */
class SchoolLicence extends Eloquent
{
    public const STATUSES = ['trial', 'active', 'past_due', 'ended'];

    protected $fillable = ['plan_name', 'status', 'starts_on', 'renews_on', 'notes'];

    protected $casts = [
        'starts_on' => 'date',
        'renews_on' => 'date',
    ];

    /** The current record for this school, or null when none exists. */
    public static function current(): ?self
    {
        return static::latest('id')->first();
    }

    public static function statusLabel(string $status): string
    {
        return [
            'trial' => 'Trial',
            'active' => 'Active',
            'past_due' => 'Past due',
            'ended' => 'Ended',
        ][$status] ?? ucwords(str_replace('_', ' ', $status));
    }
}
