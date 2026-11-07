<?php

namespace App\Http\Requests\Exam;

use App\Models\AssessmentPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Update twin of AssessmentPeriodCreate. kind / feeds_period_id /
 * legacy_term may be ABSENT (the edit form disables them once a
 * period has exams, and disabled inputs do not post) — the §1.2
 * invariants then run against the period's stored values merged
 * with what was posted, and the controller separately refuses
 * any change to those fields once exams exist (§2.3 locks).
 */
class AssessmentPeriodUpdate extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'name' => 'required|string|max:80',
            'kind' => 'nullable|in:term,unit,standalone',
            'feeds_period_id' => 'nullable|integer|exists:assessment_periods,id',
            'legacy_term' => 'nullable|integer|in:1,2,3',
            'counts_toward_annual' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:65535',
            'is_active' => 'nullable|boolean',
        ];
    }

    public function withValidator(Validator $validator)
    {
        $validator->after(function (Validator $v) {
            $period = AssessmentPeriod::find($this->route('period'));
            if (!$period) {
                return;
            }
            // Merge stored values for fields the locked form did
            // not post at all (disabled inputs are ABSENT; a posted
            // empty value is a deliberate clear and stays), then
            // run the shared invariants.
            $merged = clone $this;
            $data = $this->all();
            foreach (['kind', 'feeds_period_id', 'legacy_term'] as $field) {
                if (!array_key_exists($field, $data)) {
                    $merged->merge([$field => $period->$field]);
                }
            }
            AssessmentPeriodRules::check($v, $merged, $period->id, $period->year);
        });
    }
}
