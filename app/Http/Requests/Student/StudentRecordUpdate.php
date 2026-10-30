<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use App\Helpers\Qs;
use App\Models\Section;

class StudentRecordUpdate extends FormRequest
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
            'name' => 'required|string|min:6|max:150',
            'gender' => 'required|string',
            'phone' => 'sometimes|nullable|string|min:6|max:20',
            'email' => 'sometimes|nullable|email|max:100|unique:users,id',
            'photo' => 'sometimes|nullable|image|mimes:jpeg,gif,png,jpg,webp|max:2048',
            'address' => 'required|string|min:6|max:120',
            'bg_id' => 'sometimes|nullable|exists:blood_groups,id',
            'my_class_id' => 'required|exists:my_classes,id',
            'section_id' => 'required|exists:sections,id',
            'state_id' => 'required|exists:states,id',
            'lga_id' => 'nullable|exists:lgas,id',
            // Category (owner order 2026-10-29): the Indian admission
            // categories, asked instead of LGA.
            'category' => 'nullable|in:General,OBC,SC,ST,EWS',
            'nal_id' => 'required|exists:nationalities,id',
            'my_parent_id' => 'sometimes|nullable|exists:users,id',
            'dorm_id' => 'sometimes|nullable|exists:dorms,id',
        ];
    }

    public function attributes()
    {
        return  [
            'nal_id' => 'Nationality',
            'dorm_id' => 'Dormitory',
            'state_id' => 'State',
            'lga_id' => 'LGA',
            'bg_id' => 'Blood Group',
            'my_parent_id' => 'Parent',
            'my_class_id' => 'Class',
            'section_id' => 'Section',
        ];
    }

    /**
     * Pair guard (cascade audit 2026-10-30): the same belongs
     * check as StudentRecordCreate — an edit must not be able to
     * move a student into a section of another class either.
     */
    public function withValidator(Validator $validator)
    {
        $validator->after(function (Validator $validator) {
            $sectionId = $this->input('section_id');
            $classId = $this->input('my_class_id');
            if ($sectionId && $classId) {
                $section = Section::find($sectionId);
                if ($section && (int) $section->my_class_id !== (int) $classId) {
                    $validator->errors()->add('section_id', 'The selected section does not belong to the selected class.');
                }
            }
        });
    }

    protected function getValidatorInstance()
    {
        $input = $this->all();

        $input['my_parent_id'] = ($input['my_parent_id'] ?? null) ? Qs::decodeHash($input['my_parent_id']) : NULL;

        $this->getInputSource()->replace($input);

        return parent::getValidatorInstance();
    }
}
