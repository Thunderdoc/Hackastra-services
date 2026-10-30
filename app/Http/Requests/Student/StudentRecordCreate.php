<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use App\Helpers\Qs;
use App\Models\Section;
use App\Models\StudentRecord;
use App\Repositories\MyClassRepo;

class StudentRecordCreate extends FormRequest
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
            'adm_no' => 'sometimes|nullable|alpha_num|min:3|max:150',
            'gender' => 'required|string',
            'year_admitted' => 'required|string',
            'phone' => 'sometimes|nullable|string|min:6|max:20',
            'email' => 'sometimes|nullable|email|max:100|unique:users',
            'photo' => 'sometimes|nullable|image|mimes:jpeg,gif,png,jpg,webp|max:2048',
            'address' => 'required|string|min:6|max:120',
            'bg_id' => 'sometimes|nullable|exists:blood_groups,id',
            'state_id' => 'required|exists:states,id',
            'lga_id' => 'nullable|exists:lgas,id',
            // Category (owner order 2026-10-29): the Indian admission
            // categories, asked instead of LGA.
            'category' => 'nullable|in:General,OBC,SC,ST,EWS',
            'nal_id' => 'required|exists:nationalities,id',
            'my_class_id' => 'required|exists:my_classes,id',
            'section_id' => 'required|exists:sections,id',
            'my_parent_id' => 'sometimes|nullable|exists:users,id',
            'dorm_id' => 'sometimes|nullable|exists:dorms,id',
        ];
    }

    public function attributes()
    {
        return  [
            'section_id' => 'Section',
            'nal_id' => 'Nationality',
            'my_class_id' => 'Class',
            'dorm_id' => 'Dormitory',
            'state_id' => 'State',
            'lga_id' => 'LGA',
            'bg_id' => 'Blood Group',
            'my_parent_id' => 'Parent',
        ];
    }

    /**
     * ADM-01 companion (2026-10-23): a typed admission number is only
     * the SUFFIX of the stored composite (Qs::admNoPrefix + suffix),
     * so the old unique:student_records rule compared unlike values
     * and never fired — a duplicate typed number reached the DB
     * unique index and 500'd. Build the composite exactly as the
     * controller does and refuse it here with a form error, the same
     * check QuickAdmitRequest runs.
     */
    public function withValidator(Validator $validator)
    {
        $validator->after(function (Validator $validator) {
            // Pair guard FIRST (cascade audit 2026-10-30): it must
            // run even when no admission number was typed — the
            // adm_no block below returns early in exactly that
            // case, which is the common auto-numbered admit.
            $sectionId = $this->input('section_id');
            $pairClassId = $this->input('my_class_id');
            if ($sectionId && $pairClassId) {
                $section = Section::find($sectionId);
                if ($section && (int) $section->my_class_id !== (int) $pairClassId) {
                    $validator->errors()->add('section_id', 'The selected section does not belong to the selected class.');
                }
            }
            $admNo = trim((string) $this->input('adm_no'));
            $classId = $this->input('my_class_id');
            $year = $this->input('year_admitted');
            if ($admNo === '' || !$classId || !$year) {
                return;
            }
            $type = app(MyClassRepo::class)->findTypeByClass($classId);
            if (!$type) {
                return;
            }
            $composite = Qs::admNoPrefix($type->code, $year) . strtoupper($admNo);
            if (StudentRecord::where('adm_no', $composite)->exists()) {
                $validator->errors()->add('adm_no', "Admission number {$composite} already exists.");
            }
            // Numbers-only format (owner order 2026-10-30): the
            // school chose plain numerical admission numbers, so
            // a typed number with letters would break the style.
            if ((string) Qs::getSetting('adm_no_format') === 'serial_only' && !ctype_digit($admNo)) {
                $validator->errors()->add('adm_no', 'This school uses numbers-only admission numbers — digits only, please.');
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
