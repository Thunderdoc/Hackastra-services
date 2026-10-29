<?php

namespace App\Http\Requests\Section;

use App\Helpers\Qs;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SectionCreate extends FormRequest
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
            'my_class_id' => 'required',
            // Allotment audit 2026-10-28: exists:users,id alone let a crafted
            // POST name a student/parent/admin as a teacher. The teacher_id
            // here is already hash-decoded above, so this checks the row.
            'teacher_id' => ['sometimes', 'nullable', Rule::exists('users', 'id')->where(function ($q) { $q->where('user_type', 'teacher'); })],
            'stream' => 'nullable|in:pcm,pcb,commerce,arts',
            'capacity' => 'nullable|integer|min:0|max:1000',
        ];
    }

    public function attributes()
    {
        return  [
            'my_class_id' => 'Class',
            'teacher_id' => 'Teacher',
        ];
    }

    protected function getValidatorInstance()
    {
        $input = $this->all();

        $input['teacher_id'] = ($input['teacher_id'] ?? null) ? Qs::decodeHash($input['teacher_id']) : NULL;

        $this->getInputSource()->replace($input);

        return parent::getValidatorInstance();
    }

}
