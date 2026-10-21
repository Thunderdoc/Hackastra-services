<?php

namespace App\Support;

use App\Models\ExamSubjectComponent;
use App\Models\Mark;
use App\Models\MarkComponent;

/**
 * Batch M1/M2 — the marking-scheme resolver.
 *
 * One place answers every scheme question for an (exam, subject):
 * which components exist, what each is worth, what a student scored,
 * and what the subject total is. Components are DATA (rows in
 * exam_subject_components), never columns.
 *
 * BACK-COMPAT BY CONSTRUCTION: an exam-subject with no configured
 * components resolves to the legacy scheme — virtual components that
 * read the marks row's t1 / t2 / exm columns (CA 20 + CA 20 + Exam 60),
 * exactly the behaviour the app has always had. Existing and seeded
 * marks therefore stay valid with zero migration.
 *
 * NULL DOCTRINE (end to end): NULL = not entered, 0 = a real zero.
 * A subject total is NULL only when every component is unentered.
 */
class MarkScheme
{
    /** Soft cap from the design: keeps printed cards readable. */
    const MAX_COMPONENTS = 6;

    /** Component names offered in the editor (owner decision D2:
     *  preset list + free-text Custom). */
    public static function presetNames()
    {
        return [
            'Theory', 'Internal Assessment', 'Practical / Lab', 'Viva',
            'Project', 'Assignment', 'Examination',
            'Continuous Assessment 1', 'Continuous Assessment 2',
            'Class Test', 'Class Work', 'Homework',
        ];
    }

    /**
     * One-tap schemes for the config editor. 'legacy' is special:
     * it stores nothing — selecting it clears the configuration and
     * the subject falls back to the legacy columns.
     */
    public static function presets()
    {
        return [
            'legacy' => [
                'label' => 'Legacy — CA 20 + CA 20 + Exam 60 (no custom components)',
                'components' => [],
            ],
            'theory_internal' => [
                'label' => 'Theory 60 + Internal 40',
                'components' => [
                    ['name' => 'Theory', 'short_code' => 'TH', 'max_marks' => 60],
                    ['name' => 'Internal Assessment', 'short_code' => 'INT', 'max_marks' => 40],
                ],
            ],
            'theory_practical' => [
                'label' => 'Theory 70 + Practical / Lab 30',
                'components' => [
                    ['name' => 'Theory', 'short_code' => 'TH', 'max_marks' => 70],
                    ['name' => 'Practical / Lab', 'short_code' => 'PRAC', 'max_marks' => 30],
                ],
            ],
            'theory_internal_viva' => [
                'label' => 'Theory 60 + Internal 30 + Viva 10',
                'components' => [
                    ['name' => 'Theory', 'short_code' => 'TH', 'max_marks' => 60],
                    ['name' => 'Internal Assessment', 'short_code' => 'INT', 'max_marks' => 30],
                    ['name' => 'Viva', 'short_code' => 'VIVA', 'max_marks' => 10],
                ],
            ],
            'theory_internal_practical' => [
                'label' => 'Theory 50 + Internal 30 + Practical 20',
                'components' => [
                    ['name' => 'Theory', 'short_code' => 'TH', 'max_marks' => 50],
                    ['name' => 'Internal Assessment', 'short_code' => 'INT', 'max_marks' => 30],
                    ['name' => 'Practical / Lab', 'short_code' => 'PRAC', 'max_marks' => 20],
                ],
            ],
        ];
    }

    /** The legacy scheme as virtual components over t1 / t2 / exm. */
    public static function legacyComponents()
    {
        return [
            ['id' => null, 'name' => '1st CA', 'short_code' => 'CA1', 'max_marks' => 20, 'field' => 't1'],
            ['id' => null, 'name' => '2nd CA', 'short_code' => 'CA2', 'max_marks' => 20, 'field' => 't2'],
            ['id' => null, 'name' => 'Exam', 'short_code' => 'EXM', 'max_marks' => 60, 'field' => 'exm'],
        ];
    }

    /** Configured components for an exam-subject, in entry/print order. */
    public static function configuredComponents($examId, $subjectId)
    {
        return ExamSubjectComponent::where(['exam_id' => $examId, 'subject_id' => $subjectId])
            ->orderBy('sort')->orderBy('id')->get();
    }

    public static function isConfigured($examId, $subjectId)
    {
        return ExamSubjectComponent::where(['exam_id' => $examId, 'subject_id' => $subjectId])->exists();
    }

