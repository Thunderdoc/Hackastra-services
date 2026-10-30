<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\Mark;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\Section;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\Repositories\ExamRepo;
use App\Support\Audit;
use App\Support\MarkComputation;
use App\Support\MarkScheme;
use App\Support\QuizBulkParser;
use App\Support\SectionTeacher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Wave 1 feature 1 — Online test/quiz (2026-10-28).
 *
 * Controller-guarded: these routes carry NO team middleware (students
 * must reach attempts), so every action gates its own role/scope here
 * and aborts 403 on violation. Super Admin reaches none of these
 * routes (they are not PlatformOnly-listed).
 *
 * Scoping rules:
 *  - Teacher: own quizzes only, and only for classes/subjects they
 *    teach — resolved through SectionTeacher::teacherIdFor so a
 *    section override teacher counts, not just the class teacher.
 *  - Admin: all quizzes, read-only on results (create/store are
 *    teacher-only).
 *  - Student: quizzes for own class, and only when the quiz is
 *    whole-class (section_id null) or targets their own section.
 *    One attempt per student per quiz.
 *
 * Quiz v2 (2026-10-28): quizzes are DRAFT until published. Students
 * see and attempt only published quizzes inside their timing window
 * (available_from/until, null = open); the countdown still uses
 * time_limit_minutes. Questions and timings lock once attempts
 * exist. Push-to-Internal is an EXPLICIT teacher action on the
 * results page only (never automatic): quiz percentages scale onto
 * a configured Internal component via MarkScheme::saveComponentScores.
 *
 * Time limit: stored and enforced in the browser by a countdown that
 * auto-submits at zero. Strict server-side timeout rejection is NOT
 * built — the server records submitted_at and grades what arrives.
 */
class QuizController extends Controller
{
    protected function studentRecord()
    {
        return StudentRecord::where('user_id', Auth::id())->first();
    }

    protected function studentInScope(Quiz $quiz, $record)
    {
        if (!$record) {
            return false;
        }
        if ((int) $quiz->my_class_id !== (int) $record->my_class_id) {
            return false;
        }
        if ($quiz->section_id && (int) $quiz->section_id !== (int) $record->section_id) {
            return false;
        }
        return true;
    }

    protected function teacherMayQuiz($teacherId, $subject, $sectionId)
    {
        if (!$subject) {
            return false;
        }
        return SectionTeacher::teacherIdFor($sectionId, $subject) === (int) $teacherId;
    }

    protected function teacherSubjects($teacherId)
    {
        $direct = Subject::where('teacher_id', $teacherId)->pluck('id');
        $override = DB::table('section_subject_teachers')
            ->where('teacher_id', $teacherId)
            ->pluck('subject_id');
        return Subject::with(['my_class'])
            ->whereIn('id', $direct->merge($override)->unique()->values())
            ->orderBy('my_class_id')
            ->orderBy('name')
            ->get();
    }

    /** Published AND inside the timing window (null bound = open). */
    protected function isAvailable(Quiz $quiz): bool
    {
        if ($quiz->status !== 'published') {
            return false;
        }
        $now = now();
        if ($quiz->available_from && $now->lt($quiz->available_from)) {
            return false;
        }
        if ($quiz->available_until && $now->gt($quiz->available_until)) {
            return false;
        }
        return true;
    }

    protected function teacherOwns(Quiz $quiz, $userId): bool
    {
        return (int) $quiz->teacher_id === (int) $userId;
    }

    /** Questions and timings lock once any attempt exists. */
    protected function canEditQuestions(Quiz $quiz): bool
    {
        return $quiz->attempts()->count() === 0;
    }

    /** The Internal component of a configured scheme, if one exists.
     * Legacy-column components are never Internal (mirrors the
     * assignment internal-marks bridge). */
    protected function internalComponent($examId, $subjectId)
    {
        foreach (MarkScheme::components($examId, $subjectId) as $component) {
            if ($component['field'] !== null) {
                continue;
            }
            if ($component['name'] === 'Internal'
                || $component['name'] === 'Internal Assessment'
                || $component['short_code'] === 'INT'
                || stripos($component['name'], 'Internal') === 0) {
                return $component;
            }
        }
        return null;
    }

