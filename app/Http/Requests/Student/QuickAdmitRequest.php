<?php

namespace App\Http\Requests\Student;

use App\Helpers\Qs;
use App\Models\Section;
use App\Models\StudentRecord;
use App\Repositories\MyClassRepo;
use App\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Quick Admit validation (owner-ordered 2026-10-22): admit a student
 * with the essentials only — name, gender, class, section, year
 * admitted, an optional admission number and an optional parent link.
 *
 * This is a DEDICATED path: the full form's StudentRecordCreate rules
 * are untouched and are NOT loosened by anything here. The checks that
 * do apply mirror the CSV importer (StudentBulkController), which is
 * the established essentials-only admission precedent: the section
 * must belong to the class, a supplied admission number must not
 * collide with the composite admission number store() will generate,
 * and a parent is LINKED ONLY — the email/phone must match an
 * existing parent account, exactly as the importer's parent_email
 * column behaves. No parent account is ever created here.
 */
class QuickAdmitRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'q_name' => 'required|string|min:6|max:150',
            'q_gender' => 'required|string|in:Male,Female',
            'q_my_class_id' => 'required|exists:my_classes,id',
            'q_section_id' => 'required|exists:sections,id',
            'q_year_admitted' => 'required|string|regex:/^\d{4}$/',
            'q_adm_no' => 'sometimes|nullable|alpha_num|min:3|max:150',
            'q_parent_email' => 'sometimes|nullable|email|max:100',
            'q_parent_phone' => 'sometimes|nullable|string|min:6|max:20',
        ];
    }

    public function attributes()
    {
        return [
            'q_name' => 'Full Name',
            'q_gender' => 'Gender',
            'q_my_class_id' => 'Class',
            'q_section_id' => 'Section',
            'q_year_admitted' => 'Year Admitted',
            'q_adm_no' => 'Admission Number',
            'q_parent_email' => 'Parent Email',
            'q_parent_phone' => 'Parent Phone',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $classId = $this->input('q_my_class_id');
            $sectionId = $this->input('q_section_id');

            // Section must belong to the chosen class (importer parity —
            // the cascade select normally guarantees this; never trust it).
            if ($classId && $sectionId) {
                $section = Section::find($sectionId);
                if ($section && (int) $section->my_class_id !== (int) $classId) {
                    $validator->errors()->add('q_section_id', 'The selected section does not belong to the chosen class.');
                }
            }

            // A supplied admission number becomes the suffix of the
            // composite admission number store() generates
            // (Qs::admNoPrefix + suffix, stored in student_records.adm_no).
            // Refuse when that composite already exists — the same check
            // the importer runs per row.
            $admNo = trim((string) $this->input('q_adm_no'));
            if ($admNo !== '' && $classId && $this->input('q_year_admitted')) {
                $type = app(MyClassRepo::class)->findTypeByClass($classId);
                if ($type) {
                    $composite = Qs::admNoPrefix($type->code, $this->input('q_year_admitted')) . strtoupper($admNo);
                    if (StudentRecord::where('adm_no', $composite)->exists()) {
                        $validator->errors()->add('q_adm_no', "Admission number {$composite} already exists.");
                    }
                    // Numbers-only format (owner order 2026-10-30).
                    if ((string) Qs::getSetting('adm_no_format') === 'serial_only' && !ctype_digit($admNo)) {
                        $validator->errors()->add('q_adm_no', 'This school uses numbers-only admission numbers — digits only, please.');
                    }
                }
            }

            // Parent link (link-only, importer parity): a supplied email
            // or phone must match an EXISTING parent account; when both
            // are supplied they must resolve to the same parent.
            $byEmail = $this->parentBy('email', trim((string) $this->input('q_parent_email')));
            $byPhone = $this->parentBy('phone', trim((string) $this->input('q_parent_phone')));

            if ($this->filled('q_parent_email') && !$byEmail) {
                $validator->errors()->add('q_parent_email', "Parent email '{$this->input('q_parent_email')}' does not match an existing parent account.");
            }
            if ($this->filled('q_parent_phone') && !$byPhone) {
                $validator->errors()->add('q_parent_phone', "Parent phone '{$this->input('q_parent_phone')}' does not match an existing parent account.");
            }
            if ($byEmail && $byPhone && $byEmail->id !== $byPhone->id) {
                $validator->errors()->add('q_parent_phone', 'Parent phone belongs to a different parent account than the parent email.');
            }
        });
    }

    /** The existing parent account this request links to, if any. */
    public function resolvedParent()
    {
        return $this->parentBy('email', trim((string) $this->input('q_parent_email')))
            ?: $this->parentBy('phone', trim((string) $this->input('q_parent_phone')));
    }

    protected function parentBy($column, $value)
    {
        if ($value === '') {
            return null;
        }

        return User::where($column, $value)->where('user_type', 'parent')->first();
    }
}