    /**
     * Uniform component list for either scheme. Each entry:
     * [id, name, short_code, max_marks, field] — 'field' names the
     * legacy marks column for legacy components and is NULL for
     * configured ones (those read/write mark_components by id).
     */
    public static function components($examId, $subjectId)
    {
        $configured = self::configuredComponents($examId, $subjectId);
        if ($configured->isEmpty()) {
            return self::legacyComponents();
        }

        return $configured->map(function ($c) {
            return [
                'id' => $c->id,
                'name' => $c->name,
                'short_code' => $c->short_code,
                'max_marks' => (int) $c->max_marks,
                'field' => null,
            ];
        })->all();
    }

    /** Full scheme descriptor used by the entry grid / config UI. */
    public static function forExamSubject($examId, $subjectId)
    {
        $configured = self::isConfigured($examId, $subjectId);

        return [
            'configured' => $configured,
            'components' => self::components($examId, $subjectId),
            'max_total' => self::maxTotal($examId, $subjectId),
        ];
    }

    public static function maxTotal($examId, $subjectId)
    {
        return collect(self::components($examId, $subjectId))->sum('max_marks');
    }

    /**
     * The score a student has in one component of a mark row.
     * $component is an entry from components(). Returns int or NULL
     * (NULL = not entered; 0 is returned as a real 0).
     */
    public static function scoreFor(Mark $mark, array $component)
    {
        if ($component['field']) {
            $v = $mark->{$component['field']};
            return $v === null ? null : (int) $v;
        }

        // Prefer an eager-loaded relation; fall back to a query.
        if ($mark->relationLoaded('markComponents')) {
            $row = $mark->markComponents->firstWhere('component_id', $component['id']);
            return $row ? ($row->score === null ? null : (int) $row->score) : null;
        }

        $row = $mark->markComponents()->where('component_id', $component['id'])->first();
        return $row ? ($row->score === null ? null : (int) $row->score) : null;
    }

    /**
     * Subject total for a mark row under its scheme: the sum of the
     * non-null component scores, or NULL when nothing was entered.
     */
    public static function subjectTotal(Mark $mark)
    {
        if (!self::isConfigured($mark->exam_id, $mark->subject_id)) {
            // Legacy path: tca + exm, the formula the app has always
            // used — but NULL when all three inputs are unentered.
            if ($mark->t1 === null && $mark->t2 === null && $mark->exm === null) {
                return null;
            }
            $tca = $mark->tca !== null ? (int) $mark->tca : (int) $mark->t1 + (int) $mark->t2;
            return $tca + (int) $mark->exm;
        }

        $scores = $mark->markComponents()->pluck('score');
        $entered = $scores->filter(function ($s) { return $s !== null; });
        if ($entered->isEmpty()) {
            return null;
        }
        return (int) $entered->sum();
    }

    /**
     * Persist one student's component scores for a configured scheme.
     * Every configured component gets a row (updateOrCreate); a NULL
     * score is stored as NULL (not entered), never coerced to 0.
     */
    public static function saveComponentScores(Mark $mark, array $scoresByComponentId)
    {
        foreach ($scoresByComponentId as $componentId => $score) {
            MarkComponent::updateOrCreate(
                ['mark_id' => $mark->id, 'component_id' => $componentId],
                ['score' => $score]
            );
        }
        $mark->unsetRelation('markComponents');
    }

    /**
     * Component ids (of this exam-subject) that already carry at
     * least one real (non-null) score. Structural edits to these are
     * blocked by the config UI: a printed card must never silently
     * change meaning after marks exist.
     */
    public static function scoredComponentIds($examId, $subjectId)
    {
        return MarkComponent::whereNotNull('score')
            ->whereHas('component', function ($q) use ($examId, $subjectId) {
                $q->where(['exam_id' => $examId, 'subject_id' => $subjectId]);
            })
            ->pluck('component_id')->unique()->all();
    }

    /**
     * True when the exam-subject still carries legacy-column scores
     * (any of t1/t2/exm or the term total filled). First-time
     * configuration is blocked in that state — the scheme must be
     * chosen before marks are entered, not swapped underneath them.
     */
    public static function hasLegacyScores($examId, $subjectId)
    {
        return Mark::where(['exam_id' => $examId, 'subject_id' => $subjectId])
            ->where(function ($q) {
                $q->whereNotNull('t1')->orWhereNotNull('t2')->orWhereNotNull('exm')
                  ->orWhereNotNull('tex1')->orWhereNotNull('tex2')->orWhereNotNull('tex3');
            })
            ->exists();
    }
}
