<?php

namespace App\Http\Requests\Exam;

use App\Helpers\Qs;
use App\Models\AssessmentPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ExamCreate extends FormRequest
{

    public function authorize()
    {
        return true;
    }


    public function rules()
    {
        return [
            'name' => 'required|string',
            // Exam Patterns Phase 1 (R1 §2.4): exams are created
            // against a period; the legacy term number is derived
            // server-side. 'term' stays accepted WITHOUT period_id
            // as a compat shim for older posts — the controller
            // maps it to the session's term period (§3.1 mapping).
            'period_id' => 'required_without:term|nullable|integer|exists:assessment_periods,id',
            'term' => 'nullable|numeric|in:1,2,3',
            'my_class_id' => 'nullable|integer',
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
                return; // the exists rule already reported it
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
