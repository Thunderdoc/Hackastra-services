<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Models\AiQuestionSuggestion;
use App\Models\Exam;
use App\Models\MyClass;
use App\Models\QuestionBankQuestion;
use App\Models\QuestionPaper;
use App\Models\QuestionPaperQuestion;
use App\Models\Section;
use App\Models\Subject;
use App\Services\AiAssist;
use App\Services\AiAssistException;
use App\Support\Audit;
use App\Support\ExamDateSheet;
use App\Support\PaperSelection;
use App\Support\PdfFooter;
use App\Support\SectionTeacher;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * F4 — Question-Paper Generator (design: hk-tmp/round4).
 *
 * Controller-guarded like QuizController / QuestionBankController
 * (NO team middleware; route params are {paper}/{suggestion},
 * never {id}). Role settlement (§6.1, mirroring the bank first
 * and the quiz second):
 *  - Teacher: creates and owns papers for subjects/classes they
 *    teach (the bank's teacherSubjectIds/teacherClassIds union +
 *    SectionTeacher::teacherIdFor); owns the AI review queue.
 *  - Admin: index/show/PDF read-only over ALL papers; 403 on
 *    create/store/AI/delete.
 *  - Principal: 403 in v1. Super Admin: no route. Students and
 *    parents: 403 — there is no student-facing surface in F4.
 *
 * Papers are SNAPSHOTS: store/regenerate copy bank rows into
 * question_paper_questions; the print paths read only those rows.
 * AI suggestions live in their own table and can reach a paper
 * only through the reviewed approve action.
 */
class QuestionPaperController extends Controller
{
    /* ---------------------------------------------------------
     | Guards + teacher scoping (bank union, reused exactly)
     | ------------------------------------------------------- */

    protected function requireTeacher(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->user_type === 'teacher', 403);

