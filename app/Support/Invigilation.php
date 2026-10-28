<?php

namespace App\Support;

use App\Models\ExamDate;
use App\Models\ExamDuty;
use App\Models\LeaveRequest;
use App\Models\UserNotification;
use App\User;
use Illuminate\Support\Facades\DB;

/**
 * Exam invigilation (Scheduling Engine Slice F): who watches each
 * sitting. Hard rule: a teacher never holds two duties at the same
 * date + start time. Soft rule, surfaced as a warning rather than
 * a block: a teacher should preferably not invigilate their OWN
 * class-subject's paper. Auto-assignment is balanced — fewest
 * existing duties first — and every sitting it cannot fill is
 * reported with its reason, never silently skipped.
 */
class Invigilation
{
    /** Active teachers, the invigilator pool. */
    public static function pool()
    {
        return User::where('user_type', 'teacher')
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', 'active');
            })->orderBy('name')->get();
    }

    /** Is this teacher already on duty at that date + start time? */
    public static function hasClash(int $teacherId, ExamDate $sitting, $ignoreDutyId = null): bool
    {
        return ExamDuty::where('teacher_id', $teacherId)
            ->whereHas('exam_date', function ($q) use ($sitting) {
                $q->where('exam_date', $sitting->exam_date)
                    ->where('start_time', $sitting->start_time);
            })
            ->when($ignoreDutyId, function ($q) use ($ignoreDutyId) {
                $q->where('id', '!=', $ignoreDutyId);
            })->exists();
    }

    /** Does this teacher teach the sitting's class-subject? (warning-level) */
    public static function isOwnSubject(int $teacherId, ExamDate $sitting): bool
    {
        return (int) optional($sitting->subject)->teacher_id === $teacherId;
    }

    /**
     * Batch 2026-10-28 (exam-timetable round 5): approved-leave
     * ranges per teacher, preloaded once per auto-assign run so the
     * per-sitting check is in-memory, not a query per teacher per
     * sitting. Dates are compared as Y-m-d strings, inclusive both
     * ends (LeaveRequest::$days is inclusive, and whereDate bounds
     * stay safe if a row ever stores a datetime string).
     */
    protected static function approvedLeaveMap($sittings): array
    {
        $dates = collect($sittings)->map(function ($s) {
            return substr((string) $s->exam_date, 0, 10);
        })->filter()->values();
        if ($dates->isEmpty()) {
            return [];
        }
        $rows = LeaveRequest::where('status', 'approved')
            ->whereDate('end_date', '>=', $dates->min())
            ->whereDate('start_date', '<=', $dates->max())
            ->get(['user_id', 'start_date', 'end_date']);
        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r->user_id][] = [
                substr((string) $r->start_date, 0, 10),
                substr((string) $r->end_date, 0, 10),
            ];
        }
        return $map;
    }

    /**
     * Is this teacher on APPROVED leave on this date (inclusive)?
     * Pending/rejected leaves never block. Pass a preloaded map
     * from approvedLeaveMap when available; without one, the check
     * queries directly (used by tests and single-sitting callers).
     */
    public static function isOnApprovedLeave(int $teacherId, $date, $leaveMap = null): bool
    {
        $date = substr((string) $date, 0, 10);
        if ($leaveMap !== null) {
            foreach ($leaveMap[$teacherId] ?? [] as [$from, $to]) {
                if ($from <= $date && $date <= $to) {
                    return true;
                }
            }
            return false;
        }
        return LeaveRequest::where('user_id', $teacherId)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->exists();
    }

    /**
     * Batch fix 2026-10-25: duties were invisible to teachers. Every
     * new or changed duty now rings the teacher's bell (the topbar
     * already renders per-user notifications for every role, and
     * opening the bell marks them read) with a link to My Duties.
     */
    /**
     * The body of a duty_assigned bell for a sitting. Shared by
     * notifyDuty and the replacement cleanup in assign() so the
     * delete can never drift from the bell it is meant to clear:
     * the title is the same for every duty, so the body — class,
     * subject, date, time — is the sitting's discriminator.
     */
    public static function dutyBellBody(ExamDate $sitting): string
    {
        $class = optional($sitting->my_class)->name ?: 'a class';
        $subject = optional($sitting->subject)->name ?: 'an exam';
        $time = substr((string) $sitting->start_time, 0, 5);

        return "You are on invigilation duty for {$class} — {$subject} on {$sitting->exam_date} at {$time}.";
    }

    public static function notifyDuty(ExamDate $sitting, int $teacherId): void
    {
        Notify::toUser($teacherId, 'duty_assigned',
            'Invigilation duty assigned',
            self::dutyBellBody($sitting),
            route('exam_duties.my'));
    }

    /**
     * Round 45 (P18 delete-without-cascade): a sitting that stops
     * existing must not leave a duty or a bell behind. The duty
     * row points at a dead exam_date_id (the duties index hides
     * it because it keys by sitting ids, while My Duties loads
     * by teacher_id and renders it as a full dash row) and the
     * bell still names a paper that no longer exists. Delete
     * exactly the sitting's duty rows and, for each holder,
     * exactly the bell for this sitting (the replacement-cleanup
     * precedent in assign() — bells for sittings the teacher
     * still holds are untouched because the body names this
     * sitting). Two match legs: the exact body (when the
     * sitting's class/subject still resolve), plus the slot
     * suffix "on {date} at {HH:MM}." — needed when the subject
     * was already deleted before the sitting is dropped, so the
     * name falls back to 'an exam' and the exact body can never
     * match. The suffix leg stays exact because the clash rule
     * lets a teacher hold at most one duty per (date, time).
     * Callers delete the ExamDate row itself; this only clears
     * its children, inside the caller's transaction.
     */
    public static function clearSitting(ExamDate $sitting): void
    {
        $body = self::dutyBellBody($sitting);
        $suffix = '% on ' . substr((string) $sitting->exam_date, 0, 10)
            . ' at ' . substr((string) $sitting->start_time, 0, 5) . '.';
        $teacherIds = ExamDuty::where('exam_date_id', $sitting->id)->pluck('teacher_id')->all();
        ExamDuty::where('exam_date_id', $sitting->id)->delete();
        foreach ($teacherIds as $teacherId) {
            UserNotification::where('user_id', $teacherId)
                ->where('type', 'duty_assigned')
                ->where(function ($q) use ($body, $suffix) {
                    $q->where('body', $body)->orWhere('body', 'like', $suffix);
                })->delete();
        }
    }

    /**
     * Assign/replace a duty with the clash rule enforced.
     * Returns [ok, message].
     */
    public static function assign(ExamDate $sitting, int $teacherId, $actorId): array
    {
        if (self::hasClash($teacherId, $sitting)) {
            // Re-assigning the SAME teacher to the SAME sitting is
            // not a clash with themselves — detect the existing row.
            $existing = ExamDuty::where('exam_date_id', $sitting->id)->first();
            if (!$existing || (int) $existing->teacher_id !== $teacherId) {
                return [false, 'That teacher already has a duty at this date and time.'];
            }
        }

        $existing = ExamDuty::where('exam_date_id', $sitting->id)->first();
        $oldTeacherId = $existing ? (int) $existing->teacher_id : null;
        ExamDuty::updateOrCreate(
            ['exam_date_id' => $sitting->id],
            ['teacher_id' => $teacherId, 'assigned_by' => $actorId]
        );
        // A no-op re-save to the same teacher rings no bell; a new
        // duty or a changed teacher does.
        if (!$existing || $oldTeacherId !== $teacherId) {
            if ($existing && $oldTeacherId !== $teacherId) {
                /* Round 8 (2026-10-28, roles round-6 finding): a
                   replaced duty left the OLD teacher's bell behind,
                   naming a sitting they no longer hold (same orphan
                   class as the PTM-cancel bell). Clear exactly that
                   sitting's bell for the old teacher; bells for
                   sittings they still hold are untouched because
                   the body names this sitting's class/subject/date/
                   time. The new teacher's bell is rung below. */
                UserNotification::where('user_id', $oldTeacherId)
                    ->where('type', 'duty_assigned')
                    ->where('body', self::dutyBellBody($sitting->fresh(['my_class', 'subject'])))
                    ->delete();
            }
            self::notifyDuty($sitting->fresh(['my_class', 'subject']), $teacherId);
        }

        return [true, 'Invigilator saved.'];
    }

    /**
     * Fill every unassigned sitting of an exam, balanced by duty
     * count. Returns ['assigned' => n, 'unfilled' => [reasons]].
     */
    public static function autoAssign(int $examId, $actorId): array
    {
        $sittings = ExamDate::where('exam_id', $examId)
            ->orderBy('exam_date')->orderBy('start_time')->orderBy('my_class_id')->get();
        $assignedIds = ExamDuty::whereIn('exam_date_id', $sittings->pluck('id'))
            ->pluck('exam_date_id')->all();
        $pool = self::pool();
        $counts = ExamDuty::whereIn('exam_date_id',
            ExamDate::where('exam_id', $examId)->pluck('id'))
            ->select('teacher_id', DB::raw('COUNT(*) as c'))
            ->groupBy('teacher_id')->pluck('c', 'teacher_id')->all();

        $assigned = 0;
        $unfilled = [];
        $skippedPast = 0;
        $filledDespiteLeave = 0;
        $today = \Illuminate\Support\Carbon::today()->toDateString();
        // Batch 2026-10-28 (exam-timetable round 5): a teacher on
        // approved leave covering a sitting date is never
        // auto-assigned that duty, and never belled about it.
        // Manual assignment stays available for deliberate
        // record-keeping (same rule as past sittings).
        $leaveMap = self::approvedLeaveMap($sittings);
        foreach ($sittings as $sitting) {
            if (in_array($sitting->id, $assignedIds, true)) {
                continue;
            }
            // Batch 2026-10-28 (exam-timetable round 2): a sitting
            // whose paper already happened gets NO new duty — auto
            // assignment used to fill past sittings and bell their
            // "invigilators" about a paper that was already over.
            // Manual assignment stays available for deliberate
            // record-keeping of who actually invigilated.
            if (substr((string) $sitting->exam_date, 0, 10) < $today) {
                $skippedPast++;
                continue;
            }
            $sittingDate = substr((string) $sitting->exam_date, 0, 10);
            $leaveExcluded = $pool->filter(function ($t) use ($sittingDate, $leaveMap) {
                return self::isOnApprovedLeave($t->id, $sittingDate, $leaveMap);
            });
            $candidates = $pool->filter(function ($t) use ($sitting, $sittingDate, $leaveMap) {
                return !self::hasClash($t->id, $sitting)
                    && !self::isOnApprovedLeave($t->id, $sittingDate, $leaveMap);
            })->sortBy(function ($t) use ($counts) {
                return $counts[$t->id] ?? 0;
            })->values();

            if ($candidates->isEmpty()) {
                $reason = $sitting->exam_date . ' ' . substr((string) $sitting->start_time, 0, 5)
                    . ' — every teacher already has a duty at this time (or no teachers exist).';
                if ($leaveExcluded->isNotEmpty()) {
                    $reason .= ' Teachers on approved leave were not assigned.';
                }
                $unfilled[] = $reason;
                continue;
            }
            // Prefer a non-own-subject teacher among the least loaded.
            $best = $candidates->first(function ($t) use ($sitting) {
                return !self::isOwnSubject($t->id, $sitting);
            }) ?: $candidates->first();

            ExamDuty::create([
                'exam_date_id' => $sitting->id,
                'teacher_id' => $best->id,
                'assigned_by' => $actorId,
            ]);
            // Batch 2026-10-28 (exam-timetable round 6): a sitting
            // filled while other pool teachers were leave-excluded
            // for its date counts here, so the flash can say leave
            // shaped the pool — silence only when the count is 0.
            if ($leaveExcluded->isNotEmpty()) {
                $filledDespiteLeave++;
            }
            self::notifyDuty($sitting->load(['my_class', 'subject']), $best->id);
            $counts[$best->id] = ($counts[$best->id] ?? 0) + 1;
            $assigned++;
        }

        return ['assigned' => $assigned, 'unfilled' => $unfilled, 'skipped_past' => $skippedPast,
            'filled_despite_leave' => $filledDespiteLeave];
    }
}
