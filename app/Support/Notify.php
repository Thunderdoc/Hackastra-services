<?php

namespace App\Support;

use App\Helpers\Qs;
use App\Models\StudentRecord;
use App\Models\UserNotification;
use App\Models\UserPreference;
use Illuminate\Support\Facades\Schema;

/**
 * Workstream CZ (LMS) — per-user bell notifications.
 *
 * The smallest store the existing topbar bell can read: rows in
 * user_notifications, rendered by partials/top_menu for the roles
 * that receive them (students + parents). Writers (grading, the
 * internal-marks push) call the helpers here; nothing else in the
 * app needs to know the table exists. Every write is guarded by
 * Schema::hasTable so a partially-migrated deploy degrades to
 * "no notifications" instead of an error — the same habit the
 * topbar and home controllers already use.
 */
class Notify
{
    public static function toUser($userId, string $type, string $title, $body = null, $link = null): void
    {
        if (!$userId || !Schema::hasTable('user_notifications')) {
            return;
        }

        UserNotification::create([
            'user_id' => (int) $userId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'link' => $link,
        ]);
    }

    /**
     * Notify a student (by users.id) and every parent linked to any
     * of the student's records — the same my_parent_id linkage the
     * parent portal itself is scoped by, so a parent only ever hears
     * about their own children.
     */
    public static function toStudentAndParents($studentUserId, string $type, string $title, $body = null, $link = null): void
    {
        // My Account v3: both writers through here are LMS events
        // (assignment graded, internal marks pushed), so each
        // recipient's own 'notify_lms' preference gates their copy.
        // Default is on; a missing preferences table degrades to on.
        if (static::lmsAlertsEnabled($studentUserId)) {
            static::toUser($studentUserId, $type, $title, $body, $link);
        }

        $parentIds = StudentRecord::where('user_id', $studentUserId)
            ->whereNotNull('my_parent_id')
            ->pluck('my_parent_id')
            ->unique();

        foreach ($parentIds as $parentId) {
            if (static::lmsAlertsEnabled($parentId)) {
                static::toUser($parentId, $type, $title, $body, $link);
            }
        }
    }

    protected static function lmsAlertsEnabled($userId): bool
    {
        if (!$userId || !Schema::hasTable('user_preferences')) {
            return true;
        }

        return UserPreference::enabled((int) $userId, 'notify_lms');
    }

    public static function unreadCount($userId): int
    {
        if (!$userId || !Schema::hasTable('user_notifications')) {
            return 0;
        }

        return UserNotification::where('user_id', $userId)->unread()->count();
    }

