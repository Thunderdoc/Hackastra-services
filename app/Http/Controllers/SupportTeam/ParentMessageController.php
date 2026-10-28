<?php

namespace App\Http\Controllers\SupportTeam;

use App\Http\Controllers\Controller;
use App\Models\MyClass;
use App\Models\ParentMessage;
use App\Models\Section;
use App\Models\StudentRecord;
use App\Support\Notify;
use App\Support\TeacherScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Parent Messaging — class-wise, IN-APP ONLY.
 *
 * Admin or teacher composes a message for one class (section
 * optional). On store, every parent of a student in that class
 * (and section, when set) gets an in-app bell notification via
 * Notify::toUser pointing at the message. No SMS, no WhatsApp,
 * no email: those channels are paid and explicitly out of scope.
 *
 * Controller-guarded (no team middleware — the route group is
 * shared with parent/student surfaces): every action gates its
 * own roles below.
 *
 * TEACHER RULE (picked, documented here): the effective-teacher
 * check. A teacher may message a class when TeacherScope says so
 * — subjects.teacher_id in that class OR sections.teacher_id
 * (class-teacher) of a section of that class — plus teachers named
 * only via a section_subject_teachers override for a section of
 * that class. The class-teacher rule alone would lock out subject
 * teachers; the effective-teacher rule covers both, and it is the
 * app's canonical teacher scope, so it cannot drift from the
 * dashboard/attendance definitions of "my classes".
 *
 * READ RULE: a sender sees messages they sent; a parent sees
 * messages addressed to their children's classes (class match,
 * and section match when the message has a section). Show is
 * open only to the sender or an addressed parent — anyone else,
 * including a non-sending admin, gets 403.
 *
 * Route note: the route param is {message}, NOT {id}, so no
 * Qs hash binding applies — show() receives the raw record id.
 */
class ParentMessageController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $type = $user->user_type;

        if ($type === 'parent') {
            $messages = ParentMessage::with(['sender', 'my_class', 'section'])
                ->addressedToParent($user->id)
                ->latest()
                ->paginate(15);
        } elseif ($type === 'admin' || $type === 'teacher') {
            $messages = ParentMessage::with(['sender', 'my_class', 'section'])
                ->where('sender_id', $user->id)
                ->latest()
                ->paginate(15);
        } elseif ($type === 'principal') {
            // Limited principal role (owner order 2026-10-28):
            // Communication & Parent Relations is monitoring for
            // the principal — every parent message, school-wide.
            // Composing stays admin/teacher (can_compose below).
            $messages = ParentMessage::with(['sender', 'my_class', 'section'])
                ->latest()
                ->paginate(15);
        } else {
            abort(403);
        }

        $d['messages'] = $messages;
        $d['can_compose'] = in_array($type, ['admin', 'teacher'], true);
        $d['is_parent'] = $type === 'parent';

        return view('pages.support_team.parent_messages.index', $d);
    }

    public function create()
    {
        $user = Auth::user();
        if (!in_array($user->user_type, ['admin', 'teacher'], true)) {
            abort(403);
        }

        $d['classes'] = $this->composableClasses($user);
        $d['sections'] = Section::with('my_class')->whereIn('my_class_id', $d['classes']->pluck('id'))
            ->orderBy('my_class_id')->orderBy('name')->get();

        return view('pages.support_team.parent_messages.create', $d);
    }

    public function store()
    {
        $user = Auth::user();
        if (!in_array($user->user_type, ['admin', 'teacher'], true)) {
            abort(403);
        }

        $data = request()->validate([
            'my_class_id' => ['required', 'exists:my_classes,id'],
            'section_id' => [
                'nullable',
                Rule::exists('sections', 'id')->where('my_class_id', (int) request('my_class_id')),
            ],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
        ]);

        if ($user->user_type === 'teacher'
            && !$this->teacherCanMessageClass($user, (int) $data['my_class_id'])) {
            abort(403, 'You do not teach this class.');
        }

        $message = ParentMessage::create([
            'sender_id' => $user->id,
            'my_class_id' => (int) $data['my_class_id'],
            'section_id' => !empty($data['section_id']) ? (int) $data['section_id'] : null,
            'title' => $data['title'],
            'body' => $data['body'],
        ]);

        // Notify every parent of students in the class (and section,
        // when set) — recipients are parents found through
        // StudentRecord.my_parent_id. In-app bell only.
        $parentIds = StudentRecord::where('my_class_id', $message->my_class_id)
            ->when($message->section_id, function ($q) use ($message) {
                $q->where('section_id', $message->section_id);
            })
            ->whereNotNull('my_parent_id')
            ->pluck('my_parent_id')
            ->unique();

        $link = route('parent_messages.show', $message->id);
        foreach ($parentIds as $parentId) {
            Notify::toUser(
                (int) $parentId,
                'parent_message',
                $message->title,
                Str::limit($message->body, 200),
                $link
            );
        }

        return redirect()->route('parent_messages.index')
            ->with('flash_success', 'Message sent to parents.');
    }

    public function show($message)
    {
        $user = Auth::user();
        $msg = ParentMessage::with(['sender', 'my_class', 'section'])->findOrFail($message);

        $isSender = (int) $msg->sender_id === (int) $user->id;
        $isAddressedParent = $user->user_type === 'parent'
            && $msg->isAddressedToParent($user->id);
        // Round-2 roles fix: the principal's index lists EVERY message
        // school-wide (monitoring), so show must open them too —
        // otherwise every View link on the principal's board 403s.
        // Composing stays admin/teacher (create/store guards unchanged).
        $isPrincipal = \App\Helpers\Qs::userIsPrincipal();

        if (!$isSender && !$isAddressedParent && !$isPrincipal) {
            abort(403);
        }

        $d['message'] = $msg;

        return view('pages.support_team.parent_messages.show', $d);
    }

    /** Classes the user may compose for: all for admin, taught classes for a teacher. */
    protected function composableClasses($user)
    {
        if ($user->user_type === 'admin') {
            return MyClass::orderBy('name')->get();
        }

        return MyClass::whereIn('id', $this->teacherClassIds($user))
            ->orderBy('name')->get();
    }

    /** Effective-teacher class ids: TeacherScope + section-subject overrides. */
    protected function teacherClassIds($user): array
    {
        $ids = TeacherScope::classIds($user);

        if (Schema::hasTable('section_subject_teachers')) {
            $overrideIds = DB::table('section_subject_teachers')
                ->join('sections', 'sections.id', '=', 'section_subject_teachers.section_id')
                ->where('section_subject_teachers.teacher_id', (int) $user->id)
                ->pluck('sections.my_class_id')
                ->map(function ($id) { return (int) $id; })
                ->all();
            $ids = array_values(array_unique(array_merge($ids, $overrideIds)));
        }

        return $ids;
    }

    protected function teacherCanMessageClass($user, int $classId): bool
    {
        return in_array($classId, $this->teacherClassIds($user), true);
    }
}