    public function index()
    {
        $user = Auth::user();

        if (Qs::userIsTeacher()) {
            $quizzes = Quiz::with(['subject', 'my_class', 'section', 'teacher'])
                ->withCount('questions')
                ->where('teacher_id', $user->id)
                ->latest()
                ->get();
        } elseif (Qs::userIsAdmin()) {
            $quizzes = Quiz::with(['subject', 'my_class', 'section', 'teacher'])
                ->withCount('questions')
                ->latest()
                ->get();
        } elseif (Qs::userIsStudent()) {
            $record = $this->studentRecord();
            if (!$record) {
                abort(403);
            }
            $quizzes = Quiz::with(['subject', 'my_class', 'section', 'teacher'])
                ->withCount('questions')
                ->where('my_class_id', $record->my_class_id)
                ->where(function ($q) use ($record) {
                    $q->whereNull('section_id')->orWhere('section_id', $record->section_id);
                })
                ->latest()
                ->get();
            // Students see published quizzes inside their window — plus
            // quizzes they already attempted (an expired window must
            // never hide a student's own result).
            $attemptedIds = QuizAttempt::where('student_record_id', $record->id)->pluck('quiz_id')->all();
            $quizzes = $quizzes->filter(function ($quiz) use ($attemptedIds) {
                return $this->isAvailable($quiz) || in_array($quiz->id, $attemptedIds);
            })->values();
        } else {
            abort(403);
        }

        $attempts = [];
        if (Qs::userIsStudent()) {
            $record = $this->studentRecord();
            if ($record) {
                $attempts = QuizAttempt::where('student_record_id', $record->id)
                    ->get()
                    ->keyBy('quiz_id');
            }
        }

        return view('pages.support_team.quizzes.index', compact('quizzes', 'attempts'));
    }

    public function create()
    {
        if (!Qs::userIsTeacher()) {
            abort(403);
        }
        $subjects = $this->teacherSubjects(Auth::id());
        $classIds = $subjects->pluck('my_class_id')->unique()->values();
        $sections = Section::whereIn('my_class_id', $classIds)->orderBy('my_class_id')->orderBy('id')->get();
        return view('pages.support_team.quizzes.create', compact('subjects', 'sections'));
    }

