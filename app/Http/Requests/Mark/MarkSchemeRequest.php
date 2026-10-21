<?php

namespace App\Http\Requests\Mark;

use App\Support\MarkScheme;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Batch M1 — validation for the marking-scheme editor.
 *
 * The precision contract lives here: a subject's component maxes
 * must sum to EXACTLY 100 (so the subject total stays a true
 * percentage and the existing 0–100 grade bands stay honest), at
 * most MAX_COMPONENTS components, and names / short codes must be
 * unique within the subject (they become the report card's columns).
 * Structural guards against scores that already exist are enforced
 * in MarkSchemeController, which can see the stored scheme.
 */
class MarkSchemeRequest extends FormRequest
{
    public function authorize()
    {
        return true; // teamSA middleware guards the route group.
    }

    public function rules()
    {
        return [
            'components' => 'nullable|array|max:' . MarkScheme::MAX_COMPONENTS,
            'components.*.id' => 'nullable|integer',
            'components.*.name' => 'required|string|max:60',
            'components.*.short_code' => 'required|string|max:10',
            'components.*.max_marks' => 'required|integer|min:1|max:100',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($v) {
            $comps = collect($this->input('components', []))
                ->filter(function ($c) { return is_array($c) && trim($c['name'] ?? '') !== ''; });

            if ($comps->isEmpty()) {
                return; // An empty scheme = "use legacy" (handled as clear).
            }

            $sum = $comps->sum(function ($c) { return (int) ($c['max_marks'] ?? 0); });
            if ($sum !== 100) {
                $v->errors()->add('components', 'Component max marks must add up to exactly 100 — currently ' . $sum . '. Adjust the max marks so the subject total stays a true percentage.');
            }

            $names = $comps->map(function ($c) { return strtolower(trim($c['name'])); });
            if ($names->duplicates()->isNotEmpty()) {
                $v->errors()->add('components', 'Component names must be unique within a subject.');
            }

            $codes = $comps->map(function ($c) { return strtolower(trim($c['short_code'] ?? '')); });
            if ($codes->duplicates()->isNotEmpty()) {
                $v->errors()->add('components', 'Component short codes must be unique within a subject — they become the report card columns.');
            }
        });
    }
}
