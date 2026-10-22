<?php

namespace App\Http\Requests;

use App\Models\FeedbackItem;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Feedback / issue report submission (Workstream CW). Any signed-in
 * user may report; file rules mirror the app's upload validation
 * style (SettingUpdate / UserRequest): images or PDF, max 2 MB each,
 * up to 3 attachments (screenshots first-class).
 */
class FeedbackSave extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'type' => 'required|in:' . implode(',', array_keys(FeedbackItem::types())),
            'category' => 'required|in:' . implode(',', array_keys(FeedbackItem::categories())),
            'priority' => 'required|in:' . implode(',', array_keys(FeedbackItem::priorities())),
            'title' => 'required|string|min:3|max:150',
            'description' => 'required|string|min:5',
            'context' => 'nullable|string|max:255',
            'page_url' => 'nullable|string|max:1024',
            'attachments' => 'nullable|array|max:3',
            'attachments.*' => 'file|mimes:jpeg,gif,png,jpg,webp,pdf|max:2048',
        ];
    }

    public function messages()
    {
        return [
            'attachments.max' => 'You can attach at most 3 files.',
            'attachments.*.mimes' => 'Each attachment must be an image (jpg, png, gif, webp) or a PDF.',
            'attachments.*.max' => 'Each attachment must be at most 2 MB.',
        ];
    }
}
