<?php

namespace App\Http\Requests\TimeTable;

use App\Models\Subject;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\Support\SectionTeacher;
use App\Support\Staffing;
use App\User;
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
        // Cap guard first (audit gap G4, 2026-11-06): it must run
        // even when the pair guard below would bail on errors, and
        // it never bails on existing errors itself — each refusal
        // is independent and the write is refused either way.
        $validator->after(function (Validator $v) {
            $this->checkTeacherCaps($v);
        });
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
     * A hand-placed cell obeys the same caps the generator
     * applies (audit gap G4): the cell's EFFECTIVE teacher
     * (SectionTeacher::teacherIdFor — section override first) may
     * not be taken over their day cap or their week cap
     * (Staffing::capsFor — their personal cap when set, else the
     * school defaults) by a manual create or edit. Loads are
     * counted exactly the way the generator preloads them: placed
     * cells across the non-exam records of the record's year.
     * Exam grids carry no weekly teaching load and are skipped,
     * as are teacher-less subjects (nothing to cap). On update,
     * the cell being edited is excluded from its own count, so
     * re-saving a cell at the cap is not refused for its own
     * presence.
     */
    protected function checkTeacherCaps(Validator $v): void
    {
        $subject = Subject::find($this->input('subject_id'));
        $record = TimeTableRecord::find($this->input('ttr_id'));
        $day = $this->input('day');
        if (!$subject || !$record || !$day || $record->exam_id) {
            return;
        }
        $teacherId = SectionTeacher::teacherIdFor($record->section_id, $subject);
        if (!$teacherId) {
            return;
        }
        $teacher = User::find($teacherId);
        if (!$teacher) {
            return;
        }
        $caps = Staffing::capsFor($teacher);

        $recordIds = TimeTableRecord::whereNull('exam_id')
            ->where('year', $record->year)->pluck('id')->all();
        $dayLoads = SectionTeacher::effectiveDayLoads($recordIds);
        $dayLoad = (int) ($dayLoads[$teacherId][$day] ?? 0);
        $weekLoad = (int) array_sum($dayLoads[$teacherId] ?? []);

        // Exclude the cell being updated from its own count.
        $ttParam = $this->route('tt');
        if ($ttParam) {
            $selfCell = TimeTable::find(is_object($ttParam) ? $ttParam->getKey() : $ttParam);
            if ($selfCell) {
                $selfRecord = TimeTableRecord::find($selfCell->ttr_id);
                $selfSubject = Subject::find($selfCell->subject_id);
                if ($selfRecord && $selfSubject && !$selfRecord->exam_id
                    && (string) $selfRecord->year === (string) $record->year
                    && SectionTeacher::teacherIdFor($selfRecord->section_id, $selfSubject) === $teacherId) {
                    $weekLoad--;
                    if ($selfCell->day === $day) {
                        $dayLoad--;
                    }
                }
            }
        }

        if ($dayLoad + 1 > $caps['day']) {
            $v->errors()->add('day', $teacher->name . ' already carries ' . $dayLoad . ' periods on ' . $day
                . ' — their daily cap is ' . $caps['day'] . '. Move this period to another day, or raise the cap on their account.');
        }
        if ($weekLoad + 1 > $caps['week']) {
            $v->errors()->add('subject_id', $teacher->name . ' already carries ' . $weekLoad . ' periods in the week'
                . ' — their weekly cap is ' . $caps['week'] . '. Remove another of their periods first, or raise the cap on their account.');
        }
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
