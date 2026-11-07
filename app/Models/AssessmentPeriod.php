<?php

namespace App\Models;

use Eloquent;

/**
 * One named assessment period of one session (Exam Patterns
 * Phase 1, R1 design §1.1): kind term|unit|standalone, an
 * optional feeds_period_id (unit -> the term period whose exams'
 * Internal component its marks feed), counts_toward_annual,
 * sort_order, and the transitional legacy_term (1/2/3) that keeps
 * the legacy tex{term} readers unambiguous.
 */
class AssessmentPeriod extends Eloquent
{
    protected $fillable = [
        'year', 'name', 'kind', 'feeds_period_id', 'counts_toward_annual',
        'sort_order', 'legacy_term', 'is_active',
    ];

    protected $casts = [
        'counts_toward_annual' => 'boolean',
        'is_active' => 'boolean',
        'legacy_term' => 'integer',
        'sort_order' => 'integer',
        'feeds_period_id' => 'integer',
    ];

    public function exams()
    {
        return $this->hasMany(Exam::class, 'period_id');
    }

    /** The term period this unit period feeds (unit kind only). */
    public function feedsPeriod()
    {
        return $this->belongsTo(AssessmentPeriod::class, 'feeds_period_id');
    }

    /** The unit periods that feed this period. */
    public function fedByPeriods()
    {
        return $this->hasMany(AssessmentPeriod::class, 'feeds_period_id');
    }

    public function scopeForYear($query, $year)
    {
        return $query->where('year', $year)->orderBy('sort_order')->orderBy('id');
    }

    public function displayName()
    {
        return $this->name;
    }
}
