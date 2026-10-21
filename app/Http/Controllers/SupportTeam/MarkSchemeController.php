<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mark\MarkSchemeRequest;
use App\Models\ExamSubjectComponent;
use App\Repositories\ExamRepo;
use App\Repositories\MyClassRepo;
use App\Support\Audit;
use App\Support\MarkScheme;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Batch M1 — marking-scheme configuration (teamSA only).
 *
 * Exams → Marking Scheme → pick a class → per subject, define the
 * components (name, short code, max marks). The precision contract
 * (maxes sum to exactly 100) is validated in MarkSchemeRequest; the
 * structural guards live here because they need the stored scheme:
 *
 *  - a component that already carries scores cannot be removed, and
 *    its max cannot change (a printed card must never silently
 *    change meaning); names, short codes and order stay editable;
 *  - a scheme cannot be configured for a subject that already has
 *    legacy-column marks in this exam, nor cleared back to legacy
 *    while component scores exist — schemes are chosen before marks
 *    are entered, never swapped underneath them.
 */
class MarkSchemeController extends Controller
{
    protected $exam, $my_class;

    public function __construct(ExamRepo $exam, MyClassRepo $my_class)
    {
        $this->middleware('teamSA');
        $this->exam = $exam;
        $this->my_class = $my_class;
    }

    /** Class picker: every class with its subjects + scheme status. */
    public function index($exam_id)
    {
        $d['ex'] = $exam = $this->exam->find($exam_id);
        if (!$exam) {
            return Qs::goWithDanger('exams.index');
        }

        $d['rows'] = $this->my_class->all()->map(function ($mc) use ($exam) {
            $subjects = $this->my_class->findSubjectByClass($mc->id);
            $configured = $subjects->filter(function ($s) use ($exam) {
                return MarkScheme::isConfigured($exam->id, $s->id);
            })->count();

            return [
                'my_class' => $mc,
                'subjects' => $subjects->count(),
                'configured' => $configured,
            ];
        });

        return view('pages.support_team.exams.scheme.index', $d);
    }

    /** Per-subject component editors for one class in one exam. */
    public function edit($exam_id, $class_id)
    {
        $d['ex'] = $exam = $this->exam->find($exam_id);
        $d['my_class'] = $mc = $this->my_class->find($class_id);
        if (!$exam || !$mc) {
            return Qs::goWithDanger('exams.index');
        }

        $d['subjects'] = $this->my_class->findSubjectByClass($class_id)->map(function ($s) use ($exam) {
            $s->scheme_components = MarkScheme::configuredComponents($exam->id, $s->id);
            $s->scheme_configured = $s->scheme_components->isNotEmpty();
            $s->scheme_scored = MarkScheme::scoredComponentIds($exam->id, $s->id);
            $s->scheme_legacy_scored = !$s->scheme_configured && MarkScheme::hasLegacyScores($exam->id, $s->id);
            return $s;
        });

        $d['other_exams'] = $this->exam->all()->where('id', '!=', $exam->id);
        $d['presets'] = MarkScheme::presets();
        $d['preset_names'] = MarkScheme::presetNames();

        return view('pages.support_team.exams.scheme.edit', $d);
    }