    /**
     * Owner build 2026-10-25 — result-published broadcast: when an
     * exam's results are published, every active student of the
     * exam's session (and each linked parent) gets one bell
     * notification pointing at their own marksheet. Unlike the LMS
     * helpers above, results are essential school communication, so
     * they are NOT gated by the notify_lms preference — the owner
     * ordered "saab ko jana chahes". Class-scoped exams (my_class_id
     * set) notify only that class. Re-publishing refreshes: unread
     * copies of the same broadcast are removed first, so nobody
     * gets the same announcement twice. Returns rows inserted.
     */
    public static function resultsPublished($exam): int
    {
        if (!$exam || !Schema::hasTable('user_notifications')) {
            return 0;
        }

        $students = StudentRecord::where('session', $exam->year)
            ->where('grad', 0)
            ->get(['user_id', 'my_parent_id', 'my_class_id']);

        if (Schema::hasColumn('exams', 'my_class_id') && !empty($exam->my_class_id)) {
            $students = $students->where('my_class_id', (int) $exam->my_class_id);
        }

        $title = 'Results published: ' . $exam->name;
        $body = $exam->name . ' (' . $exam->year . ') results are out. View the marksheet.';
        $now = now();
        $rows = [];
        foreach ($students as $st) {
            if (!$st->user_id) {
                continue;
            }
            $link = route('marks.print', [Qs::hash($st->user_id), $exam->id, $exam->year]);
            $rows[] = [
                'user_id' => (int) $st->user_id, 'type' => 'result_published',
                'title' => $title, 'body' => $body, 'link' => $link,
                'read_at' => null, 'created_at' => $now, 'updated_at' => $now,
            ];
            if ($st->my_parent_id) {
                $rows[] = [
                    'user_id' => (int) $st->my_parent_id, 'type' => 'result_published',
                    'title' => $title, 'body' => $body, 'link' => $link,
                    'read_at' => null, 'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }

        if (empty($rows)) {
            return 0;
        }

        // Refresh, don't duplicate: drop unread copies of this exact
        // broadcast (links are per-student, so only their rows go).
        UserNotification::where('type', 'result_published')->whereNull('read_at')
            ->whereIn('link', array_unique(array_column($rows, 'link')))->delete();

        foreach (array_chunk($rows, 500) as $chunk) {
            UserNotification::insert($chunk);
        }

        return count($rows);
    }

    /**
     * Wave 5 — owner order 2026-10-25: fee reminders. One bell
     * notification per defaulter (current session, same
     * FeeDefaulter definition as the Defaulters page, so the
     * reminded set can never drift from the listed set) to the
     * student AND each linked parent. Like resultsPublished, fee
     * reminders are essential school communication, so they are NOT
     * gated by notify_lms. Re-sending refreshes: unread copies are
     * removed first, so nobody gets the same reminder twice. The
     * optional class + filters narrow the set exactly like the
     * Defaulters page filters. Returns defaulters + notifications.
     */
    public static function feeReminders($year, $selected_class = null, array $filters = []): array
    {
        if (!$year || !Schema::hasTable('user_notifications')) {
            return ['defaulters' => 0, 'notifications' => 0];
        }

        $rows = \App\Support\FeeDefaulter::rows($year, $selected_class, $filters);
        $now = now();
        $notifs = [];
        $defaulters = 0;
        foreach ($rows as $row) {
            $st = $row['student'];
            if (!$st || !$st->user_id) {
                continue;
            }
            $defaulters++;
            $due = '₹' . number_format((int) $row['total_due']);
            $title = 'Fee reminder: ' . $due . ' outstanding';
            $body = $due . ' is outstanding for session ' . $year . '. Please clear it at the school office.';
            $notifs[] = [
                'user_id' => (int) $st->user_id, 'type' => 'fee_reminder',
                'title' => $title, 'body' => $body, 'link' => route('student.fees'),
                'read_at' => null, 'created_at' => $now, 'updated_at' => $now,
            ];
            if ($st->my_parent_id) {
                $notifs[] = [
                    'user_id' => (int) $st->my_parent_id, 'type' => 'fee_reminder',
                    'title' => $title, 'body' => $body, 'link' => route('my_fees'),
                    'read_at' => null, 'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }

        if (empty($notifs)) {
            return ['defaulters' => 0, 'notifications' => 0];
        }

        UserNotification::where('type', 'fee_reminder')->whereNull('read_at')
            ->whereIn('user_id', array_unique(array_column($notifs, 'user_id')))->delete();

        foreach (array_chunk($notifs, 500) as $chunk) {
            UserNotification::insert($chunk);
        }

        return ['defaulters' => $defaulters, 'notifications' => count($notifs)];
    }

    public static function latestFor($userId, int $take = 5)
    {
        if (!$userId || !Schema::hasTable('user_notifications')) {
            return collect();
        }

        return UserNotification::where('user_id', $userId)
            ->latest()->take($take)->get();
    }

    public static function markAllRead($userId): void
    {
        if (!$userId || !Schema::hasTable('user_notifications')) {
            return;
        }

        UserNotification::where('user_id', $userId)->unread()
            ->update(['read_at' => now()]);
    }

    /** 8.50 -> "8.5", 8.00 -> "8", 8.25 -> "8.25" — marks as a teacher typed them. */
    public static function fmtMarks($value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2), '0'), '.');
    }
}