        return $user;
    }

    /** Read gate: owning teacher, or admin (read-only). */
    protected function requireReader(Request $request, QuestionPaper $paper)
    {
        $user = $request->user();
        abort_unless($user, 403);
        if ($user->user_type === 'admin') {
            return $user;
        }
        abort_unless($user->user_type === 'teacher' && (int) $paper->teacher_id === (int) $user->id, 403);

        return $user;
    }

    /** Owner gate: the creating teacher only — admin is read-only. */
    protected function requireOwner(Request $request, QuestionPaper $paper)
    {
        $user = $request->user();
        abort_unless($user && $user->user_type === 'teacher' && (int) $paper->teacher_id === (int) $user->id, 403);

        return $user;
    }

    protected function teacherSubjectIds(int $userId)
    {
        $classLevel = Subject::where('teacher_id', $userId)->pluck('id')->all();
        $override = DB::table('section_subject_teachers')
            ->where('teacher_id', $userId)->pluck('subject_id')->all();

        return array_values(array_unique(array_merge($classLevel, $override)));
    }

    protected function teacherClassIds(int $userId)
    {
        $ids = Subject::whereIn('id', $this->teacherSubjectIds($userId))
            ->pluck('my_class_id')->all();
        $overrideSectionClasses = Section::whereIn('id', DB::table('section_subject_teachers')
            ->where('teacher_id', $userId)->pluck('section_id')->all())
            ->pluck('my_class_id')->all();

        return array_values(array_unique(array_merge($ids, $overrideSectionClasses)));
    }

    protected function formData(int $userId)
    {
        $classIds = $this->teacherClassIds($userId);
        $subjectIds = $this->teacherSubjectIds($userId);

        return [
            'my_classes' => MyClass::whereIn('id', $classIds)->orderBy('id')->get(),
            'subjects' => Subject::whereIn('id', $subjectIds)->orderBy('my_class_id')->orderBy('name')->get(),
            'sections' => Section::whereIn('my_class_id', $classIds)->orderBy('my_class_id')->orderBy('name')->get(),
        ];
    }

    /** The AI gate (§4.3) — STRICTER than AiAssist's built-in mock
     *  behaviour: real AI only when enabled AND not on the mock
     *  transport (enabled + no key, or AI_MOCK on, is the honest
     *  no-key state; mock text is never saved or printed). */
    public static function aiState(): string
    {
        if (!AiAssist::enabled()) {
            return 'off';
        }
        if (AiAssist::usingMock()) {
            return 'no_key';
        }

        return 'ready';
    }

    /* ---------------------------------------------------------
     | Spec parsing (shared by preview + store)
     | ------------------------------------------------------- */

    protected function parseSpec(Request $request, $user): array
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'my_class_id' => 'required|integer|exists:my_classes,id',
            'subject_id' => 'required|integer|exists:subjects,id',
            'section_id' => 'nullable|integer|exists:sections,id',
            'exam_id' => 'nullable|integer|exists:exams,id',
            'exam_label' => 'nullable|string|max:255',
            'duration_minutes' => 'nullable|integer|min:1|max:600',
            'seed' => 'nullable|string|max:64',
            'instructions' => 'nullable|string|max:2000',
            'answer_space_lines' => 'nullable|integer|min:0|max:20',
            'exam_date' => 'nullable|string|max:30',
            'sections' => 'required|array|min:1',
            'sections.*.label' => 'nullable|string|max:60',
            'sections.*.type' => 'required|in:mcq,short,long',
            'sections.*.count' => 'required|integer|min:0|max:100',
            'sections.*.marks_each' => 'nullable|integer|min:1|max:100',
            'sections.*.marks_total' => 'nullable|integer|min:1|max:500',
            'sections.*.chapters' => 'nullable|array',
            'sections.*.chapters.*' => 'string|max:120',
            'sections.*.difficulty_mix' => 'nullable|array',
            'sections.*.difficulty_mix.*' => 'nullable|numeric|min:0|max:100',
        ]);

        $classId = (int) $request->input('my_class_id');
        $subjectId = (int) $request->input('subject_id');
        $sectionId = $request->input('section_id') ? (int) $request->input('section_id') : null;

        if (!in_array($classId, $this->teacherClassIds($user->id), true)
            || !in_array($subjectId, $this->teacherSubjectIds($user->id), true)) {
            throw ValidationException::withMessages([
                'subject_id' => 'You can only generate papers for subjects you teach.',
            ]);
        }
        $subject = Subject::find($subjectId);
        if ((int) $subject->my_class_id !== $classId) {
            throw ValidationException::withMessages([
                'subject_id' => 'That subject does not belong to the chosen class.',
            ]);
        }
        if ($sectionId) {
            $section = Section::find($sectionId);
            if ((int) $section->my_class_id !== $classId
                || SectionTeacher::teacherIdFor($sectionId, $subject) !== (int) $user->id) {
                throw ValidationException::withMessages([
                    'section_id' => 'You can only generate papers for sections you teach.',
                ]);
            }
        }
        $exam = null;
        if ($request->input('exam_id')) {
            $exam = Exam::find((int) $request->input('exam_id'));
            if ((int) $exam->my_class_id !== 0 && (int) $exam->my_class_id !== $classId) {
                throw ValidationException::withMessages([
                    'exam_id' => 'That exam is not for the chosen class.',
                ]);
            }
        }

        $sections = [];
        foreach ((array) $request->input('sections') as $key => $row) {
            $sectionKey = isset($row['key']) && $row['key'] !== '' ? (string) $row['key'] : (string) $key;
            $marksEach = isset($row['marks_each']) && $row['marks_each'] !== '' && $row['marks_each'] !== null ? (int) $row['marks_each'] : null;
            $marksTotal = isset($row['marks_total']) && $row['marks_total'] !== '' && $row['marks_total'] !== null ? (int) $row['marks_total'] : null;
            if ($marksEach !== null && $marksTotal !== null) {
                throw ValidationException::withMessages([
                    'sections' => "Section {$sectionKey}: set either marks per question or a marks total, not both.",
                ]);
            }
            if ($marksEach === null && $marksTotal === null) {
                $marksEach = 1;
            }
            $mix = null;
            if (!empty($row['difficulty_mix']) && is_array($row['difficulty_mix'])) {
                $clean = [];
                foreach (['easy', 'medium', 'hard', 'unrated'] as $bucket) {
                    $clean[$bucket] = isset($row['difficulty_mix'][$bucket]) && $row['difficulty_mix'][$bucket] !== ''
                        ? (float) $row['difficulty_mix'][$bucket] : 0;
                }
                if (array_sum($clean) > 0) {
                    $mix = $clean;
                }
            }
            $sections[] = [
                'key' => $sectionKey,
                'label' => trim((string) ($row['label'] ?? '')) !== '' ? trim((string) $row['label']) : $sectionKey,
                'type' => $row['type'],
                'count' => (int) $row['count'],
                'marks_each' => $marksEach,
                'marks_total' => $marksTotal,
                'chapters' => array_values(array_filter(array_map(fn ($c) => trim((string) $c), (array) ($row['chapters'] ?? [])), fn ($c) => $c !== '')),
                'difficulty_mix' => $mix,
            ];
        }

        $spec = [
            'sections' => $sections,
            'instructions' => trim((string) $request->input('instructions', '')) !== ''
                ? trim((string) $request->input('instructions'))
                : 'Answer all questions. Marks are shown against each question.',
            'answer_space_lines' => (int) ($request->input('answer_space_lines') ?: 0),
            'exam_date' => $request->input('exam_date') ?: null,
            'ai_assisted' => false,
            'shortfall' => null,
        ];

        $scope = [
            'my_class_id' => $classId,
            'section_id' => $sectionId,
            'subject_id' => $subjectId,
            'exam_id' => $exam ? (int) $exam->id : null,
            'exam_label' => $request->input('exam_label') ?: null,
            'class_name' => MyClass::find($classId)->name ?? '',
            'subject_name' => $subject->name ?? '',
        ];

        return [$spec, $scope, $exam];
    }

    protected function runSelection(array $spec, string $seed, $user, array $scope, bool $allowRepeats): array
    {
        return PaperSelection::select($spec, $seed, (int) $user->id, $scope, $allowRepeats);
    }

    protected function shortfallMessages(array $selection): array
    {
        $messages = [];
        foreach ($selection['sections'] as $result) {
            if ($result['shortfall']) {
                $messages[] = $result['shortfall']['message'];
            }
        }

        return $messages;
    }

    /** Persist a paper + its snapshot rows from a selection result. */
    protected function persistPaper($user, Request $request, array $spec, array $scope, string $seed, bool $allowRepeats, array $selection, bool $partial): QuestionPaper
    {
        if ($partial) {
            $shortfalls = [];
            foreach ($selection['sections'] as $key => $result) {
                if ($result['shortfall']) {
                    $shortfalls[$key] = $result['shortfall'];
                }
            }
            $spec['shortfall'] = $shortfalls;
        }

        return DB::transaction(function () use ($user, $request, $spec, $scope, $seed, $allowRepeats, $selection) {
            $paper = QuestionPaper::create([
                'teacher_id' => $user->id,
                'created_by' => $user->id,
                'my_class_id' => $scope['my_class_id'],
                'section_id' => $scope['section_id'],
                'subject_id' => $scope['subject_id'],
                'exam_id' => $scope['exam_id'],
                'title' => $request->input('title'),
                'exam_label' => $scope['exam_label'],
                'duration_minutes' => $request->input('duration_minutes') ?: null,
                'total_marks' => 0,
                'seed' => $seed,
                'spec' => $spec,
                'status' => 'draft',
                'allow_repeat_questions' => $allowRepeats,
            ]);

            $total = 0;
            foreach ($spec['sections'] as $sectionSpec) {
                $result = $selection['sections'][$sectionSpec['key']] ?? null;
                if (!$result) {
                    continue;
                }
                $position = 1;
                foreach ($result['picked'] as $q) {
                    QuestionPaperQuestion::create([
                        'question_paper_id' => $paper->id,
                        'bank_question_id' => $q->id,
                        'source' => 'bank',
                        'section_key' => $sectionSpec['key'],
                        'position' => $position++,
                        'question_type' => $q->question_type ?: 'mcq',
                        'question' => $q->question,
                        'options' => $q->options,
                        'correct_index' => $q->correct_index,
                        'model_answer' => $q->model_answer,
                        'marks' => (int) $q->marks,
                        'chapter' => $q->chapter,
                        'difficulty' => $q->difficulty,
                        'text_hash' => PaperSelection::textHash($q->question),
                    ]);
                    $total += (int) $q->marks;
                }
            }
            $paper->update(['total_marks' => $total]);

            return $paper;
        });
    }

    /* ---------------------------------------------------------
     | Mode A pages + actions
     | ------------------------------------------------------- */

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && in_array($user->user_type, ['teacher', 'admin'], true), 403);
        $isAdmin = $user->user_type === 'admin';

        $query = QuestionPaper::with(['my_class', 'section', 'subject', 'exam', 'teacher'])
            ->withCount('questions')
            ->latest();
        if (!$isAdmin) {
            $query->where('teacher_id', $user->id);
        }
        if ($request->filled('class_id')) {
            $query->where('my_class_id', (int) $request->query('class_id'));
        }
        if ($request->filled('subject_id')) {
            $query->where('subject_id', (int) $request->query('subject_id'));
        }
        if ($request->filled('exam_id')) {
            $query->where('exam_id', (int) $request->query('exam_id'));
        }
        if (in_array($request->query('status'), ['draft', 'final'], true)) {
            $query->where('status', $request->query('status'));
        }

        if ($isAdmin) {
            $d = [
                'my_classes' => MyClass::orderBy('id')->get(),
                'subjects' => Subject::orderBy('my_class_id')->orderBy('name')->get(),
                'exams' => Exam::latest()->get(),
            ];
        } else {
            $d = $this->formData($user->id);
            $d['exams'] = Exam::where(function ($q) use ($d) {
                $q->whereIn('my_class_id', $d['my_classes']->pluck('id')->all())->orWhere('my_class_id', 0);
            })->latest()->get();
        }
        $d['papers'] = $query->get();
        $d['is_admin'] = $isAdmin;
        $d['selected_class_id'] = $request->query('class_id');
        $d['selected_subject_id'] = $request->query('subject_id');
        $d['selected_exam_id'] = $request->query('exam_id');
        $d['selected_status'] = $request->query('status');

        return view('pages.support_team.question_papers.index', $d);
    }

    public function create(Request $request)
    {
        $user = $this->requireTeacher($request);
        $d = $this->formData($user->id);
        $d['exams'] = Exam::where(function ($q) use ($d) {
            $q->whereIn('my_class_id', $d['my_classes']->pluck('id')->all())->orWhere('my_class_id', 0);
        })->latest()->get();

        $d['bank_type_counts'] = [
            'mcq' => QuestionBankQuestion::where('teacher_id', $user->id)->where('question_type', 'mcq')->count(),
            'short' => QuestionBankQuestion::where('teacher_id', $user->id)->where('question_type', 'short')->count(),
            'long' => QuestionBankQuestion::where('teacher_id', $user->id)->where('question_type', 'long')->count(),
        ];
        $chaptersBySubject = [];
        foreach ($d['subjects'] as $subject) {
            $chaptersBySubject[$subject->id] = QuestionBankQuestion::where('teacher_id', $user->id)
                ->where('subject_id', $subject->id)
                ->whereNotNull('chapter')->where('chapter', '!=', '')
                ->distinct()->orderBy('chapter')->pluck('chapter')->all();
        }
        $d['chapters_by_subject'] = $chaptersBySubject;
        $d['ai_state'] = static::aiState();
        $d['my_papers'] = QuestionPaper::where('teacher_id', $user->id)->latest()->limit(20)->get();

        return view('pages.support_team.question_papers.create', $d);
    }

    /** Dry-run (§3.1): runs the algorithm, saves NOTHING. */
    public function preview(Request $request)
    {
        $user = $this->requireTeacher($request);
        [$spec, $scope] = $this->parseSpec($request, $user);
        $seed = $request->input('seed') ?: 'preview-seed';
        $allowRepeats = $request->boolean('allow_repeat_questions');
        $selection = $this->runSelection($spec, $seed, $user, $scope, $allowRepeats);

        $sections = [];
        foreach ($selection['sections'] as $key => $result) {
            $sections[$key] = [
                'pool' => $result['pool'],
                'picked' => count($result['picked']),
                'marks' => $result['marks'],
                'excluded_duplicates' => $result['excluded_duplicates'],
                'shortfall' => $result['shortfall'],
                'difficulty_note' => $result['difficulty_note'],
            ];
        }

        return response()->json([
            'sections' => $sections,
            'has_shortfall' => $selection['has_shortfall'],
            'shortfall_messages' => $this->shortfallMessages($selection),
            'excluded_total' => $selection['excluded_total'],
            'excluded_from' => $selection['excluded_from'],
            'duplicate_warning' => $selection['duplicate_warning'],
        ]);
    }

    public function store(Request $request)
    {
        $user = $this->requireTeacher($request);
        [$spec, $scope] = $this->parseSpec($request, $user);
        $seed = $request->input('seed') ?: Str::random(16);
        $allowRepeats = $request->boolean('allow_repeat_questions');
        $selection = $this->runSelection($spec, $seed, $user, $scope, $allowRepeats);

        if ($selection['has_shortfall'] && !$request->boolean('create_partial')) {
            // §3.3: on a shortfall NOTHING is written.
            return back()->withInput()
                ->with('flash_danger', implode(' ', $this->shortfallMessages($selection)))
                ->with('paper_shortfalls', $this->shortfallMessages($selection));
        }

        $paper = $this->persistPaper($user, $request, $spec, $scope, $seed, $allowRepeats, $selection, $selection['has_shortfall']);

        $description = 'Teacher ' . $user->name . ' created question paper ' . $paper->id
            . ' (' . $paper->title . '), class ' . $paper->my_class_id . ', subject ' . $paper->subject_id
            . ', seed ' . $paper->seed . ', total marks ' . $paper->total_marks;
        if ($allowRepeats) {
            $description .= ', repeat questions ALLOWED, repeats used: ' . ($selection['repeat_count'] ?? 0);
        }
        Audit::log('question_paper.created', $paper, $description);

        $message = $selection['has_shortfall']
            ? 'Partial draft created — it records the shortfall below and cannot be finalised until the spec or your bank changes.'
            : 'Question paper draft created from your bank.';

        return redirect()->route('question_papers.show', $paper->id)->with('flash_success', $message);
    }

    public function show(Request $request, QuestionPaper $paper)
    {
        $user = $this->requireReader($request, $paper);
        $paper->load(['my_class', 'section', 'subject', 'exam', 'teacher', 'questions']);

        return view('pages.support_team.question_papers.show', [
            'paper' => $paper,
            'doc' => $this->documentData($paper, false),
            'is_owner' => $user->user_type === 'teacher',
            'filed' => $paper->pdf_path && Storage::disk('local')->exists($paper->pdf_path),
        ]);
    }

    public function regenerate(Request $request, QuestionPaper $paper)
    {
        $user = $this->requireOwner($request, $paper);
        if (!$paper->isDraft()) {
            return back()->with('flash_danger', 'Only a draft paper can be regenerated — a final paper is frozen.');
        }
        $spec = $paper->spec;
        $spec['shortfall'] = null;
        $scope = [
            'my_class_id' => (int) $paper->my_class_id,
            'section_id' => $paper->section_id ? (int) $paper->section_id : null,
            'subject_id' => (int) $paper->subject_id,
            'exam_id' => $paper->exam_id ? (int) $paper->exam_id : null,
            'exam_label' => $paper->exam_label,
            'class_name' => optional($paper->my_class)->name ?? '',
            'subject_name' => optional($paper->subject)->name ?? '',
        ];
        $seed = $request->input('seed') ?: Str::random(16);
        $allowRepeats = (bool) $paper->allow_repeat_questions;
        $selection = $this->runSelection($spec, $seed, $user, $scope, $allowRepeats);

        if ($selection['has_shortfall']) {
            $shortfalls = [];
            foreach ($selection['sections'] as $key => $result) {
                if ($result['shortfall']) {
                    $shortfalls[$key] = $result['shortfall'];
                }
            }
            $spec['shortfall'] = $shortfalls;
        }

        DB::transaction(function () use ($paper, $spec, $seed, $selection) {
            $paper->questions()->where('source', 'bank')->delete();
            $total = (int) $paper->questions()->sum('marks');
            foreach (($spec['sections'] ?? []) as $sectionSpec) {
                $result = $selection['sections'][$sectionSpec['key']] ?? null;
                if (!$result) {
                    continue;
                }
                $position = (int) QuestionPaperQuestion::where('question_paper_id', $paper->id)
                    ->where('section_key', $sectionSpec['key'])->max('position');
                foreach ($result['picked'] as $q) {
                    // Within-paper dedupe also covers AI rows already appended.
                    $hash = PaperSelection::textHash($q->question);
                    $dupe = QuestionPaperQuestion::where('question_paper_id', $paper->id)
                        ->where(function ($w) use ($q, $hash) {
                            $w->where('bank_question_id', $q->id)->orWhere('text_hash', $hash);
                        })->exists();
                    if ($dupe) {
                        continue;
                    }
                    QuestionPaperQuestion::create([
                        'question_paper_id' => $paper->id,
                        'bank_question_id' => $q->id,
                        'source' => 'bank',
                        'section_key' => $sectionSpec['key'],
                        'position' => ++$position,
                        'question_type' => $q->question_type ?: 'mcq',
                        'question' => $q->question,
                        'options' => $q->options,
                        'correct_index' => $q->correct_index,
                        'model_answer' => $q->model_answer,
                        'marks' => (int) $q->marks,
                        'chapter' => $q->chapter,
                        'difficulty' => $q->difficulty,
                        'text_hash' => $hash,
                    ]);
                    $total += (int) $q->marks;
                }
            }
            $paper->update(['spec' => $spec, 'seed' => $seed, 'total_marks' => $total]);
        });

        Audit::log('question_paper.regenerated', $paper, 'Teacher ' . $user->name . ' regenerated draft paper ' . $paper->id . ' with seed ' . $seed);

        return back()->with('flash_success', 'Draft regenerated with a new selection (seed ' . $seed . ').');
    }

    public function finalise(Request $request, QuestionPaper $paper)
    {
        $user = $this->requireOwner($request, $paper);
        if (!$paper->isDraft()) {
            return back()->with('flash_danger', 'This paper is already final.');
        }
        if ($paper->hasShortfall()) {
            return back()->with('flash_danger', 'This draft records a shortfall and cannot be finalised until the spec or your bank changes.');
        }
        $paper->update(['status' => 'final']);
        try {
            $this->filePdfs($paper->fresh());
        } catch (\Throwable $e) {
            Log::warning('Question paper finalise: PDF filing failed for paper ' . $paper->id . ': ' . $e->getMessage());
        }
        Audit::log('question_paper.finalised', $paper, 'Teacher ' . $user->name . ' finalised question paper ' . $paper->id . ' (' . $paper->title . ').');

        return back()->with('flash_success', 'Paper finalised — the snapshot is now frozen.');
    }

    public function destroy(Request $request, QuestionPaper $paper)
    {
        $user = $this->requireOwner($request, $paper);
        $wasFinal = $paper->status === 'final';
        $fileNote = null;

        foreach (array_filter([$paper->pdf_path, $paper->answer_key_pdf_path]) as $path) {
            if (Storage::disk('local')->exists($path) && !Storage::disk('local')->delete($path)) {
                Log::warning('QuestionPaper destroy: file delete failed but paper deleted', [
                    'pdf_path' => $path, 'deleted_by' => $user->id,
                ]);
                $fileNote = 'Paper deleted, but one of its filed PDFs could not be removed from storage — please check storage manually.';
            }
        }

        DB::transaction(function () use ($paper) {
            AiQuestionSuggestion::where('question_paper_id', $paper->id)
                ->update(['question_paper_id' => null]);
            $paper->questions()->delete();
            $paper->delete();
        });

        if ($wasFinal) {
            Audit::log('question_paper.deleted', null, 'Teacher ' . $user->name . ' deleted FINAL question paper ' . $paper->id . ' (' . $paper->title . ').');
        }

        return redirect()->route('question_papers.index')
            ->with('flash_success', $fileNote ?: 'Question paper deleted.');
    }

    /* ---------------------------------------------------------
     | Slice 2 — documents (paper + answer key), PDF, filing
     | ------------------------------------------------------- */

    /**
     * View-model for the document partials. For the QUESTION PAPER
     * the model is STRIPPED: correct_index and model_answer are
     * never passed to that partial, so an answer cannot leak into
     * a printed paper even by a blade mistake (§5.1).
     */
    protected function documentData(QuestionPaper $paper, bool $forPdf, bool $withAnswers = false): array
    {
        $paper->loadMissing(['my_class', 'section', 'subject', 'exam', 'questions']);
        $spec = $paper->spec ?: [];
        $bySection = $paper->questions->groupBy('section_key');
        $sections = [];
        $seen = [];
        foreach (($spec['sections'] ?? []) as $sectionSpec) {
            $key = $sectionSpec['key'];
            $seen[$key] = true;
            $sections[] = $this->documentSection($sectionSpec, $bySection->get($key, collect()), $withAnswers);
        }
        foreach ($bySection as $key => $rows) {
            if (!isset($seen[$key])) {
                $sections[] = $this->documentSection(
                    ['key' => $key, 'label' => 'Additional Questions', 'type' => $rows->first()->question_type ?? 'mcq'],
                    $rows,
                    $withAnswers
                );
            }
        }

        return [
            'paper' => $paper,
            'sections' => $sections,
            'school_name' => Qs::getSystemName(),
            'logo' => $forPdf ? ExamDateSheet::pdfImageDataUri(Qs::getSetting('logo')) : Qs::getSetting('logo'),
            'address' => Qs::getSetting('address'),
            'phone' => Qs::getSchoolPhone(),
            'email' => Qs::getSetting('system_email'),
            'exam_name' => $paper->examName() ?: $paper->title,
            'class_name' => optional($paper->my_class)->name,
            'section_name' => optional($paper->section)->name,
            'subject_name' => optional($paper->subject)->name,
            'total_marks' => (int) $paper->questions->sum('marks'),
            'duration_minutes' => $paper->duration_minutes,
            'exam_date' => $spec['exam_date'] ?? null,
            'instructions' => $spec['instructions'] ?? 'Answer all questions. Marks are shown against each question.',
            'answer_space_lines' => (int) ($spec['answer_space_lines'] ?? 0),
        ];
    }

    protected function documentSection(array $sectionSpec, $rows, bool $withAnswers): array
    {
        $questions = [];
        foreach ($rows->sortBy('position')->values() as $q) {
            $row = [
                'question' => $q->question,
                'options' => $q->options,
                'marks' => (int) $q->marks,
                'question_type' => $q->question_type,
            ];
            if ($withAnswers) {
                $row['correct_index'] = $q->correct_index;
                $row['model_answer'] = $q->model_answer;
            }
            $questions[] = $row;
        }
        $marksEach = $sectionSpec['marks_each'] ?? null;
        $total = (int) collect($questions)->sum('marks');

        return [
            'key' => $sectionSpec['key'],
            'label' => $sectionSpec['label'] ?? $sectionSpec['key'],
            'type' => $sectionSpec['type'] ?? ($questions[0]['question_type'] ?? 'mcq'),
            'questions' => $questions,
            'count' => count($questions),
            'marks_each' => $marksEach,
            'total_marks' => $total,
            'heading_marks' => $marksEach
                ? count($questions) . ' × ' . $marksEach . ' = ' . $total . ' marks'
                : $total . ' marks',
        ];
    }

    protected function buildPdfBytes(QuestionPaper $paper, string $kind): string
    {
        Qs::ensurePdfMemory();
        $view = $kind === 'key'
            ? 'pages.support_team.question_papers.answer_key_pdf'
            : 'pages.support_team.question_papers.pdf';
        $pdf = Pdf::loadView($view, ['doc' => $this->documentData($paper, true, $kind === 'key')])->setPaper('a4');
        PdfFooter::stampPageNumbers($pdf);

        return $pdf->output();
    }

    /** CircularOfficialPdf filing pattern (§5.3): render the bytes,
     *  file them on the local disk at a deterministic path, stamp
     *  the row. Downloads prefer these bytes when present. */
    public function filePdfs(QuestionPaper $paper): void
    {
        $paperBytes = $this->buildPdfBytes($paper, 'paper');
        $keyBytes = $this->buildPdfBytes($paper, 'key');
        $paperPath = 'question-papers/paper-' . $paper->id . '.pdf';
        $keyPath = 'question-papers/paper-' . $paper->id . '-key.pdf';
        Storage::disk('local')->put($paperPath, $paperBytes);
        Storage::disk('local')->put($keyPath, $keyBytes);
        $paper->update([
            'pdf_path' => $paperPath,
            'answer_key_pdf_path' => $keyPath,
            'pdf_generated_at' => now(),
        ]);
    }

    protected function downloadPdf(Request $request, QuestionPaper $paper, string $kind)
    {
        $this->requireReader($request, $paper);
        $filedPath = $kind === 'key' ? $paper->answer_key_pdf_path : $paper->pdf_path;
        $filename = ($kind === 'key' ? 'answer-key-' : 'question-paper-') . $paper->id . '.pdf';
        if ($filedPath && Storage::disk('local')->exists($filedPath)) {
            return response()->download(storage_path('app/' . $filedPath), $filename, ['Content-Type' => 'application/pdf']);
        }
        $bytes = $this->buildPdfBytes($paper, $kind);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    public function pdf(Request $request, QuestionPaper $paper)
    {
        return $this->downloadPdf($request, $paper, 'paper');
    }

    public function answerKey(Request $request, QuestionPaper $paper)
    {
        return $this->downloadPdf($request, $paper, 'key');
    }

    public function printPage(Request $request, QuestionPaper $paper)
    {
        $this->requireReader($request, $paper);

        return view('pages.support_team.question_papers.print', [
            'paper' => $paper,
            'doc' => $this->documentData($paper, false),
        ]);
    }

    public function savePdf(Request $request, QuestionPaper $paper)
    {
        $this->requireOwner($request, $paper);
        $this->filePdfs($paper);

        return back()->with('flash_success', 'PDF filed — downloads now serve the filed copy.');
    }

    /* ---------------------------------------------------------
     | Slice 3 — Mode B: AI generation + review queue
     | ------------------------------------------------------- */

    /** One AI item, validated with the SAME per-type rules as a
     *  bank question (§4.1/§1.1). Returns the cleaned row or null. */
    protected function validateSuggestionItem(array $item, string $type, array $defaults): ?array
    {
        $question = trim((string) ($item['question'] ?? ''));
        if ($question === '') {
            return null;
        }
        $marks = isset($item['marks']) && is_numeric($item['marks']) ? (int) $item['marks'] : (int) ($defaults['marks'] ?? 1);
        if ($marks < 1) {
            return null;
        }
        $difficulty = $item['difficulty'] ?? ($defaults['difficulty'] ?? null);
        if (!in_array($difficulty, ['easy', 'medium', 'hard'], true)) {
            $difficulty = null;
        }
        $chapter = trim((string) ($item['chapter'] ?? ($defaults['chapter'] ?? '')));

        if ($type === 'mcq') {
            $options = [];
            foreach ((array) ($item['options'] ?? []) as $opt) {
                $opt = trim((string) $opt);
                if ($opt !== '') {
                    $options[] = $opt;
                }
            }
            $correct = $item['correct_index'] ?? null;
            if (count($options) < 2 || !is_numeric($correct) || (int) $correct < 0 || (int) $correct >= count($options)) {
                return null;
            }

            return [
                'question' => $question, 'options' => $options, 'correct_index' => (int) $correct,
                'model_answer' => null, 'marks' => $marks, 'difficulty' => $difficulty,
                'chapter' => $chapter !== '' ? Str::limit($chapter, 120, '') : null,
            ];
        }

        $modelAnswer = trim((string) ($item['model_answer'] ?? ''));
        if ($modelAnswer === '') {
            return null;
        }

        return [
            'question' => $question, 'options' => null, 'correct_index' => null,
            'model_answer' => $modelAnswer, 'marks' => $marks, 'difficulty' => $difficulty,
            'chapter' => $chapter !== '' ? Str::limit($chapter, 120, '') : null,
        ];
    }

    public function aiGenerate(Request $request)
    {
        $user = $this->requireTeacher($request);
        $request->validate([
            'my_class_id' => 'required|integer|exists:my_classes,id',
            'subject_id' => 'required|integer|exists:subjects,id',
            'section_id' => 'nullable|integer|exists:sections,id',
            'question_paper_id' => 'nullable|integer|exists:question_papers,id',
            'question_type' => 'required|in:mcq,short,long',
            'count' => 'required|integer|min:1|max:20',
            'marks' => 'required|integer|min:1|max:100',
            'chapter' => 'nullable|string|max:120',
            'difficulty' => 'nullable|in:easy,medium,hard',
        ]);

        $classId = (int) $request->input('my_class_id');
        $subjectId = (int) $request->input('subject_id');
        if (!in_array($classId, $this->teacherClassIds($user->id), true)
            || !in_array($subjectId, $this->teacherSubjectIds($user->id), true)) {
            return back()->withInput()->with('flash_danger', 'You can only generate questions for subjects you teach.');
        }
        $subject = Subject::find($subjectId);
        if ((int) $subject->my_class_id !== $classId) {
            return back()->withInput()->with('flash_danger', 'That subject does not belong to the chosen class.');
        }
        $sectionId = $request->input('section_id') ? (int) $request->input('section_id') : null;
        if ($sectionId && SectionTeacher::teacherIdFor($sectionId, $subject) !== (int) $user->id) {
            return back()->withInput()->with('flash_danger', 'You can only generate questions for sections you teach.');
        }
        $paperId = $request->input('question_paper_id') ? (int) $request->input('question_paper_id') : null;
        if ($paperId) {
            $target = QuestionPaper::find($paperId);
            if (!$target || (int) $target->teacher_id !== (int) $user->id) {
                return back()->withInput()->with('flash_danger', 'You can only generate questions for your own papers.');
            }
        }

        // The strict gate (§4.3): off and no-key states save ZERO
        // suggestions and never touch the mock transport.
        $state = static::aiState();
        if ($state === 'off') {
            return back()->withInput()->with('flash_danger', 'AI question generation is switched off. Bank-only generation works fully without it.');
        }
        if ($state === 'no_key') {
            return back()->withInput()->with('flash_danger', 'AI is switched on but no API key is configured yet, so real AI generation isn\'t available. Bank-only generation works fully without it.');
        }

        $type = $request->input('question_type');
        $exemplars = QuestionBankQuestion::where('teacher_id', $user->id)
            ->where('my_class_id', $classId)->where('subject_id', $subjectId)
            ->where('question_type', $type)
            ->when($request->input('chapter'), fn ($q) => $q->where('chapter', $request->input('chapter')))
            ->limit(3)->pluck('question')->all();

        try {
            $text = AiAssist::generate('question_generate', [
                'school_name' => Qs::getSystemName(),
                'class_name' => MyClass::find($classId)->name ?? '',
                'subject_name' => $subject->name,
                'chapter' => $request->input('chapter'),
                'question_type' => $type,
                'count' => (int) $request->input('count'),
                'marks' => (int) $request->input('marks'),
                'difficulty' => $request->input('difficulty'),
                'exemplars' => $exemplars,
            ], $user);
        } catch (AiAssistException $e) {
            // Transport failure / cap: nothing saved, land back on
            // the form with the input preserved (§4.4).
            return back()->withInput()->with('flash_danger', $e->getMessage() . ' Nothing was generated. Continue with Bank-only.');
        }

        $items = AiAssist::decodeJsonArray($text);
        if ($items === null) {
            return back()->withInput()->with('flash_danger', 'AI returned questions we couldn\'t use — nothing was saved. Try again or use Bank-only.');
        }

        $defaults = [
            'marks' => (int) $request->input('marks'),
            'chapter' => $request->input('chapter'),
            'difficulty' => $request->input('difficulty'),
        ];
        $valid = [];
        $discarded = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                $discarded++;
                continue;
            }
            $clean = $this->validateSuggestionItem($item, $type, $defaults);
            if ($clean === null) {
                $discarded++;
                continue;
            }
            $valid[] = $clean;
        }
        if (!$valid) {
            return back()->withInput()->with('flash_danger', 'AI returned questions we couldn\'t use — nothing was saved. Try again or use Bank-only.');
        }

        DB::transaction(function () use ($valid, $user, $classId, $subjectId, $sectionId, $paperId, $type, $request) {
            foreach ($valid as $clean) {
                AiQuestionSuggestion::create([
                    'teacher_id' => $user->id,
                    'my_class_id' => $classId,
                    'subject_id' => $subjectId,
                    'section_id' => $sectionId,
                    'question_paper_id' => $paperId,
                    'question_type' => $type,
                    'question' => $clean['question'],
                    'options' => $clean['options'],
                    'correct_index' => $clean['correct_index'],
                    'model_answer' => $clean['model_answer'],
                    'marks' => $clean['marks'],
                    'chapter' => $clean['chapter'],
                    'difficulty' => $clean['difficulty'],
                    'status' => 'pending',
                    'ai_model' => AiAssist::model(),
                    'prompt_context' => [
                        'chapter' => $request->input('chapter'),
                        'question_type' => $type,
                        'count' => (int) $request->input('count'),
                        'marks' => (int) $request->input('marks'),
                        'difficulty' => $request->input('difficulty'),
                    ],
                ]);
            }
        });

        $total = count($valid) + $discarded;
        $message = $discarded > 0
            ? count($valid) . " of {$total} returned questions were usable and are waiting in your review queue; {$discarded} were discarded."
            : count($valid) . ' questions are waiting in your review queue.';

        return redirect()->route('question_papers.ai_index')->with('flash_success', $message);
    }

    public function aiIndex(Request $request)
    {
        $user = $this->requireTeacher($request);
        $pending = AiQuestionSuggestion::with(['my_class', 'subject', 'paper'])
            ->where('teacher_id', $user->id)->where('status', 'pending')->latest()->get();
        $history = AiQuestionSuggestion::with(['my_class', 'subject', 'paper'])
            ->where('teacher_id', $user->id)->whereIn('status', ['approved', 'rejected'])->latest()->limit(50)->get();
        $myPapers = QuestionPaper::where('teacher_id', $user->id)->latest()->get();

        return view('pages.support_team.question_papers.ai_index', [
            'pending' => $pending,
            'history' => $history,
            'my_papers' => $myPapers,
            'ai_state' => static::aiState(),
        ]);
    }

    protected function requireOwnSuggestion(Request $request, AiQuestionSuggestion $suggestion)
    {
        $user = $this->requireTeacher($request);
        abort_unless((int) $suggestion->teacher_id === (int) $user->id, 403);

        return $user;
    }

    public function aiUpdate(Request $request, AiQuestionSuggestion $suggestion)
    {
        $this->requireOwnSuggestion($request, $suggestion);
        if ($suggestion->status !== 'pending') {
            return back()->with('flash_danger', 'Only a pending suggestion can be edited.');
        }
        $clean = $this->validateSuggestionItem($request->all(), $suggestion->question_type, [
            'marks' => $suggestion->marks,
            'chapter' => $suggestion->chapter,
            'difficulty' => $suggestion->difficulty,
        ]);
        if ($clean === null) {
            return back()->withInput()->with('flash_danger', 'That edit is not a usable question — check the question text, options/correct option (MCQ) or model answer (short/long), and marks.');
        }
        $suggestion->update($clean);

        return back()->with('flash_success', 'Suggestion updated — it is still pending your approval.');
    }

    public function aiApprove(Request $request, AiQuestionSuggestion $suggestion)
    {
        $user = $this->requireOwnSuggestion($request, $suggestion);
        if ($suggestion->status !== 'pending') {
            return back()->with('flash_danger', 'This suggestion has already been ' . $suggestion->status . '.');
        }
        $target = $request->input('target', 'bank');
        if (!in_array($target, ['paper', 'bank', 'both'], true)) {
            $target = 'bank';
        }
        $toBank = in_array($target, ['bank', 'both'], true);
        $toPaper = in_array($target, ['paper', 'both'], true);

        $bankData = null;
        if ($toBank) {
            // Same validatedData() rules as a hand-banked question;
            // a refusal leaves the suggestion pending (§8 item 14).
            try {
                $bankData = (new QuestionBankController())->validatedArray([
                    'my_class_id' => $suggestion->my_class_id,
                    'subject_id' => $suggestion->subject_id,
                    'section_id' => $suggestion->section_id,
                    'question' => $suggestion->question,
                    'question_type' => $suggestion->question_type,
                    'options' => $suggestion->options,
                    'correct_index' => $suggestion->correct_index,
                    'model_answer' => $suggestion->model_answer,
                    'marks' => $suggestion->marks,
                    'chapter' => $suggestion->chapter,
                    'difficulty' => $suggestion->difficulty,
                ], (int) $user->id);
            } catch (ValidationException $e) {
                $first = collect($e->errors())->flatten()->first();

                return back()->with('flash_danger', 'That suggestion could not be saved to your bank: ' . $first . ' It is still pending — edit it and try again.');
            }
        }

        $paper = null;
        if ($toPaper) {
            $paperId = $request->input('question_paper_id') ?: $suggestion->question_paper_id;
            $paper = $paperId ? QuestionPaper::find((int) $paperId) : null;
            if (!$paper || (int) $paper->teacher_id !== (int) $user->id || !$paper->isDraft()) {
                return back()->with('flash_danger', 'Questions can only be added to your own draft papers. The suggestion is still pending.');
            }
        }

        DB::transaction(function () use ($suggestion, $user, $toBank, $bankData, $toPaper, $paper, $request) {
            if ($toBank) {
                $bankRow = QuestionBankQuestion::create(array_merge($bankData, ['teacher_id' => $user->id]));
                $suggestion->bank_question_id = $bankRow->id;
            }
            if ($toPaper) {
                $sectionKey = $request->input('section_key');
                if (!$sectionKey) {
                    $sectionKey = 'AI';
                    foreach (($paper->spec['sections'] ?? []) as $sectionSpec) {
                        if (($sectionSpec['type'] ?? null) === $suggestion->question_type) {
                            $sectionKey = $sectionSpec['key'];
                            break;
                        }
                    }
                }
                $position = (int) QuestionPaperQuestion::where('question_paper_id', $paper->id)
                    ->where('section_key', $sectionKey)->max('position');
                QuestionPaperQuestion::create([
                    'question_paper_id' => $paper->id,
                    'bank_question_id' => null,
                    'source' => 'ai',
                    'section_key' => $sectionKey,
                    'position' => $position + 1,
                    'question_type' => $suggestion->question_type,
                    'question' => $suggestion->question,
                    'options' => $suggestion->options,
                    'correct_index' => $suggestion->correct_index,
                    'model_answer' => $suggestion->model_answer,
                    'marks' => (int) $suggestion->marks,
                    'chapter' => $suggestion->chapter,
                    'difficulty' => $suggestion->difficulty,
                    'text_hash' => PaperSelection::textHash($suggestion->question),
                ]);
                $spec = $paper->spec;
                $spec['ai_assisted'] = true;
                $paper->update([
                    'spec' => $spec,
                    'total_marks' => (int) $paper->questions()->sum('marks'),
                ]);
            }
            $suggestion->status = 'approved';
            $suggestion->reviewed_by = $user->id;
            $suggestion->reviewed_at = now();
            $suggestion->save();
        });

        Audit::log('ai.suggestion_approved', $suggestion, 'Teacher ' . $user->name . ' approved AI suggestion ' . $suggestion->id
            . ' (to ' . $target . ')' . ($suggestion->bank_question_id ? ', bank question ' . $suggestion->bank_question_id : '')
            . ($toPaper && $paper ? ', paper ' . $paper->id : '') . '.');

        return back()->with('flash_success', 'Suggestion approved.');
    }

    public function aiReject(Request $request, AiQuestionSuggestion $suggestion)
    {
        $user = $this->requireOwnSuggestion($request, $suggestion);
        if ($suggestion->status !== 'pending') {
            return back()->with('flash_danger', 'This suggestion has already been ' . $suggestion->status . ' — rejection is final for a generation.');
        }
        $suggestion->update([
            'status' => 'rejected',
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);
        Audit::log('ai.suggestion_rejected', $suggestion, 'Teacher ' . $user->name . ' rejected AI suggestion ' . $suggestion->id . '.');

        return back()->with('flash_success', 'Suggestion rejected.');
    }
}