    public function store(Request $req)
    {
        if (!Qs::userIsTeacher()) {
            abort(403);
        }

        $req->validate([
            'title' => 'required|string|max:255',
            'subject_id' => 'required|exists:subjects,id',
            'section_id' => 'nullable|exists:sections,id',
            'time_limit_minutes' => 'nullable|integer|min:1|max:600',
            'bulk_marks' => 'nullable|integer|min:1|max:100',
        ]);

        // Quiz v2: the bulk box and the inline rows are two modes for
        // the same quiz — never both at once, so nothing is silently
        // ignored. A failed bulk parse returns every bad block to the
        // teacher with the original text preserved.
        $bulkText = trim((string) $req->input('bulk_text', ''));
        $inlineFilled = false;
        foreach ((array) $req->input('questions', []) as $row) {
            if (is_array($row) && trim((string) ($row['question'] ?? '')) !== '') {
                $inlineFilled = true;
                break;
            }
        }

        $parsed = null;
        if ($bulkText !== '' && $inlineFilled) {
            return back()->withInput()->withErrors(['bulk_text' => 'Fill either the bulk box or the inline questions, not both.']);
        }
        if ($bulkText !== '') {
            $parsed = QuizBulkParser::parse($bulkText, (int) ($req->input('bulk_marks') ?: 1));
            if (!empty($parsed['errors'])) {
                return back()->withInput()->withErrors(['bulk_text' => $parsed['errors']]);
            }
            if (empty($parsed['questions'])) {
                return back()->withInput()->withErrors(['bulk_text' => 'No questions could be parsed from the bulk box.']);
            }
        } else {
            $validator = Validator::make($req->all(), [
                'questions' => 'required|array|min:1',
                'questions.*.question' => 'required|string',
                'questions.*.options' => 'required|array|min:2',
                'questions.*.options.*' => 'nullable|string|max:1000',
                'questions.*.correct_index' => 'required|integer|min:0|max:3',
                'questions.*.marks' => 'nullable|integer|min:1|max:100',
            ]);

            $validator->after(function ($v) use ($req) {
                foreach ((array) $req->input('questions', []) as $i => $row) {
                    $options = array_values(array_filter(array_map(function ($o) {
                        return is_string($o) ? trim($o) : $o;
                    }, (array) ($row['options'] ?? [])), function ($o) {
                        return $o !== '' && $o !== null;
                    }));
                    if (count($options) < 2) {
                        $v->errors()->add("questions.$i.options", 'Each question needs at least 2 non-empty options.');
                        continue;
                    }
                    $rawOptions = (array) ($row['options'] ?? []);
                    $correct = $row['correct_index'] ?? null;
                    if ($correct === null || !isset($rawOptions[$correct]) || trim((string) $rawOptions[$correct]) === '') {
                        $v->errors()->add("questions.$i.correct_index", 'The correct option must be one of the non-empty options.');
                    }
                }
            });

            $validator->validate();
        }

        $subject = Subject::find($req->input('subject_id'));
        if (!$this->teacherMayQuiz(Auth::id(), $subject, $req->input('section_id'))) {
            abort(403);
        }
        if ($req->input('section_id')) {
            $section = Section::find($req->input('section_id'));
            if (!$section || (int) $section->my_class_id !== (int) $subject->my_class_id) {
                return back()->withInput()->withErrors(['section_id' => 'The section does not belong to the subject\'s class.']);
            }
        }

        $quiz = Quiz::create([
            'teacher_id' => Auth::id(),
            'my_class_id' => $subject->my_class_id,
            'section_id' => $req->input('section_id') ?: null,
            'subject_id' => $subject->id,
            'title' => $req->input('title'),
            'time_limit_minutes' => $req->input('time_limit_minutes') ?: null,
            'status' => 'draft',
        ]);

        $rows = $parsed ? $parsed['questions'] : $req->input('questions');
        foreach ($rows as $row) {
            $rawOptions = (array) $row['options'];
            $kept = [];
            $remap = [];
            foreach ($rawOptions as $idx => $opt) {
                if (is_string($opt) && trim($opt) === '') {
                    continue;
                }
                $remap[$idx] = count($kept);
                $kept[] = $opt;
            }
            QuizQuestion::create([
                'quiz_id' => $quiz->id,
                'question' => $row['question'],
                'options' => $kept,
                'correct_index' => $remap[(int) $row['correct_index']],
                'marks' => isset($row['marks']) && $row['marks'] !== '' ? (int) $row['marks'] : 1,
            ]);
        }

        return redirect()->route('quizzes.review', $quiz->id)
            ->with('flash_success', 'Quiz saved as a draft — review the questions, then publish it with its timings.');
    }

    public function show(Quiz $quiz)
    {
        $quiz->load(['questions', 'subject', 'my_class', 'section', 'teacher']);
        $user = Auth::user();

        if (Qs::userIsTeacher()) {
            if ((int) $quiz->teacher_id !== (int) $user->id) {
                abort(403);
            }
        } elseif (Qs::userIsAdmin()) {
            // Admin reads everything.
        } elseif (Qs::userIsStudent()) {
            $record = $this->studentRecord();
            if (!$this->studentInScope($quiz, $record)) {
                abort(403);
            }
            // A draft, unpublished, or out-of-window quiz is invisible
            // to students — unless they already attempted it, in which
            // case they keep their own result.
            $hasAttempt = $record && QuizAttempt::where('quiz_id', $quiz->id)
                ->where('student_record_id', $record->id)->exists();
            if (!$hasAttempt && !$this->isAvailable($quiz)) {
                abort(403);
            }
        } else {
            abort(403);
        }

        $myAttempt = null;
        if (Qs::userIsStudent()) {
            $record = $this->studentRecord();
            if ($record) {
                $myAttempt = QuizAttempt::with(['answers.question'])
                    ->where('quiz_id', $quiz->id)
                    ->where('student_record_id', $record->id)
                    ->first();
            }
        }

        return view('pages.support_team.quizzes.show', compact('quiz', 'myAttempt'));
    }

