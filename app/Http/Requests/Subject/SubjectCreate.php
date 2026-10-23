<?php

namespace App\Http\Requests\Subject;

use App\Helpers\Qs;
use Illuminate\Foundation\Http\FormRequest;

class SubjectCreate extends FormRequest
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
            'name' => 'required|string|min:3',
            'my_class_id' => 'required',
            'teacher_id' => 'nullable|exists:users,id',
            'slug' => 'nullable|string|min:3',
            'periods_per_week' => 'sometimes|nullable|integer|min:1|max:40',
            'subject_type' => 'sometimes|in:theory,practical',
            'needs_double' => 'sometimes|boolean',
        ];
    }

    public function attributes()
    {
        return  [
            'my_class_id' => 'Class',
            'teacher_id' => 'Teacher',
            'slug' => 'Short Name',
        ];
    }

    protected function getValidatorInstance()
    {
        $input = $this->all();

        $decoded = ($input['teacher_id'] ?? null) ? Qs::decodeHash($input['teacher_id']) : NULL;
        // A tampered value that fails to decode becomes '' — normalize
        // it to NULL so a subject can never sit in a ''-teacher limbo
        // (Allocation and the Vacant badge key off a real null).
        $input['teacher_id'] = ($decoded === '' || $decoded === null) ? NULL : $decoded;

        $this->getInputSource()->replace($input);

        return parent::getValidatorInstance();
    }
}
