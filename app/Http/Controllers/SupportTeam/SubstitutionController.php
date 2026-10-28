<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Models\Substitution;
use App\Models\TimeTable;
use App\Models\UserNotification;
use App\Support\Notify;
use App\Support\SectionTeacher;
use App\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Wave 1 feature 4 (Substitution): cover for an absent teacher's
 * period on one date.
 *
 * These routes carry NO team middleware, so every action guards
 * itself here: Admin manages all substitutions; a teacher creates
 * only for periods they own (the period's effective teacher, via
 * SectionTeacher — section override else subject teacher) and
 * deletes only substitutions they created; index shows an admin
 * everything and a teacher only rows where they are absent or
 * substitute. Anyone else (including Super Admin, who has no
 * oversight role on this surface) gets a 403.
 *
 * Clash rule (store): the substitute must not already teach
 * another period at an overlapping time that day — checked against
 * (a) the substitute's own timetable cells on that date's weekday
 * and (b) existing substitutions for that substitute on that date.
 * Slot overlap reuses the timetable's own time concept (the cell's
 * time slot window); adjacent slots that merely touch do not clash.
 * Substitute-taking-attendance is deliberately NOT part of this
 * feature: the period-attendance take guard is untouched, so the
 * substitute still cannot submit the register.
 */
