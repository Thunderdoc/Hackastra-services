<?php

namespace App\Http\Requests\SchoolEvent;

use App\Models\SchoolEvent;
use Illuminate\Foundation\Http\FormRequest;

/**
 * School event create + update share one rule set (no unique fields),
 * so a single request class serves both, mirroring how
 * SchoolEventController previously shared one validateEvent() helper.
 */
class SchoolEventSave extends FormRequest
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
            'title' => 'required|string|max:255',
            'type' => 'required|in:' . implode(',', array_keys(SchoolEvent::types())),
            'description' => 'nullable|string|max:2000',
            'starts_at' => 'required|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'all_day' => 'nullable|boolean',
        ];
    }

}
