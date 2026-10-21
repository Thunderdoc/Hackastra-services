<?php

namespace App\Http\Requests;

use App\Models\Circular;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Circular create + update share one rule set (no unique fields),
 * so a single request class serves both, mirroring SchoolEventSave.
 */
class CircularSave extends FormRequest
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
            'title' => 'required|string|min:3|max:150',
            'body' => 'required|string',
            'audience' => 'required|in:' . implode(',', array_keys(Circular::audiences())),
            'priority' => 'required|in:' . implode(',', array_keys(Circular::priorities())),
            'starts_at' => 'nullable|date',
            'expires_at' => 'nullable|date|after_or_equal:starts_at',
            'is_published' => 'nullable|boolean',
        ];
    }
}
