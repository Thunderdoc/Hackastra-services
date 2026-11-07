<?php

namespace App\Http\Controllers\SupportTeam;

use App\Http\Controllers\Controller;
use App\Models\MyClass;
use App\Models\QuestionBankQuestion;
use App\Models\Section;
use App\Models\Subject;
use App\Support\SectionTeacher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuestionBankController extends Controller
{
    /**
     * Teacher-only guard: the bank is teacher-owned, so admins (and
     * every other role) get a 403 on every action, including the
     * picker. Super Admin reaches none of these routes at all.
     */
    protected function teacher(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->user_type === 'teacher', 403);

        return $user;
    }

    /**
     * Subjects a teacher may bank questions for: subjects they own
     * at class level (subjects.teacher_id) plus subjects they teach
     * in a section via a section_subject_teachers override.
     */
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

    /**
     * Form pickers, scoped to the teacher's own classes/subjects.
     */
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

    /**
     * Shared store/update validation (F4 Slice 0 branches it on
     * question_type): MCQ keeps the original exact rules — options
     * arrive as four inputs; blanks are dropped, and at least two
     * must survive, with the correct option inside the surviving
     * range. Short/long questions carry no options at all; they
     * require a model answer instead (the answer key must never be
     * blank for a printed paper).
     */
    public function validatedData(Request $request, int $userId, ?QuestionBankQuestion $existing = null)
    {
        $type = $request->input('question_type');
        if (!in_array($type, ['mcq', 'short', 'long'], true)) {
            $type = $existing ? ($existing->question_type ?: 'mcq') : 'mcq';
        }

        $base = [
            'my_class_id' => 'required|integer|exists:my_classes,id',
            'subject_id' => 'required|integer|exists:subjects,id',
            'section_id' => 'nullable|integer|exists:sections,id',
            'question' => 'required|string',
            'marks' => 'required|integer|min:1',
            'chapter' => 'nullable|string|max:120',
            'difficulty' => 'nullable|in:easy,medium,hard',
            'question_type' => 'nullable|in:mcq,short,long',
        ];

        if ($type === 'mcq') {
            $request->validate($base + [
                'options' => 'required|array',
                'options.*' => 'nullable|string',
                'correct_index' => 'required|integer|min:0',
            ]);
        } else {
            $request->validate($base + [
                'model_answer' => 'required|string',
            ]);
        }

        $subject = Subject::find($request->input('subject_id'));

        // Allotment round 21 (F-R21-1), mirroring round 18's
        // assignment fix: an UPDATE that does not retarget the
        // question (same class/section/subject as the existing
        // row) gains no new teaching surface — the owner only
        // edits their own existing row — so it is exempt from
        // the teaching checks. Without this, removing an
        // override FROZE the owner's own questions: edit opens
        // (ownership-only) but every save failed validation,
        // while the new subject teacher cannot edit them either
        // (ownership gate) and admin cannot (teacher-only
        // guard). A retarget to a subject the teacher does not
        // teach is still refused below; creates ($existing null)
        // always check. The subject-class match check stays
        // always-on, as in the assignment fix.
        $sectionId = $request->input('section_id') ?: null;
        $retargeted = !$existing
            || (int) $request->input('my_class_id') !== (int) $existing->my_class_id
            || (int) ($sectionId ?? 0) !== (int) ($existing->section_id ?? 0)
            || (int) $request->input('subject_id') !== (int) $existing->subject_id;

        if ((int) $subject->my_class_id !== (int) $request->input('my_class_id')
            || ($retargeted && !in_array((int) $subject->id, $this->teacherSubjectIds($userId), true))) {
            throw ValidationException::withMessages([
                'subject_id' => 'You can only bank questions for subjects you teach.',
            ]);
        }

        if ($sectionId) {
            $section = Section::find($sectionId);
            if ((int) $section->my_class_id !== (int) $request->input('my_class_id')
                || ($retargeted && SectionTeacher::teacherIdFor((int) $sectionId, $subject) !== $userId)) {
                throw ValidationException::withMessages([
                    'section_id' => 'You can only bank questions for sections you teach.',
                ]);
            }
        }

        $chapter = trim((string) $request->input('chapter', ''));
        $difficulty = $request->input('difficulty') ?: null;

        if ($type !== 'mcq') {
            return [
                'my_class_id' => (int) $request->input('my_class_id'),
                'section_id' => $sectionId ? (int) $sectionId : null,
                'subject_id' => (int) $request->input('subject_id'),
                'question' => trim($request->input('question')),
                'options' => null,
                'correct_index' => null,
                'model_answer' => trim($request->input('model_answer')),
                'question_type' => $type,
                'chapter' => $chapter !== '' ? $chapter : null,
                'difficulty' => $difficulty,
                'marks' => (int) $request->input('marks'),
            ];
        }

        $options = array_values(array_filter(array_map(
            fn ($o) => is_string($o) ? trim($o) : $o,
            $request->input('options', [])
        ), fn ($o) => $o !== '' && $o !== null));

        if (count($options) < 2) {
            throw ValidationException::withMessages([
                'options' => 'Provide at least two non-empty options.',
            ]);
        }

        if ((int) $request->input('correct_index') >= count($options)) {
            throw ValidationException::withMessages([
                'correct_index' => 'The correct option must be one of the provided options.',
            ]);
        }

        return [
            'my_class_id' => (int) $request->input('my_class_id'),
            'section_id' => $sectionId ? (int) $sectionId : null,
            'subject_id' => (int) $request->input('subject_id'),
            'question' => trim($request->input('question')),
            'options' => $options,
            'correct_index' => (int) $request->input('correct_index'),
            'model_answer' => null,
            'question_type' => 'mcq',
            'chapter' => $chapter !== '' ? $chapter : null,
            'difficulty' => $difficulty,
            'marks' => (int) $request->input('marks'),
        ];
    }

    /**
     * The SAME per-type bank rules, applied to a plain data array
     * (F4 Slice 3: AI suggestions approved into the bank must pass
     * exactly what a hand-typed question passes — including the
     * teaching-scope checks inside validatedData()).
     *
     * @throws ValidationException
     */
    public function validatedArray(array $input, int $userId, ?QuestionBankQuestion $existing = null)
    {
        $request = Request::create('/', 'POST', $input);

        return $this->validatedData($request, $userId, $existing);
    }

    public function index(Request $request)
    {
        $user = $this->teacher($request);

        $query = QuestionBankQuestion::with(['my_class', 'section', 'subject'])
            ->where('teacher_id', $user->id)
            ->latest();

        if ($request->filled('class_id')) {
            $query->where('my_class_id', (int) $request->query('class_id'));
        }
        if ($request->filled('subject_id')) {
            $query->where('subject_id', (int) $request->query('subject_id'));
        }

        $d = $this->formData($user->id);
        $d['questions'] = $query->get();
        $d['selected_class_id'] = $request->query('class_id');
        $d['selected_subject_id'] = $request->query('subject_id');

        return view('pages.support_team.question_bank.index', $d);
    }

    /**
     * Quiz integration point: the requesting teacher's own bank
     * questions, filtered by class/subject (and section when
     * passed). Full list, no pagination — teacher banks are small.
     */
    public function picker(Request $request)
    {
        $user = $this->teacher($request);

        $query = QuestionBankQuestion::where('teacher_id', $user->id)
            ->orderBy('id');

        if ($request->filled('class_id')) {
            $query->where('my_class_id', (int) $request->query('class_id'));
        }
        if ($request->filled('subject_id')) {
            $query->where('subject_id', (int) $request->query('subject_id'));
        }
        if ($request->filled('section_id')) {
            $query->where('section_id', (int) $request->query('section_id'));
        }

        return response()->json($query->get()->map(fn ($q) => [
            'id' => $q->id,
            'question' => $q->question,
            'options' => $q->options,
            'marks' => $q->marks,
            // Owner-only endpoint (teacher guard above): the owning
            // teacher already knows the answer, and the quiz form
            // needs it to prefill the correct-option radio.
            'correct_index' => $q->correct_index,
        ])->values()->all());
    }

    public function create(Request $request)
    {
        $user = $this->teacher($request);

        return view('pages.support_team.question_bank.create', $this->formData($user->id));
    }

    public function store(Request $request)
    {
        $user = $this->teacher($request);
        $data = $this->validatedData($request, $user->id);
        $data['teacher_id'] = $user->id;

        QuestionBankQuestion::create($data);

        return redirect()->route('question_bank.index')
            ->with('flash_success', 'Question added to your bank.');
    }

    public function edit(Request $request, QuestionBankQuestion $question)
    {
        $user = $this->teacher($request);
        abort_unless((int) $question->teacher_id === (int) $user->id, 403);

        $d = $this->formData($user->id);
        $d['question'] = $question;

        return view('pages.support_team.question_bank.edit', $d);
    }

    public function update(Request $request, QuestionBankQuestion $question)
    {
        $user = $this->teacher($request);
        abort_unless((int) $question->teacher_id === (int) $user->id, 403);

        $question->update($this->validatedData($request, $user->id, $question));

        return redirect()->route('question_bank.index')
            ->with('flash_success', 'Bank question updated.');
    }

    public function destroy(Request $request, QuestionBankQuestion $question)
    {
        $user = $this->teacher($request);
        abort_unless((int) $question->teacher_id === (int) $user->id, 403);

        $question->delete();

        return redirect()->route('question_bank.index')
            ->with('flash_success', 'Bank question deleted.');
    }
}
