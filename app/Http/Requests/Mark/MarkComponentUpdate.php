<?php

namespace App\Http\Requests\Mark;

use App\Helpers\Qs;
use App\Models\Mark;
use App\Models\Subject;
use App\Support\MarkScheme;
use App\Support\SectionTeacher;
use App\Support\TeacherScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * Batch M2 — the marks save path gets real validation for the first
 * time. Rules are built per cell from the scheme itself: every score
 * must be a whole number within 0..component max; a blank cell means
 * "not entered" and is always allowed. A single bad cell rejects the
 * whole save with the student and component named — the legacy
 * behaviour of silently wiping a student's scores is gone.
 */
class MarkComponentUpdate extends FormRequest
{
    public function authorize()
    {
        // Same rule the controller enforces (Batch S + agent C
        // round 24): a teacher may only save marks for their
        // assigned classes AND, within them, only for subjects
        // whose effective teacher for the section is herself.
        // It runs here so an out-of-scope teacher gets the 403
        // before any validation output, exactly as the pre-M2
        // plain-Request flow did.
        if (Qs::userIsTeacher()) {
            if (!TeacherScope::canTeachClass(Auth::user(), $this->route('class'))) {
                return false;
            }
            $subject = Subject::find($this->route('subject'));
            if (!$subject || (int) $subject->my_class_id !== (int) $this->route('class')) {
                return false;
            }

            return (int) SectionTeacher::teacherIdFor($this->route('section'), $subject) === (int) Auth::user()->id;
        }

        return true;
    }

    public function rules()
    {
        $examId = $this->route('exam');
        $classId = $this->route('class');
        $sectionId = $this->route('section');
        $subjectId = $this->route('subject');
        $year = Qs::getSetting('current_session');

        $marks = Mark::where([
            'exam_id' => $examId, 'my_class_id' => $classId,
            'section_id' => $sectionId, 'subject_id' => $subjectId, 'year' => $year,
        ])->with('user')->get();

        $rules = [];

        if (MarkScheme::isConfigured($examId, $subjectId)) {
            $components = MarkScheme::configuredComponents($examId, $subjectId);
            foreach ($marks as $mk) {
                foreach ($components as $comp) {
                    $key = 'comp_' . $mk->id . '_' . $comp->id;
                    // Wave 32: a mark whose student user is gone (orphan
                    // row) must not 500 the whole save — name it
                    // generically and let the save proceed.
                    $rules[$key] = [$this->cellRule(optional($mk->user)->name ?? 'Student', $comp->name, (int) $comp->max_marks)];
                }
            }
        } else {
            foreach (MarkScheme::legacyComponents() as $comp) {
                foreach ($marks as $mk) {
                    $key = $comp['field'] . '_' . $mk->id;
                    $rules[$key] = [$this->cellRule(optional($mk->user)->name ?? 'Student', $comp['name'], $comp['max_marks'])];
                }
            }
        }

        return $rules;
    }

    /**
     * One closure rule per cell so the error can name the exact cell:
     * "Ada Lovelace — Theory must be a whole number between 0 and 60."
     * Blank (null / empty string) passes: it means "not entered".
     */
    protected function cellRule($studentName, $componentName, $max)
    {
        return function ($attribute, $value, $fail) use ($studentName, $componentName, $max) {
            if ($value === null || $value === '') {
                return;
            }
            if (!is_numeric($value) || (int) $value != $value || $value < 0 || $value > $max) {
                $fail($studentName . ' — ' . $componentName . ' must be a whole number between 0 and ' . $max . '.');
            }
        };
    }
}
