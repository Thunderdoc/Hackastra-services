<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Models\TeacherEligibility;
use App\Support\Allocation;
use App\Support\Audit;
use App\Support\Readiness;
use App\Support\Staffing;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Scheduling Engine Slice A (2026-10-23): teacher eligibility —
 * which subjects each teacher is QUALIFIED to teach. Distinct from
 * the current assignment (subjects.teacher_id): eligibility is the
 * pool the allocation engine may draw from. Managed by the school
 * Admin (teamSAWrite); Super Admin reads platform-only as usual.
 */
class PlanningController extends Controller
{
    public function __construct()
    {
        $this->middleware('teamSA');
        $this->middleware('teamSAWrite', ['only' => ['saveEligibility', 'applyAllocation']]);
    }

    public function eligibility(Request $request)
    {
        $teachers = User::where('user_type', 'teacher')->orderBy('name')->get();
        $d['teachers'] = $teachers;
        $d['subject_names'] = Staffing::subjectNames();
        $d['eligibility'] = TeacherEligibility::orderBy('subject_name')
            ->get()->groupBy('teacher_id')
            ->map(function ($rows) { return $rows->pluck('subject_name')->all(); });
        $d['assigned'] = \App\Models\Subject::whereNotNull('teacher_id')->get()
            ->groupBy('teacher_id')
            ->map(function ($rows) { return $rows->pluck('name')->unique()->values()->all(); });

        $selected = $request->query('teacher');
        $d['selected_teacher'] = $selected
            ? $teachers->firstWhere('id', Qs::resolveId($selected))
            : null;

        return view('pages.support_team.planning.eligibility', $d);
    }

    /**
     * Staffing Dashboard (Slice B): demand vs capacity per subject
     * and committed load per teacher, from Staffing::report(). Read
     * surface — every number traces to a configured requirement,
     * an eligibility row or an assignment; nothing is estimated.
     */
    public function staffing()
    {
        return view('pages.support_team.planning.staffing', Staffing::report());
    }

    /**
     * Allocation workspace (Slice C): the class-subjects that need
     * attention with the engine's recommendation for each. Applying
     * revalidates server-side — eligibility is a hard rule; the
     * weekly cap can be overridden only explicitly, with the
     * override and its reason written to the audit log.
     */
    public function allocation()
    {
        $d['rows'] = Allocation::recommendations();
        $d['cap'] = Staffing::maxPeriodsPerWeek();
        $d['teachers'] = User::where('user_type', 'teacher')->orderBy('name')
            ->get()->keyBy('id');

        return view('pages.support_team.planning.allocation', $d);
    }

    public function applyAllocation(Request $request)
    {
        $data = $request->validate([
            'subject_id' => 'required',
            'teacher_id' => 'required',
            'override_cap' => 'nullable|boolean',
            'override_reason' => 'nullable|string|max:255',
        ]);

        $subject = \App\Models\Subject::findOrFail(Qs::resolveId($data['subject_id']));
        $teacher = User::where('user_type', 'teacher')
            ->findOrFail(Qs::resolveId($data['teacher_id']));

        // Hard rule: eligibility. An ineligible teacher can never be
        // applied — not by this form, not by any override.
        if (!in_array($teacher->id, Staffing::eligibleTeacherIds($subject->name), true)) {
            return back()->with('flash_danger', $teacher->name . ' is not eligible to teach ' . $subject->name . ' — record their eligibility first.');
        }

        // Soft rule: the weekly cap. Crossing it needs an explicit
        // override + reason, and both are audit-logged.
        $loads = Allocation::teacherLoads();
        $projected = ($loads[$teacher->id] ?? 0) + (int) ($subject->periods_per_week ?? 0);
        $cap = Staffing::maxPeriodsPerWeek();
        if ($subject->periods_per_week && $projected > $cap && !$request->boolean('override_cap')) {
            return back()->with('flash_danger', 'This would put ' . $teacher->name . ' at ' . $projected . ' periods, over the weekly cap of ' . $cap . '. Tick the override and give a reason if you mean to do it anyway.');
        }

        $previous = $subject->teacher_id;
        $subject->update(['teacher_id' => $teacher->id]);

        // Allotment audit 2026-10-28 (pass 2): moving the class
        // teacher does NOT move section overrides — those sections
        // deliberately keep their own teacher. The old flash hid
        // that, so an admin could believe the whole class moved.
        // Say it plainly in the confirmation and the audit note.
        $overrideSections = DB::table('section_subject_teachers')
            ->where('subject_id', $subject->id)->count();
        $overrideNote = $overrideSections > 0
            ? ' Note: ' . $overrideSections . ' section(s) keep their override teacher(s).'
            : '';

        $note = 'Allocated ' . $subject->name . ' (' . $subject->my_class->name . ') to ' . $teacher->name
            . ($previous ? ' (was teacher ' . $previous . ')' : ' (was unassigned)');
        if ($subject->periods_per_week && $projected > $cap) {
            $note .= ' — OVER weekly cap (' . $projected . '/' . $cap . '), override reason: ' . ($data['override_reason'] ?? '');
        }
        $note .= $overrideNote;
        Audit::log('planning.allocation_applied', $subject, $note);

        return back()->with('flash_success', $subject->name . ' (' . $subject->my_class->name . ') is now taught by ' . $teacher->name . '.' . $overrideNote);
    }

    /** School Readiness (Phase 8): live setup checks with fixes. */
    public function readiness()
    {
        return view('pages.support_team.planning.readiness', Readiness::checks());
    }

    public function saveEligibility(Request $request)
    {
        $data = $request->validate([
            'teacher_id' => 'required',
            'subjects' => 'nullable|array',
            'subjects.*' => 'string|max:150',
        ]);

        $teacher = User::where('user_type', 'teacher')
            ->findOrFail(Qs::resolveId($data['teacher_id']));

        // Only names that exist as subjects in this school are
        // accepted — the eligibility vocabulary is the school's own
        // subject list, never free text that can drift by a typo.
        $valid = collect($data['subjects'] ?? [])
            ->intersect(Staffing::subjectNames())->unique()->values();

        \Illuminate\Support\Facades\DB::transaction(function () use ($teacher, $valid) {
            TeacherEligibility::where('teacher_id', $teacher->id)->delete();
            foreach ($valid as $name) {
                TeacherEligibility::create([
                    'teacher_id' => $teacher->id,
                    'subject_name' => $name,
                ]);
            }
        });

        Audit::log('planning.eligibility_updated', $teacher,
            'Eligibility for ' . $teacher->name . ' set to: ' . ($valid->isEmpty() ? '(none)' : $valid->implode(', ')));

        return redirect()->route('planning.eligibility', ['teacher' => Qs::hash($teacher->id)])
            ->with('flash_success', 'Eligibility saved for ' . $teacher->name . '.');
    }
}