    public function attempt(Quiz $quiz)
    {
        if (!Qs::userIsStudent()) {
            abort(403);
        }
        $record = $this->studentRecord();
        if (!$this->studentInScope($quiz, $record)) {
            abort(403);
        }
        if (!$this->isAvailable($quiz)) {
            return redirect()->route('quizzes.index')
                ->with('flash_danger', 'This quiz is not available right now (draft, or outside its published timing window).');
        }
        $existing = QuizAttempt::where('quiz_id', $quiz->id)
            ->where('student_record_id', $record->id)
            ->first();
        if ($existing) {
            return redirect()->route('quizzes.show', $quiz->id)
                ->with('flash_success', 'You have already attempted this quiz. Your result is below.');
        }
        // Timed quizzes stamp their start on the SERVER the first
        // time this page opens (loophole closed 2026-10-30): the
        // countdown — and the submit check — run from this stamp,
        // so refreshing or reopening never buys more time.
        $secondsLeft = null;
        if ($quiz->time_limit_minutes) {
            $start = \App\Models\QuizAttemptStart::firstOrCreate(
                ['quiz_id' => $quiz->id, 'student_record_id' => $record->id],
                ['started_at' => now()]
            );
            $deadline = $start->started_at->copy()->addMinutes((int) $quiz->time_limit_minutes);
            $secondsLeft = max(0, (int) now()->diffInSeconds($deadline, false));
            if ($secondsLeft <= 0) {
                return redirect()->route('quizzes.index')
                    ->with('flash_danger', 'Your time for this quiz has run out.');
            }
        }
        $quiz->load(['questions', 'subject', 'my_class', 'section']);
        return view('pages.support_team.quizzes.attempt', compact('quiz', 'secondsLeft'));
    }

    public function submitAttempt(Request $req, Quiz $quiz)
    {
        if (!Qs::userIsStudent()) {
            abort(403);
        }
        $record = $this->studentRecord();
        if (!$this->studentInScope($quiz, $record)) {
            abort(403);
        }
        $existing = QuizAttempt::where('quiz_id', $quiz->id)
            ->where('student_record_id', $record->id)
            ->first();
        if ($existing) {
            return redirect()->route('quizzes.show', $quiz->id)
                ->with('flash_success', 'You have already attempted this quiz. Your result is below.');
        }
        if (!$this->isAvailable($quiz)) {
            return redirect()->route('quizzes.index')
                ->with('flash_danger', 'This quiz is not available right now (draft, or outside its published timing window). Nothing was submitted.');
        }
        // Server-side time enforcement (loophole closed
        // 2026-10-30): a timed quiz can only be submitted from a
        // stamped start, inside started_at + the limit (plus a
        // 60-second grace for a slow connection). The client
        // countdown alone was never a check.
        if ($quiz->time_limit_minutes) {
            $start = \App\Models\QuizAttemptStart::where('quiz_id', $quiz->id)
                ->where('student_record_id', $record->id)
                ->first();
            if (!$start) {
                return redirect()->route('quizzes.index')
                    ->with('flash_danger', 'Please open the quiz from its page first — the timer starts there. Nothing was submitted.');
            }
            $deadline = $start->started_at->copy()
                ->addMinutes((int) $quiz->time_limit_minutes)->addSeconds(60);
            if (now()->greaterThan($deadline)) {
                return redirect()->route('quizzes.index')
                    ->with('flash_danger', 'Your time for this quiz has run out — the submission was not accepted.');
            }
        }

        $quiz->load('questions');
        $req->validate([
            'answers' => 'nullable|array',
            'answers.*' => 'nullable|integer|min:0',
        ]);

        $score = 0;
        $total = 0;
        foreach ($quiz->questions as $question) {
            $total += (int) $question->marks;
        }

        $attempt = QuizAttempt::create([
            'quiz_id' => $quiz->id,
            'student_record_id' => $record->id,
            'score' => 0,
            'total' => $total,
            'submitted_at' => now(),
        ]);

        foreach ($quiz->questions as $question) {
            $selected = $req->input("answers.{$question->id}");
            $selected = $selected === null || $selected === '' ? null : (int) $selected;
            $correct = $selected !== null && $selected === (int) $question->correct_index;
            if ($correct) {
                $score += (int) $question->marks;
            }
            QuizAnswer::create([
                'attempt_id' => $attempt->id,
                'question_id' => $question->id,
                'selected_index' => $selected,
                'is_correct' => $correct,
            ]);
        }

        $attempt->update(['score' => $score]);

        return redirect()->route('quizzes.show', $quiz->id)
            ->with('flash_success', "Quiz submitted. Your score: {$score} / {$total}.");
    }