    /** Save one subject's scheme (or clear it back to legacy). */
    public function update(MarkSchemeRequest $req, $exam_id, $class_id, $subject_id)
    {
        $exam = $this->exam->find($exam_id);
        $subject = $this->my_class->findSubject($subject_id);
        if (!$exam || !$subject || (int) $subject->my_class_id !== (int) $class_id) {
            return back()->with('flash_danger', __('msg.rnf'));
        }

        $submitted = collect($req->input('components', []))
            ->filter(function ($c) { return is_array($c) && trim($c['name'] ?? '') !== ''; })
            ->values();

        $clearing = $req->boolean('clear') || $submitted->isEmpty();

        if ($clearing) {
            if (MarkScheme::scoredComponentIds($exam_id, $subject_id)) {
                return back()->with('flash_danger', 'Marks have already been entered for ' . $subject->name . ' under its custom scheme, so it cannot be switched back to the legacy scheme.');
            }
            ExamSubjectComponent::where(['exam_id' => $exam_id, 'subject_id' => $subject_id])->delete();
            Audit::log('marks.scheme_cleared', null, 'Marking scheme cleared to legacy: exam ' . $exam_id . ', subject ' . $subject_id);
            return back()->with('flash_success', $subject->name . ' now uses the legacy scheme (CA 20 + CA 20 + Exam 60).');
        }

        $blocked = $this->guardStructure($exam_id, $subject, $submitted);
        if ($blocked) {
            return back()->with('flash_danger', $blocked);
        }

        DB::transaction(function () use ($exam_id, $subject_id, $submitted) {
            $this->writeScheme($exam_id, $subject_id, $submitted);
        });

        Audit::log('marks.scheme_updated', null, 'Marking scheme updated: exam ' . $exam_id . ', subject ' . $subject_id);

        $msg = 'Marking scheme saved for ' . $subject->name . '.';

        if ($req->boolean('apply_all')) {
            list($copied, $skipped) = $this->replicateToClass($exam_id, $class_id, $subject_id, $submitted);
            $msg .= ' Applied to ' . $copied . ' other subject(s) in this class.';
            if ($skipped) {
                $msg .= ' Skipped (marks already entered): ' . implode(', ', $skipped) . '.';
            }
        }

        return back()->with('flash_success', $msg);
    }

    /** Copy every subject scheme of this class from another exam. */
    public function copy(Request $req, $exam_id, $class_id)
    {
        $exam = $this->exam->find($exam_id);
        $source = $this->exam->find($req->input('source_exam_id'));
        if (!$exam || !$source || (int) $source->id === (int) $exam->id) {
            return back()->with('flash_danger', __('msg.rnf'));
        }

        $copied = 0;
        $skipped = [];

        foreach ($this->my_class->findSubjectByClass($class_id) as $subject) {
            $sourceComps = MarkScheme::configuredComponents($source->id, $subject->id);

            if ($sourceComps->isEmpty()) {
                continue; // Source uses legacy for this subject — leave target untouched.
            }

            $submitted = $sourceComps->map(function ($c) {
                return ['id' => null, 'name' => $c->name, 'short_code' => $c->short_code, 'max_marks' => (int) $c->max_marks];
            });

            // Copying onto a subject that already carries scores of its
            // own (component or legacy) would change the meaning of
            // entered marks — skip it, never force it.
            if (MarkScheme::scoredComponentIds($exam_id, $subject->id)
                || MarkScheme::hasLegacyScores($exam_id, $subject->id)
                || MarkScheme::isConfigured($exam_id, $subject->id)) {
                $skipped[] = $subject->name;
                continue;
            }

            DB::transaction(function () use ($exam_id, $subject, $submitted) {
                $this->writeScheme($exam_id, $subject->id, $submitted);
            });
            $copied++;
        }

        Audit::log('marks.scheme_copied', null, 'Marking schemes copied from exam ' . $source->id . ' to exam ' . $exam_id . ', class ' . $class_id . ' (' . $copied . ' subjects)');

        $msg = 'Copied marking schemes from "' . $source->name . '" to ' . $copied . ' subject(s).';
        if ($skipped) {
            $msg .= ' Skipped (already configured or marks entered): ' . implode(', ', $skipped) . '.';
        }

        return back()->with($copied ? 'flash_success' : 'flash_danger', $msg);
    }

