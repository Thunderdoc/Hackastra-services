<?php

namespace App\Http\Requests\Exam;

use App\Helpers\Qs;
use App\Models\AssessmentPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ExamUpdate extends FormRequest
{

    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'name' => 'required|string',
            // Phase 1: period_id replaces the term select (the
            // edit blade disables it once marks exist, so it may
            // be absent). 'term' remains as the compat shim.
            'period_id' => 'nullable|integer|exists:assessment_periods,id',
            'term' => 'nullable|numeric|in:1,2,3',
        ];
    }

    public function withValidator(Validator $validator)
    {
        $validator->after(function (Validator $v) {
            if (!$this->filled('period_id')) {
                return;
            }
            $period = AssessmentPeriod::find((int) $this->input('period_id'));
            if (!$period) {
                return;
            }
            if ($period->year !== Qs::getSetting('current_session')) {
                $v->errors()->add('period_id', 'The selected period belongs to a different session.');
            }
            if (!$period->is_active) {
                $v->errors()->add('period_id', 'The selected period is not active.');
            }
        });
    }

}