    public function results(Quiz $quiz)
    {
        if (Qs::userIsTeacher()) {
            if ((int) $quiz->teacher_id !== (int) Auth::id()) {
                abort(403);
            }
        } elseif (Qs::userIsAdmin()) {
            // Admin reads everything.
        } else {
            abort(403);
        }

        $quiz->load(['questions', 'subject', 'my_class', 'section']);
        $attempts = QuizAttempt::with(['student_record.user', 'answers.question'])
            ->where('quiz_id', $quiz->id)
            ->latest('submitted_at')
            ->get();

        // Quiz v2 push-to-Internal: teacher-owner only. The exam list
        // is global exams + this quiz's class exams; availability is
        // computed per exam so the page states plainly which exams
        // lack a configured Internal component (legacy scheme).
        $canPush = Qs::userIsTeacher() && $this->teacherOwns($quiz, Auth::id());
        $exams = collect();
        $internalAvailability = [];
        if ($canPush) {
            $exams = Exam::where(function ($q) use ($quiz) {
                $q->where('my_class_id', $quiz->my_class_id)->orWhere('my_class_id', 0);
            })->latest()->get();
            foreach ($exams as $exam) {
                $internalAvailability[$exam->id] = $this->internalComponent($exam->id, $quiz->subject_id);
            }
        }

        return view('pages.support_team.quizzes.results', compact('quiz', 'attempts', 'canPush', 'exams', 'internalAvailability'));
    }

    /** Quiz v2 review page: questions + publish form. Teacher-owner
     * edits; admin views read-only; foreign teachers get 403. */
    public function review(Quiz $quiz)
    {
        $quiz->load(['questions', 'subject', 'my_class', 'section', 'teacher']);
        if (Qs::userIsTeacher()) {
            if (!$this->teacherOwns($quiz, Auth::id())) {
                abort(403);
            }
        } elseif (!Qs::userIsAdmin()) {
            abort(403);
        }
        $canEdit = Qs::userIsTeacher() && $this->canEditQuestions($quiz);
        $attemptsCount = $quiz->attempts()->count();
        return view('pages.support_team.quizzes.review', compact('quiz', 'canEdit', 'attemptsCount'));
    }

    /** Validate one review-form question; returns the cleaned row
     * (options re-indexed, correct_index remapped). */
    protected function validateReviewQuestion(Request $req)
    {
        $validator = Validator::make($req->all(), [
            'question' => 'required|string',
            'options' => 'required|array|min:2',
            'options.*' => 'nullable|string|max:1000',
            'correct_index' => 'required|integer|min:0',
            'marks' => 'nullable|integer|min:1|max:100',
        ]);
        $validator->after(function ($v) use ($req) {
            $options = array_values(array_filter(array_map(function ($o) {
                return is_string($o) ? trim($o) : $o;
            }, (array) $req->input('options', [])), function ($o) {
                return $o !== '' && $o !== null;
            }));
            if (count($options) < 2) {
                $v->errors()->add('options', 'Each question needs at least 2 non-empty options.');
                return;
            }
            $raw = (array) $req->input('options', []);
            $correct = $req->input('correct_index');
            if ($correct === null || !isset($raw[$correct]) || trim((string) $raw[$correct]) === '') {
                $v->errors()->add('correct_index', 'The correct option must be one of the non-empty options.');
            }
        });
        $validator->validate();
        $kept = [];
        $remap = [];
        foreach ((array) $req->input('options') as $idx => $opt) {
            if (is_string($opt) && trim($opt) === '') {
                continue;
            }
            $remap[$idx] = count($kept);
            $kept[] = $opt;
        }
        return [
            'question' => $req->input('question'),
            'options' => $kept,
            'correct_index' => $remap[(int) $req->input('correct_index')],
            'marks' => $req->input('marks') !== null && $req->input('marks') !== '' ? (int) $req->input('marks') : 1,
        ];
    }

