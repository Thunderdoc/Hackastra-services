<?php

namespace App\Http\Requests\TimeTable;

use App\Models\Subject;
use App\Models\TimeTableRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class TTRequest extends FormRequest
{

    public function authorize()
    {
        return true;
    }

    /**
     * Pair guard (cascade audit 2026-10-30): a timetable cell's
     * subject must belong to the class its record serves. The
     * plain `exists` rules let a crafted post drop a Class 12
     * subject into a Class 6 record and it saved silently — the
     * one unguarded pair in the audit (records, assignments,
     * materials, question bank, PTM and lesson plans all check
     * theirs). Covers both the cell store and the cell update,
     * which share this request.
     */
    public function withValidator(Validator $validator)
    {
        $validator->after(function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            $subject = Subject::find($this->input('subject_id'));
            $record = TimeTableRecord::find($this->input('ttr_id'));
            if ($subject && $record && (int) $subject->my_class_id !== (int) $record->my_class_id) {
                $v->errors()->add('subject_id', 'That subject does not belong to the class this timetable serves.');
            }
        });
    }

    /**
     * Get the validation rules that apply to the request.
     * @return array
     */
    public function rules()
    {
        return [
            'exam_date' => 'sometimes|required|string|min:8',
            /* Agent D round 2: the old min:6 let a typo'd weekday
               ('Mondyy') through and create a cell no grid, day
               number or validator could place. */
            'day' => 'sometimes|required|in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday',
            'subject_id' => 'required|exists:subjects,id',
            'ttr_id' => 'required|exists:time_table_records,id',
            'ts_id' => 'required|exists:time_slots,id',
        ];
    }

    public function attributes()
    {
        return  [
            'subject_id' => 'Subject',
            'ttr_id' => 'TimeTable Record',
            'ts_id' => 'Time Slot',
        ];
    }

}
