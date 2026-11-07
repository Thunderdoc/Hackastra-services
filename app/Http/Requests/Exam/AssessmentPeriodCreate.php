<?php

namespace App\Http\Requests\Exam;

use App\Helpers\Qs;
use App\Models\AssessmentPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Exam Pattern invariants (R1 design §1.2): a unit period must
 * feed a term period of the SAME session; term/standalone never
 * feed; at most one ACTIVE period per (session, legacy_term).
 */
class AssessmentPeriodCreate extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'name' => 'required|string|max:80',
            'kind' => 'required|in:term,unit,standalone',
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
            AssessmentPeriodRules::check($v, $this, null, Qs::getSetting('current_session'));
        });
    }
}
