<?php

namespace App\Http\Requests\Exam;

use App\Models\AssessmentPeriod;
use Illuminate\Validation\Validator;

/**
 * Shared invariant checks for period create/update (R1 design
 * §1.2), so the two form requests can never drift apart.
 */
class AssessmentPeriodRules
{
    /**
     * @param  mixed $ignoreId period id to exclude (update), or null
     * @param  mixed $year     the session the period belongs to
     */
    public static function check(Validator $v, $request, $ignoreId, $year): void
    {
        if ($v->errors()->isNotEmpty()) {
            return;
        }
        $kind = $request->input('kind');
        $feeds = $request->input('feeds_period_id');
        $legacy = $request->input('legacy_term');
        $legacy = ($legacy === '' || $legacy === null) ? null : (int) $legacy;

        if ($kind === 'unit') {
            if (!$feeds) {
                $v->errors()->add('feeds_period_id', 'A unit period must feed a term period.');
            } else {
                $target = AssessmentPeriod::find((int) $feeds);
                if (!$target || $target->kind !== 'term') {
                    $v->errors()->add('feeds_period_id', 'A unit period can only feed a term period.');
                } elseif ($target->year !== $year) {
                    $v->errors()->add('feeds_period_id', 'A unit period can only feed a term period of the same session.');
                } elseif ($ignoreId && (int) $target->id === (int) $ignoreId) {
                    $v->errors()->add('feeds_period_id', 'A period cannot feed itself.');
                }
            }
            if ($legacy !== null) {
                $v->errors()->add('legacy_term', 'A unit period has no legacy term number.');
            }
        } else {
            if ($feeds) {
                $v->errors()->add('feeds_period_id', 'Only unit periods feed another period.');
            }
            if ($kind === 'standalone' && $legacy !== null) {
                $v->errors()->add('legacy_term', 'Only term periods carry a legacy term number.');
            }
        }

        // At most one ACTIVE period per (session, legacy_term).
        if ($legacy !== null && $request->input('is_active', true)) {
            $clash = AssessmentPeriod::where('year', $year)
                ->where('legacy_term', $legacy)
                ->where('is_active', true)
                ->when($ignoreId, function ($q) use ($ignoreId) { $q->where('id', '!=', $ignoreId); })
                ->exists();
            if ($clash) {
                $v->errors()->add('legacy_term', 'Another active period in this session already uses legacy term ' . $legacy . '.');
            }
        }
    }
}
