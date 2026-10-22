<?php

namespace App\Support;

use App\Models\StudentRecord;
use App\Models\UserNotification;
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
        static::toUser($studentUserId, $type, $title, $body, $link);

        $parentIds = StudentRecord::where('user_id', $studentUserId)
            ->whereNotNull('my_parent_id')
            ->pluck('my_parent_id')
            ->unique();

        foreach ($parentIds as $parentId) {
            static::toUser($parentId, $type, $title, $body, $link);
        }
    }

    public static function unreadCount($userId): int
    {
        if (!$userId || !Schema::hasTable('user_notifications')) {
            return 0;
        }

        return UserNotification::where('user_id', $userId)->unread()->count();
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