    /**
     * Structural guards for a scheme save. Returns a blocker message
     * or NULL when the save is safe.
     */
    protected function guardStructure($examId, $subject, $submitted)
    {
        $existing = MarkScheme::configuredComponents($examId, $subject->id);
        $scored = MarkScheme::scoredComponentIds($examId, $subject->id);

        if ($existing->isEmpty()) {
            // First configuration for this subject in this exam.
            if (MarkScheme::hasLegacyScores($examId, $subject->id)) {
                return 'Marks have already been entered for ' . $subject->name . ' under the legacy scheme in this exam. A custom scheme must be set before marks are entered — it cannot be swapped in underneath existing marks.';
            }
            return null;
        }

        $existingIds = $existing->pluck('id')->all();
        foreach ($submitted as $row) {
            if (!empty($row['id']) && !in_array((int) $row['id'], $existingIds)) {
                return __('msg.rnf');
            }
        }

        foreach ($existing as $comp) {
            $row = $submitted->firstWhere('id', $comp->id);
            $hasScores = in_array($comp->id, $scored);

            if (!$row && $hasScores) {
                return 'The component "' . $comp->name . '" already has marks entered for ' . $subject->name . ' and cannot be removed.';
            }
            if ($row && $hasScores && (int) $row['max_marks'] !== (int) $comp->max_marks) {
                return 'The max marks for "' . $comp->name . '" cannot be changed — marks have already been entered for it in ' . $subject->name . '.';
            }
        }

        return null;
    }

    /**
     * Replace a subject's scheme with the submitted component set.
     * Submitted rows carrying an existing component id update it in
     * place (scores stay attached); rows without an id are created;
     * existing components absent from the submission are deleted
     * (the caller has already proven they carry no scores).
     */
    protected function writeScheme($examId, $subjectId, $submitted)
    {
        $existing = MarkScheme::configuredComponents($examId, $subjectId);
        $submittedIds = $submitted
            ->filter(function ($row) { return !empty($row['id']); })
            ->map(function ($row) { return (int) $row['id']; })
            ->all();

        // Delete stale components FIRST (the caller has already proven
        // they carry no scores): freeing their names before any create
        // or rename keeps the (exam, subject, name) unique index from
        // rejecting a legitimate remove-and-re-add in one save.
        foreach ($existing as $comp) {
            if (!in_array($comp->id, $submittedIds)) {
                $comp->delete();
            }
        }

        foreach ($submitted as $i => $row) {
            $data = [
                'name' => trim($row['name']),
                'short_code' => strtoupper(trim($row['short_code'])),
                'max_marks' => (int) $row['max_marks'],
                'sort' => $i,
            ];

            if (!empty($row['id'])) {
                ExamSubjectComponent::where('id', $row['id'])
                    ->where(['exam_id' => $examId, 'subject_id' => $subjectId])
                    ->update($data);
            } else {
                ExamSubjectComponent::create($data + ['exam_id' => $examId, 'subject_id' => $subjectId]);
            }
        }
    }

    /** Apply one subject's just-saved scheme to the rest of the class. */
    protected function replicateToClass($examId, $classId, $fromSubjectId, $submitted)
    {
        $copied = 0;
        $skipped = [];

        $plain = $submitted->map(function ($row) {
            return ['id' => null, 'name' => $row['name'], 'short_code' => $row['short_code'], 'max_marks' => (int) $row['max_marks']];
        });

        foreach ($this->my_class->findSubjectByClass($classId) as $subject) {
            if ((int) $subject->id === (int) $fromSubjectId) {
                continue;
            }
            if (MarkScheme::scoredComponentIds($examId, $subject->id) || MarkScheme::hasLegacyScores($examId, $subject->id)) {
                $skipped[] = $subject->name;
                continue;
            }
            DB::transaction(function () use ($examId, $subject, $plain) {
                // Replace any unscored existing scheme wholesale.
                ExamSubjectComponent::where(['exam_id' => $examId, 'subject_id' => $subject->id])->delete();
                $this->writeScheme($examId, $subject->id, $plain);
            });
            $copied++;
        }

        return [$copied, $skipped];
    }
}
