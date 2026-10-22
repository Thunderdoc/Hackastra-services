<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SettingUpdate extends FormRequest
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
        /* Workstream DG: a Super Admin save that carries ONLY platform
           keys (SettingController::PLATFORM_FIELDS) is validated against
           those keys alone — the school form's required fields are not
           part of that save and must not fail it. Any other save keeps
           the full school rule set below. */
        $posted = array_keys($this->except('_token', '_method'));
        if ($posted && !array_diff($posted, \App\Http\Controllers\SuperAdmin\SettingController::PLATFORM_FIELDS)) {
            return [
                // Google issues an alphanumeric token (letters, digits,
                // dashes, underscores), shown in Search Console.
                'google_site_verification' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9_-]+$/'],
            ];
        }

        return [
            'system_name' => 'required|string|min:3',
            'current_session' => 'required|string',
            'address' => 'required|string|min:8',
            'system_email' => 'sometimes|nullable|email',
            'lock_exam' => 'required',
            'logo' => 'sometimes|nullable|image|mimes:jpeg,gif,png,jpg,webp|max:2048',
            'principal_name' => 'sometimes|nullable|string|max:120',
            /* Online fee payments (Workstream CU) — secrets are
               sometimes|nullable so leaving the field blank keeps the
               stored value (SettingController drops blanks). */
            'gateway_enabled' => 'sometimes|nullable|in:0,1',
            'razorpay_key_id' => 'sometimes|nullable|string|max:100',
            'razorpay_key_secret' => 'sometimes|nullable|string|max:200',
            'razorpay_webhook_secret' => 'sometimes|nullable|string|max:200',
            'school_stamp' => 'sometimes|nullable|image|mimes:jpeg,gif,png,jpg,webp|max:2048',
            'ai_enabled' => 'sometimes|nullable|in:0,1',
            'ai_provider' => 'sometimes|nullable|string|in:gemini',
            'ai_model' => 'sometimes|nullable|string|max:100',
            'ai_api_key' => 'sometimes|nullable|string|max:255',

        ];
    }

    public function attributes()
    {
        return  [
            'system_name' => 'School Name',
            'system_email' => 'School Email',
            'current_session' => 'Current Session',
        ];
    }

}
