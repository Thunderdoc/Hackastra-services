<?php

namespace App\Http\Requests;

use App\Models\Circular;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Circular create + update share one rule set (no unique fields),
 * so a single request class serves both, mirroring SchoolEventSave.
 *
 * Attachments (Loop 105) mirror the feedback system's contract
 * (FeedbackSave, Workstream CW) exactly: up to 3 files, images or
 * PDF, at most 2 MB each. On update the cap applies to the
 * circular's TOTAL attachments (existing − removed + newly
 * uploaded), enforced in withValidator below.
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
            'attachments' => 'nullable|array|max:3',
            'attachments.*' => 'file|mimes:jpeg,gif,png,jpg,webp,pdf|max:2048',
            'remove_attachments' => 'nullable|array',
            'remove_attachments.*' => 'integer',
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

    /**
     * The per-request max:3 alone would let an edit push a circular
     * past 3 files in total (2 existing + 2 new). Count what the
     * circular will hold after this save and refuse the overflow.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $circular = $this->route('circular');
            if (!$circular instanceof Circular) {
                return;
            }

            $remove = collect($this->input('remove_attachments', []))
                ->map(function ($id) { return (int) $id; })
                ->all();
            $kept = $circular->attachments()
                ->whereNotIn('id', $remove ?: [0])
                ->count();
            $added = collect($this->file('attachments', []))
                ->filter()
                ->count();

            if ($kept + $added > 3) {
                $validator->errors()->add('attachments', 'A circular can hold at most 3 attachments in total. Remove one before adding another.');
            }
        });
    }
}
