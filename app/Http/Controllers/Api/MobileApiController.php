<?php

namespace App\Http\Controllers\Api;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Http\Controllers\SupportTeam\TimeTableController;
use App\Models\AttendanceSessionEntry;
use App\Models\Exam;
use App\Models\ExamRecord;
use App\Models\Mark;
use App\Models\ParentMessage;
use App\Models\StudentRecord;
use App\Support\Notify;
use App\Support\SectionTeacher;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Mobile app API (Sanctum token auth) — read-only, own-data only.
 *
 * Scoping is the whole point of this controller:
 *  - students see ONLY their own rows;
 *  - parents see ONLY their own children's rows (?child must be one of
 *    their own StudentRecords, otherwise 403 — stricter than the web
 *    tamper-guard fallback, because an API must never silently swap
 *    the child);
 *  - teachers see ONLY their own classes (effective-teacher rule,
 *    section overrides included) via the existing week dataset;
 *  - Super Admin gets NOTHING here (platform-only by design);
 *  - teachers get NO attendance endpoint at all: a teacher's taken
 *    sessions are submitted registers, and submitted registers are
 *    gone from the teacher's end by owner rule.
 *
 * Marks follow the web Result Publish gate: published exams only.
 */
class MobileApiController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'identity' => 'required|string',
            'password' => 'required|string',
        ]);

        $identity = $request->input('identity');
        $field = filter_var($identity, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
        $user = User::where($field, $identity)->first();

        if (!$user || !Hash::check($request->input('password'), $user->password)) {
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        if ($user->getAttribute('status') && $user->getAttribute('status') !== 'active') {
            return response()->json(['message' => 'This account is not active. Please contact the school administrator.'], 403);
        }

        if ($user->user_type === 'super_admin') {
            return response()->json(['message' => 'Platform accounts do not use the mobile app.'], 403);
        }

        return response()->json([
            'token' => $user->createToken('mobile')->plainTextToken,
            'user' => $this->userSummary($user),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    /**
     * Lifecycle gate for API data routes (C-R34-1, roles round 34).
     * The web session ends on the next request after a suspension
     * (EnsureActiveAccount) and API login refuses non-active
     * accounts (403), but the api group previously carried no
     * per-request status check — so a Sanctum token minted BEFORE
     * the status change kept yielding data on every data route.
     * This guard mirrors the login gate's message and status code.
     * Logout is deliberately NOT guarded: a suspended user must
     * still be able to revoke her own token. Token rows are not
     * deleted here; enforcement is per-request.
     */
    protected function inactiveAccountResponse(Request $request)
    {
        $user = $request->user();
        if ($user && $user->getAttribute('status') && $user->getAttribute('status') !== 'active') {
            return response()->json(['message' => 'This account is not active. Please contact the school administrator.'], 403);
        }

        return null;
    }

    public function me(Request $request)
    {
        if ($blocked = $this->inactiveAccountResponse($request)) {
            return $blocked;
        }

        return response()->json(['user' => $this->userSummary($request->user())]);
    }

    public function timetableMine(Request $request)
    {
        if ($blocked = $this->inactiveAccountResponse($request)) {
            return $blocked;
        }

        $user = $request->user();
        $tt = app(TimeTableController::class);

        if ($user->user_type === 'teacher') {
            $d = $tt->apiTeacherWeek($user);

            return response()->json([
                'mode' => 'teacher',
                'period_count' => $d['period_count'],
                'stats' => $d['stats'],
                'entries' => $d['entries'],
            ]);
        }

        if ($user->user_type === 'student' || $user->user_type === 'parent') {
            $child = $this->resolveChild($request, $user);
            if ($child === false) {
                return response()->json(['message' => 'Not your child.'], 403);
            }
            if (!$child) {
                return response()->json(['mode' => $user->user_type, 'entries' => []]);
            }

            $ttr = SectionTeacher::recordForClassSection($child->my_class_id, $child->section_id ?? null, Qs::getCurrentSession());
            if (!$ttr) {
                return response()->json(['mode' => $user->user_type, 'entries' => []]);
            }

            $grid = $tt->apiRecordGrid($ttr);
            $entries = [];
            foreach ($grid['map'] as $day => $slots) {
                foreach ($slots as $slot => $cell) {
                    $entries[] = [
                        'day' => $day,
                        'slot' => $slot,
                        'subject' => $cell['title'],
                        'teacher' => $cell['sub'],
                    ];
                }
            }

            return response()->json([
                'mode' => $user->user_type,
                'class' => optional($child->my_class)->name,
                'entries' => $entries,
            ]);
        }

        return response()->json(['message' => 'Not available for this account type.'], 403);
    }

    public function marksMine(Request $request)
    {
        if ($blocked = $this->inactiveAccountResponse($request)) {
            return $blocked;
        }

        $user = $request->user();
        if (!in_array($user->user_type, ['student', 'parent'], true)) {
            return response()->json(['message' => 'Not available for this account type.'], 403);
        }
        $target = $this->resolveTargetStudentId($request, $user);
        if ($target === false) {
            return response()->json(['message' => 'Not your child.'], 403);
        }
        if (!$target) {
            return response()->json(['exams' => []]);
        }

        $year = Qs::getCurrentSession();
        $exams = Exam::where('year', $year)->where('results_published', true)->orderBy('id')->get();
        $examIds = $exams->pluck('id');

        $marks = Mark::with(['subject', 'markComponents.component'])
            ->where('student_id', $target)
            ->where('year', $year)
            ->whereIn('exam_id', $examIds)
            ->get()
            ->groupBy('exam_id');

        $records = ExamRecord::where('student_id', $target)
            ->where('year', $year)
            ->whereIn('exam_id', $examIds)
            ->get()
            ->keyBy('exam_id');

        $out = [];
        foreach ($exams as $exam) {
            $record = $records->get($exam->id);
            $out[] = [
                'exam' => ['id' => $exam->id, 'name' => $exam->name, 'term' => $exam->term, 'year' => $exam->year],
                'record' => $record ? [
                    'total' => $record->total,
                    'average' => $record->ave,
                    'position' => $record->pos,
                ] : null,
                'subjects' => ($marks->get($exam->id) ?: collect())->map(function ($mark) {
                    return [
                        'subject' => optional($mark->subject)->name,
                        'tca' => $mark->tca,
                        'exam' => $mark->exm,
                        'components' => $mark->markComponents->map(function ($component) {
                            return [
                                'name' => optional($component->component)->name,
                                'score' => $component->score,
                                'max' => optional($component->component)->max_marks,
                            ];
                        })->values(),
                    ];
                })->values(),
            ];
        }

        return response()->json(['exams' => $out]);
    }

    public function attendanceMine(Request $request)
    {
        if ($blocked = $this->inactiveAccountResponse($request)) {
            return $blocked;
        }

        $user = $request->user();
        if (!in_array($user->user_type, ['student', 'parent'], true)) {
            return response()->json(['message' => 'Not available for this account type.'], 403);
        }
        $target = $this->resolveTargetStudentId($request, $user);
        if ($target === false) {
            return response()->json(['message' => 'Not your child.'], 403);
        }
        if (!$target) {
            return response()->json(['percentage' => null, 'sessions' => []]);
        }

        $recordIds = StudentRecord::where('user_id', $target)->pluck('id');
        $entries = AttendanceSessionEntry::with(['session.subject'])
            ->whereIn('student_record_id', $recordIds)
            ->get();

        $marked = $entries->count();
        $present = $entries->whereIn('status', ['present', 'late'])->count();
        $sessions = $entries->map(function ($entry) {
            return [
                'date' => optional($entry->session)->date ? $entry->session->date->format('Y-m-d') : null,
                'subject' => optional(optional($entry->session)->subject)->name,
                'status' => $entry->status,
                'topic' => optional($entry->session)->topic,
            ];
        })->sortByDesc('date')->values();

        return response()->json([
            'percentage' => $marked ? round($present / $marked * 100, 1) : null,
            'marked' => $marked,
            'present_or_late' => $present,
            'sessions' => $sessions,
        ]);
    }

    public function notificationsMine(Request $request)
    {
        if ($blocked = $this->inactiveAccountResponse($request)) {
            return $blocked;
        }

        $items = Notify::latestFor($request->user()->id, 20);

        return response()->json(['notifications' => $items->map(function ($item) {
            return [
                'id' => $item->id,
                'type' => $item->type,
                'title' => $item->title,
                'body' => $item->body,
                'link' => $item->link,
                'read_at' => $item->read_at,
                'created_at' => $item->created_at,
            ];
        })->values()]);
    }

    public function messagesMine(Request $request)
    {
        if ($blocked = $this->inactiveAccountResponse($request)) {
            return $blocked;
        }

        $user = $request->user();
        if ($user->user_type !== 'parent') {
            return response()->json(['message' => 'Parent messages are for parent accounts.'], 403);
        }

        $messages = ParentMessage::with(['sender', 'my_class', 'section'])
            ->addressedToParent($user->id)
            ->latest()
            ->take(50)
            ->get();

        return response()->json(['messages' => $messages->map(function ($message) {
            return [
                'id' => $message->id,
                'title' => $message->title,
                'body' => $message->body,
                'class' => optional($message->my_class)->name,
                'section' => optional($message->section)->name,
                'sender' => optional($message->sender)->name,
                'created_at' => $message->created_at,
            ];
        })->values()]);
    }

    /**
     * Student id the caller may see marks/attendance for, or false
     * when a ?child id is foreign, or null when nobody is linked yet.
     * Callers pre-check the role; only students and parents reach here.
     */
    protected function resolveTargetStudentId(Request $request, $user)
    {
        if ($user->user_type === 'student') {
            return $user->id;
        }

        if ($user->user_type === 'parent') {
            $child = $this->resolveChild($request, $user);
            if ($child === false) {
                return false;
            }

            return $child ? $child->user_id : null;
        }

        return false;
    }

    /**
     * The caller's child StudentRecord: student callers resolve their
     * own record; parent callers resolve ?child (must be their own)
     * or their first child. Returns false on a foreign ?child id.
     */
    protected function resolveChild(Request $request, $user)
    {
        if ($user->user_type === 'student') {
            return Qs::findStudentRecord($user->id);
        }

        $children = Qs::findMyChildren($user->id);
        if ($request->query('child')) {
            $child = $children->firstWhere('id', (int) $request->query('child'));

            return $child ?: false;
        }

        return $children->first();
    }

    protected function userSummary($user)
    {
        $summary = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'user_type' => $user->user_type,
            'photo' => Qs::hasRealPhoto($user->photo) ? $user->photo : null,
            'initials' => Qs::initials($user->name),
        ];

        if ($user->user_type === 'student') {
            $record = Qs::findStudentRecord($user->id);
            $summary['class'] = $record ? optional($record->my_class)->name : null;
            $summary['adm_no'] = $record ? $record->adm_no : null;
        }

        if ($user->user_type === 'parent') {
            $summary['children'] = Qs::findMyChildren($user->id)->map(function ($child) {
                return [
                    'student_record_id' => $child->id,
                    'name' => optional($child->user)->name,
                    'class' => optional($child->my_class)->name,
                ];
            })->values();
        }

        return $summary;
    }
}