class SubstitutionController extends Controller
{
    /** List: admin sees all, teacher sees rows where they are absent or substitute. */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(403);
        }

        $query = Substitution::with([
            'time_table.subject', 'time_table.time_slot',
            'time_table.tt_record.my_class', 'time_table.tt_record.section',
            'absent_teacher', 'substitute', 'creator',
        ])->orderByDesc('date')->orderByDesc('id');

        if (Qs::userIsAdmin() || Qs::userIsPrincipal()) {
            // Round-2 roles fix: the principal's leave queue links its
            // "N without cover" badge here, so the board must open for
            // the principal (read-only monitoring). Arranging and
            // deleting cover stay admin/teacher (create/store/destroy
            // guards unchanged).
            $d['substitutions'] = $query->get();
        } elseif (Qs::userIsTeacher()) {
            $d['substitutions'] = $query->where(function ($q) use ($user) {
                $q->where('absent_teacher_id', $user->id)
                    ->orWhere('substitute_teacher_id', $user->id);
            })->get();
        } else {
            abort(403, 'Substitutions are managed by administrators and teachers.');
        }

        $d['can_delete'] = function (Substitution $sub) use ($user) {
            return Qs::userIsAdmin() || (int) $sub->created_by === (int) $user->id;
        };

        return view('pages.support_team.substitutions.index', $d);
    }

    /** Form: admin picks any period, a teacher only their own periods. */
    public function create(Request $request)
    {
        $user = $request->user();
        if (!$user || (!Qs::userIsAdmin() && !Qs::userIsTeacher())) {
            abort(403, 'Substitutions are managed by administrators and teachers.');
        }

        $d['periods'] = $this->periodOptions($user);
        $d['teachers'] = User::where('user_type', 'teacher')
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', User::STATUS_ACTIVE);
            })
            ->orderBy('name')->get();
        $d['selected_date'] = $request->query('date');
        $d['selected_period'] = $request->query('time_table_id');

        return view('pages.support_team.substitutions.create', $d);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user || (!Qs::userIsAdmin() && !Qs::userIsTeacher())) {
            abort(403, 'Substitutions are managed by administrators and teachers.');
        }

        $data = $request->validate([
            'date' => 'required|date_format:Y-m-d',
            'time_table_id' => 'required|integer|exists:time_tables,id',
            'substitute_teacher_id' => 'required|integer|exists:users,id',
            'reason' => 'nullable|string|max:500',
        ]);

        $date = Carbon::createFromFormat('Y-m-d', $data['date'])->startOfDay();
        $tt = TimeTable::with(['subject', 'time_slot', 'tt_record'])
            ->findOrFail((int) $data['time_table_id']);

        if (!$tt->subject) {
            throw ValidationException::withMessages([
                'time_table_id' => 'This timetable period has no subject assigned.',
            ]);
        }

        // The period's effective teacher (section override else
        // subject teacher) is the absent teacher — derived here,
        // never trusted from the request.
        $absentId = SectionTeacher::teacherIdFor(
            optional($tt->tt_record)->section_id ?? null, $tt->subject
        );

        // Create guard: admin, or the absent teacher themself. A
        // teacher may only create substitutions for periods they own.
        if (!Qs::userIsAdmin() && $absentId !== (int) $user->id) {
            abort(403, 'You can only create substitutions for your own periods.');
        }

        if (!$absentId) {
            throw ValidationException::withMessages([
                'time_table_id' => 'This period has no teacher assigned, so there is nobody to substitute for.',
            ]);
        }

        $substitute = User::findOrFail((int) $data['substitute_teacher_id']);

        if ((int) $substitute->id === (int) $absentId) {
            throw ValidationException::withMessages([
                'substitute_teacher_id' => 'The substitute cannot be the absent teacher.',
            ]);
        }

        if ($substitute->user_type !== 'teacher' || !$substitute->isActiveAccount()) {
            throw ValidationException::withMessages([
                'substitute_teacher_id' => 'The substitute must be an active teacher.',
            ]);
        }

        // Same weekday rule as period attendance: a period only runs
        // on its own weekday.
        if ($tt->day && $date->format('l') !== $tt->day) {
            throw ValidationException::withMessages([
                'date' => 'This period runs on ' . $tt->day . ' — ' . $date->format('d M Y') . ' is a ' . $date->format('l') . '.',
            ]);
        }

        if (Substitution::whereDate('date', $date->toDateString())
            ->where('time_table_id', $tt->id)->exists()) {
            throw ValidationException::withMessages([
                'time_table_id' => 'This period already has a substitute for ' . $date->format('d M Y') . '.',
            ]);
        }

        if ($clash = $this->clashMessage($substitute->id, $date, $tt)) {
            throw ValidationException::withMessages([
                'substitute_teacher_id' => $clash,
            ]);
        }

        try {
            $sub = Substitution::create([
                'time_table_id' => $tt->id,
                'date' => $date->toDateString(),
                'absent_teacher_id' => $absentId,
                'substitute_teacher_id' => $substitute->id,
                'created_by' => $user->id,
                'reason' => $data['reason'] ?? null,
            ]);
        } catch (QueryException $e) {
            // The (date, time_table_id) unique key fired on a race.
            throw ValidationException::withMessages([
                'time_table_id' => 'This period already has a substitute for ' . $date->format('d M Y') . '.',
            ]);
        }

        // Round 6 (roles audit): a substitution is a "you must be
        // somewhere" assignment exactly like an invigilation duty —
        // and duties have rung the teacher's bell since the
        // 2026-10-25 batch fix. Without this, the substitute only
        // learns by opening the index. The absent teacher gets no
        // bell: they are the one being covered, not an actor.
        Notify::toUser(
            (int) $substitute->id,
            'substitution_assigned',
            static::bellTitle($tt->subject->name, $date->toDateString()),
            'You cover ' . optional(optional($tt->tt_record)->my_class)->name . ' — ' . $tt->subject->name . ' on ' . $date->format('d M Y') . '.',
            route('substitutions.index')
        );

        return redirect()->route('substitutions.index')
            ->with('flash_success', 'Substitution arranged: ' . $substitute->name . ' covers ' . $tt->subject->name . ' on ' . $date->format('d M Y') . '.');
    }

    /** Delete: admin, or the teacher who created the row. */
    public function destroy(Request $request, Substitution $substitution)
    {
        $user = $request->user();
        if (!$user) {
            abort(403);
        }
        if (!Qs::userIsAdmin() && (int) $substitution->created_by !== (int) $user->id) {
            abort(403, 'You can only delete substitutions you created.');
        }

        // Round 6 (roles audit, PTM-cancel precedent): removing the
        // substitution must also remove the substitute's bell item,
        // or it dangles naming a cover that no longer exists. The
        // title is rebuilt from the row itself, so the match is
        // exact; other bells for the same teacher are untouched.
        $substitution->loadMissing('time_table.subject');
        $oldTitle = static::bellTitle(
            optional($substitution->time_table->subject)->name ?: '',
            (string) $substitution->date
        );
        UserNotification::where('user_id', (int) $substitution->substitute_teacher_id)
            ->where('type', 'substitution_assigned')
            ->where('title', $oldTitle)
            ->delete();

        $substitution->delete();

        return redirect()->route('substitutions.index')
            ->with('flash_success', 'Substitution removed.');
    }

    /**
     * Single definition of the substitution bell title, shared by
     * store (notify) and destroy (cleanup) so the two can never
     * drift apart. $date is a Y-m-d string.
     */
    protected static function bellTitle(string $subjectName, string $date): string
    {
        return 'Substitution assigned: ' . $subjectName . ' on ' . Carbon::parse($date)->format('d M Y');
    }

    /**
     * Period options for the create form. Admins get every period
     * with a subject; teachers get only their own periods, using
     * the same effective-teacher filter period attendance uses.
     */
    protected function periodOptions(User $user)
    {
        $query = TimeTable::with(['subject', 'time_slot', 'tt_record.my_class', 'tt_record.section'])
            ->whereNotNull('subject_id');

        if (!Qs::userIsAdmin()) {
            SectionTeacher::applyTeacherFilter($query, (int) $user->id);
        }

        return $query->get()->sortBy(function ($tt) {
            return ($tt->day ?? '') . ' ' . optional($tt->time_slot)->time_from;
        })->values();
    }

    /**
     * Clash message when the substitute is already busy at an
     * overlapping time on this date, or null when free. Checks (a)
     * the substitute's own timetable cells on the date's weekday
     * and (b) existing substitutions for that substitute on the
     * date — both compared by time-slot window overlap.
     */
    protected function clashMessage(int $substituteId, Carbon $date, TimeTable $cell): ?string
    {
        $window = $this->slotWindow($date, optional($cell->time_slot));
        if (!$window) {
            return null;
        }
        [$start, $end] = $window;

        $ownCells = TimeTable::with(['subject', 'time_slot', 'tt_record'])
            ->where('day', $date->format('l'))
            ->where('id', '!=', $cell->id)
            ->whereNotNull('subject_id')
            ->get()
            ->filter(function ($other) use ($substituteId) {
                return SectionTeacher::teacherIdFor(
                    optional($other->tt_record)->section_id ?? null, $other->subject
                ) === $substituteId;
            });

        foreach ($ownCells as $other) {
            $otherWindow = $this->slotWindow($date, optional($other->time_slot));
            if ($otherWindow && $this->overlaps($start, $end, $otherWindow[0], $otherWindow[1])) {
                return 'This teacher already teaches ' . $other->subject->name . ' at ' . (optional($other->time_slot)->full ?: 'this time') . ' on ' . $date->format('l') . '.';
            }
        }

        $existing = Substitution::with('time_table.time_slot')
            ->whereDate('date', $date->toDateString())
            ->where('substitute_teacher_id', $substituteId)
            ->get();

        foreach ($existing as $sub) {
            $otherWindow = $this->slotWindow($date, optional(optional($sub->time_table)->time_slot));
            if ($otherWindow && $this->overlaps($start, $end, $otherWindow[0], $otherWindow[1])) {
                return 'This teacher is already covering another period at this time on ' . $date->format('d M Y') . '.';
            }
        }

        return null;
    }

    /** A slot's window on a date, parsed from its time strings. */
    protected function slotWindow(Carbon $date, $slot): ?array
    {
        if (!$slot || !$slot->time_from || !$slot->time_to) {
            return null;
        }
        try {
            $start = Carbon::parse($date->toDateString() . ' ' . $slot->time_from);
            $end = Carbon::parse($date->toDateString() . ' ' . $slot->time_to);
        } catch (\Exception $e) {
            return null;
        }
        if ($end->lte($start)) {
            return null;
        }
        return [$start, $end];
    }

    /** Strict overlap: slots that merely touch do not clash. */
    protected function overlaps(Carbon $aStart, Carbon $aEnd, Carbon $bStart, Carbon $bEnd): bool
    {
        return $aStart->lt($bEnd) && $bStart->lt($aEnd);
    }
}