    public function storeQuestion(Request $req, Quiz $quiz)
    {
        if (!Qs::userIsTeacher() || !$this->teacherOwns($quiz, Auth::id())) {
            abort(403);
        }
        if (!$this->canEditQuestions($quiz)) {
            return back()->with('flash_danger', 'Questions are locked — this quiz already has attempts.');
        }
        $row = $this->validateReviewQuestion($req);
        QuizQuestion::create(array_merge(['quiz_id' => $quiz->id], $row));
        return back()->with('flash_success', 'Question added.');
    }

    public function updateQuestion(Request $req, Quiz $quiz, QuizQuestion $question)
    {
        if (!Qs::userIsTeacher() || !$this->teacherOwns($quiz, Auth::id())) {
            abort(403);
        }
        if ((int) $question->quiz_id !== (int) $quiz->id) {
            abort(404);
        }
        if (!$this->canEditQuestions($quiz)) {
            return back()->with('flash_danger', 'Questions are locked — this quiz already has attempts.');
        }
        $question->update($this->validateReviewQuestion($req));
        return back()->with('flash_success', 'Question updated.');
    }

    public function destroyQuestion(Quiz $quiz, QuizQuestion $question)
    {
        if (!Qs::userIsTeacher() || !$this->teacherOwns($quiz, Auth::id())) {
            abort(403);
        }
        if ((int) $question->quiz_id !== (int) $quiz->id) {
            abort(404);
        }
        if (!$this->canEditQuestions($quiz)) {
            return back()->with('flash_danger', 'Questions are locked — this quiz already has attempts.');
        }
        $question->delete();
        return back()->with('flash_success', 'Question deleted.');
    }

    public function publish(Request $req, Quiz $quiz)
    {
        if (!Qs::userIsTeacher() || !$this->teacherOwns($quiz, Auth::id())) {
            abort(403);
        }
        if ($quiz->status === 'published' && !$this->canEditQuestions($quiz)) {
            return back()->with('flash_danger', 'Timings are locked — this quiz already has attempts.');
        }
        if ($quiz->questions()->count() < 1) {
            return back()->with('flash_danger', 'Add at least one question before publishing.');
        }
        $req->validate([
            'available_from' => 'nullable|date',
            'available_until' => 'nullable|date',
            'time_limit_minutes' => 'nullable|integer|min:1|max:600',
        ]);
        if ($req->input('available_from') && $req->input('available_until')
            && strtotime($req->input('available_until')) <= strtotime($req->input('available_from'))) {
            return back()->withInput()->withErrors(['available_until' => 'The end of the window must be after its start.']);
        }
        $quiz->update([
            'status' => 'published',
            'available_from' => $req->input('available_from') ?: null,
            'available_until' => $req->input('available_until') ?: null,
            'time_limit_minutes' => $req->input('time_limit_minutes') ?: null,
        ]);
        return back()->with('flash_success', 'Quiz published.');
    }

    public function unpublish(Quiz $quiz)
    {
        if (!Qs::userIsTeacher() || !$this->teacherOwns($quiz, Auth::id())) {
            abort(403);
        }
        $count = $quiz->attempts()->count();
        if ($count > 0) {
            return back()->with('flash_danger', "Cannot unpublish — this quiz already has {$count} attempt(s).");
        }
        $quiz->update(['status' => 'draft']);
        return back()->with('flash_success', 'Quiz unpublished — back to draft.');
    }

