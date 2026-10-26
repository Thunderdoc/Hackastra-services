<?php

namespace App\Support;

use App\Models\ExamDate;
use App\Models\ExamDuty;
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
     * Batch fix 2026-10-25: duties were invisible to teachers. Every
     * new or changed duty now rings the teacher's bell (the topbar
     * already renders per-user notifications for every role, and
     * opening the bell marks them read) with a link to My Duties.
     */
    public static function notifyDuty(ExamDate $sitting, int $teacherId): void
    {
        $class = optional($sitting->my_class)->name ?: 'a class';
        $subject = optional($sitting->subject)->name ?: 'an exam';
        $time = substr((string) $sitting->start_time, 0, 5);
        Notify::toUser($teacherId, 'duty_assigned',
            'Invigilation duty assigned',
            "You are on invigilation duty for {$class} — {$subject} on {$sitting->exam_date} at {$time}.",
            route('exam_duties.my'));
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
        ExamDuty::updateOrCreate(
            ['exam_date_id' => $sitting->id],
            ['teacher_id' => $teacherId, 'assigned_by' => $actorId]
        );
        // A no-op re-save to the same teacher rings no bell; a new
        // duty or a changed teacher does.
        if (!$existing || (int) $existing->teacher_id !== $teacherId) {
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
        foreach ($sittings as $sitting) {
            if (in_array($sitting->id, $assignedIds, true)) {
                continue;
            }
            $candidates = $pool->filter(function ($t) use ($sitting) {
                return !self::hasClash($t->id, $sitting);
            })->sortBy(function ($t) use ($counts) {
                return $counts[$t->id] ?? 0;
            })->values();

            if ($candidates->isEmpty()) {
                $unfilled[] = $sitting->exam_date . ' ' . substr((string) $sitting->start_time, 0, 5)
                    . ' — every teacher already has a duty at this time (or no teachers exist).';
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
            self::notifyDuty($sitting->load(['my_class', 'subject']), $best->id);
            $counts[$best->id] = ($counts[$best->id] ?? 0) + 1;
            $assigned++;
        }

        return ['assigned' => $assigned, 'unfilled' => $unfilled];
    }
}