    /** Quiz v2: explicit teacher action — quiz percentages scaled
     * onto a CONFIGURED Internal component. Never automatic, never
     * invents a component for legacy schemes, never writes when the
     * exam's results are published. Idempotent via updateOrCreate. */
    public function pushInternal(Request $req, Quiz $quiz)
    {
        if (!Qs::userIsTeacher() || !$this->teacherOwns($quiz, Auth::id())) {
            abort(403);
        }
        $exam = Exam::find($req->input('exam_id'));
        if (!$exam) {
            return back()->with('flash_danger', 'Please choose an exam.');
        }
        if ((int) $exam->my_class_id !== 0 && (int) $exam->my_class_id !== (int) $quiz->my_class_id) {
            return back()->with('flash_danger', 'That exam is not for this quiz\'s class.');
        }
        if ($exam->results_published) {
            return back()->with('flash_danger', 'Results for this exam are already published — quiz scores cannot be pushed into published results.');
        }
        $component = $this->internalComponent($exam->id, $quiz->subject_id);
        if (!$component) {
            return back()->with('flash_danger', 'This exam and subject use the legacy marking scheme, which has no configured Internal component — quiz scores cannot be pushed. Configure an Internal component for this exam and subject first.');
        }

        $examRepo = app(ExamRepo::class);
        $written = 0;
        $sectionsTouched = [];
        $attempts = QuizAttempt::with('student_record')->where('quiz_id', $quiz->id)->get();

        // Marks lifecycle (owner order 2026-10-29): a push is a
        // marks write — it respects the submit lock and the
        // exam-date gate for every section it would touch.
        foreach ($attempts->map(function ($a) { return optional($a->student_record)->section_id; })->filter()->unique() as $sid) {
            if ($reason = \App\Support\MarkLifecycle::blockReason($exam->id, $quiz->my_class_id, $sid, $quiz->subject_id)) {
                return back()->with('flash_danger', $reason);
            }
        }

        foreach ($attempts as $attempt) {
            $record = $attempt->student_record;
            if (!$record || !$record->user_id || (int) $attempt->total <= 0) {
                continue;
            }
            $percent = (int) $attempt->score / (int) $attempt->total * 100;
            $scaled = (int) round($percent / 100 * $component['max_marks']);
            $mark = Mark::firstOrCreate([
                'exam_id' => $exam->id,
                'student_id' => $record->user_id,
                'subject_id' => $quiz->subject_id,
                'my_class_id' => $quiz->my_class_id,
                'section_id' => $record->section_id,
                'year' => $exam->year,
            ]);
            $examRepo->createRecord([
                'exam_id' => $exam->id,
                'student_id' => $record->user_id,
                'my_class_id' => $quiz->my_class_id,
                'section_id' => $record->section_id,
                'year' => $exam->year,
            ]);
            MarkScheme::saveComponentScores($mark, [$component['id'] => $scaled]);
            $sectionsTouched[$record->section_id] = true;
            $written++;
        }
        foreach (array_keys($sectionsTouched) as $sectionId) {
            MarkComputation::recomputeSubject($exam, $quiz->my_class_id, $sectionId, $quiz->subject_id, $exam->year);
            MarkComputation::recomputeRecords($exam, $quiz->my_class_id, $sectionId, $exam->year);
        }

        Audit::log('quiz.internal_pushed', $quiz,
            'Teacher ' . Auth::user()->name . ' pushed quiz scores to Internal marks: quiz '
            . $quiz->id . ' (' . $quiz->title . '), exam ' . $exam->id . ' (' . $exam->name
            . '), class ' . $quiz->my_class_id . ', subject ' . $quiz->subject_id
            . ', students written: ' . $written
            . ', component: ' . $component['name'] . ' (max ' . $component['max_marks'] . ')');

        return back()->with('flash_success', 'Internal marks pushed for ' . $written . ' student(s).');
    }
}
