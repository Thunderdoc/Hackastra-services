<?php

namespace App\Support;

use App\Helpers\Qs;
use App\Models\Assignment;
use App\Models\Circular;
use App\Models\Exam;
use App\Models\StudentRecord;
use App\Models\UserPreference;
use App\Models\WhatsAppMessage;
use App\Support\WhatsApp\MetaCloudProvider;
use App\Support\WhatsApp\MockWhatsAppProvider;
use App\Support\WhatsApp\WhatsAppGateway;
use App\Support\WhatsApp\WhatsAppPhone;
use App\Support\WhatsApp\WhatsAppProvider;
use App\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * F1 (Round 4, design §3.3) — the WhatsApp engine: the only class
 * triggers call. Queue-event API + the outbox drainer.
 *
 * Laws (design §8/§11):
 *  - WhatsApp is ADDITIONAL: the bell is written first /
 *    independently by its existing writers; nothing here can
 *    change bell behaviour, and no throwable ever escapes a
 *    public method (Audit pattern).
 *  - Recipients: the linked parent ONLY (StudentRecord.my_parent_id
 *    → users, user_type 'parent' defensive check) → phone else
 *    phone2 → WhatsAppPhone::normalize. The student is never a
 *    WhatsApp recipient.
 *  - Dedupe lives in the DB (dedupe_key UNIQUE, insertOrIgnore).
 *  - notifications_muted never suppresses WhatsApp (bell
 *    presentation only); opt-out is notify_whatsapp + the
 *    per-event preference mapping, enforced HERE only.
 */
class ParentWhatsApp
{
    public const EVENT_ABSENT = 'attendance_absent';
    public const EVENT_FEE = 'fee_reminder';
    public const EVENT_RESULT = 'result_published';
    public const EVENT_CIRCULAR = 'circular_published';
    public const EVENT_ASSIGNMENT = 'assignment_created';

    /** Exact Meta template names (design §4.2). */
    public const TEMPLATES = [
        self::EVENT_ABSENT => 'hackstra_attendance_absent',
        self::EVENT_FEE => 'hackstra_fee_reminder',
        self::EVENT_RESULT => 'hackstra_result_published',
        self::EVENT_CIRCULAR => 'hackstra_circular_published',
        self::EVENT_ASSIGNMENT => 'hackstra_assignment_created',
    ];

    /** Per-event preference keys (design §8.2) — WhatsApp layer only. */
    public const EVENT_PREFS = [
        self::EVENT_ABSENT => 'notify_attendance',
        self::EVENT_RESULT => 'notify_exams',
        self::EVENT_CIRCULAR => 'notify_announcements',
        self::EVENT_ASSIGNMENT => 'notify_lms',
        // Fee reminders: essential channel — gated only by notify_whatsapp.
        self::EVENT_FEE => null,
    ];

    public const PER_PARENT_DAILY_CAP = 5;
    public const FEE_COOLDOWN_DAYS = 7;

    /* ---------------------------------------------------------------
     | Provider resolution
     | -------------------------------------------------------------- */

    /**
     * The active transport. A container binding for the interface
     * wins (tests bind fakes / a throwing stub here); otherwise
     * mock → MockWhatsAppProvider, live → MetaCloudProvider.
     */
    public static function provider(): WhatsAppProvider
    {
        try {
            if (app()->bound(WhatsAppProvider::class)) {
                $p = app(WhatsAppProvider::class);
                if ($p instanceof WhatsAppProvider) {
                    return $p;
                }
            }
        } catch (\Throwable $e) {
            // Fall through to the gateway-chosen transport.
        }

        return WhatsAppGateway::mock() ? new MockWhatsAppProvider() : new MetaCloudProvider();
    }

    /* ---------------------------------------------------------------
     | Queue-event API (triggers)
     | -------------------------------------------------------------- */

    /**
     * One absence message per child per DAY (dedupe absent:{sr}:{Y-m-d}).
     * 'late' never reaches this method — the trigger filters it.
     */
    public static function absent(StudentRecord $sr, $session = null, $date = null, $subjectName = null, $triggeredBy = null): void
    {
        try {
            if (!Schema::hasTable('whatsapp_messages') || !$sr) {
                return;
            }
            $dateStr = $date ? Carbon::parse($date)->toDateString() : Carbon::today()->toDateString();
            $studentName = self::studentName($sr);
            $school = self::schoolName();
            $dateLabel = Carbon::parse($dateStr)->format('d M Y');
            $subject = $subjectName ?: 'class';
            $params = [$studentName, $dateLabel, (string) $subject, $school];
            $preview = 'Namaste. ' . $studentName . ' was marked absent at ' . $school . ' on ' . $dateLabel . ' (' . $subject . '). If your child is unwell or this is expected, please inform the school office.';

            $inserted = self::queueForRecord($sr, self::EVENT_ABSENT, $params, $preview,
                'absent:' . $sr->id . ':' . $dateStr,
                is_object($session) ? get_class($session) : null,
                (is_object($session) && isset($session->id)) ? (int) $session->id : null,
                $triggeredBy ? (int) $triggeredBy : (auth()->id() ?: null));

            if ($inserted) {
                self::drain((int) config('whatsapp.inline_drain_limit', 25));
            }
        } catch (\Throwable $e) {
            WhatsAppGateway::logEvent('absent queue failed', ['student_record_id' => $sr ? $sr->id : null]);
        }
    }

    /** Fee reminder for one defaulter record (7-day cooldown, design §11.3). */
    public static function feeReminder(StudentRecord $sr, int $amountRupees, string $sessionYear, $triggeredBy = null): void
    {
        try {
            if (!Schema::hasTable('whatsapp_messages') || !$sr) {
                return;
            }
            if (!WhatsAppGateway::masterEnabled()) {
                return;
            }
            $studentName = self::studentName($sr);
            $school = self::schoolName();
            $amount = number_format($amountRupees);
            $params = [$studentName, $amount, (string) $sessionYear, $school];
            $preview = 'Fee reminder from ' . $school . ': Rs. ' . $amount . ' is outstanding for ' . $studentName . ' for session ' . $sessionYear . '. Please clear it at the school office. If you have already paid, please ignore this message.';
            $canonical = 'fee:' . $sr->id . ':' . $sessionYear;

            if (!WhatsAppGateway::eventEnabled(self::EVENT_FEE)) {
                self::insertRow(self::baseRow($sr, self::EVENT_FEE, $params, $preview, $canonical, null, null, $triggeredBy) + ['status' => 'skipped_disabled']);
                return;
            }

            $resolved = self::resolveRecipient($sr, self::EVENT_FEE);
            if ($resolved['skip'] === 'grad') {
                return;
            }
            if ($resolved['skip'] !== null) {
                $row = self::baseRow($sr, self::EVENT_FEE, $params, $preview, $canonical, null, null, $triggeredBy, $resolved);
                $row['status'] = $resolved['skip'];
                self::insertRow($row);
                return;
            }

            // Cooldown: a sent|mocked fee message for this child in
            // the last 7 days suppresses a fresh send — the repeat
            // lands as its own suppressed_cooldown audit row (the
            // canonical dedupe key stays with the real send).
            $recentSent = WhatsAppMessage::where('event', self::EVENT_FEE)
                ->where('student_record_id', $sr->id)
                ->whereIn('status', ['sent', 'mocked'])
                ->where('created_at', '>=', now()->subDays(self::FEE_COOLDOWN_DAYS))
                ->exists();
            if ($recentSent) {
                $row = self::baseRow($sr, self::EVENT_FEE, $params, $preview,
                    $canonical . ':sup:' . Carbon::today()->toDateString(), null, null, $triggeredBy, $resolved);
                $row['status'] = 'suppressed_cooldown';
                self::insertRow($row);
                return;
            }

            // Per-parent daily cap: a fee nudge beyond the cap drops
            // to suppressed_cooldown (a stale nudge tomorrow is noise).
            if (self::actionableTodayForParent((int) $resolved['parent']->id) >= self::PER_PARENT_DAILY_CAP) {
                $row = self::baseRow($sr, self::EVENT_FEE, $params, $preview,
                    $canonical . ':sup:' . Carbon::today()->toDateString(), null, null, $triggeredBy, $resolved);
                $row['status'] = 'suppressed_cooldown';
                self::insertRow($row);
                return;
            }

            $row = self::baseRow($sr, self::EVENT_FEE, $params, $preview, $canonical, null, null, $triggeredBy, $resolved);
            $row['status'] = 'queued';
            $inserted = self::insertRow($row);
            if (!$inserted) {
                // Canonical key already exists (an earlier reminder
                // action) — record this repeat as suppressed.
                $sup = self::baseRow($sr, self::EVENT_FEE, $params, $preview,
                    $canonical . ':sup:' . Carbon::today()->toDateString(), null, null, $triggeredBy, $resolved);
                $sup['status'] = 'suppressed_cooldown';
                self::insertRow($sup);
                return;
            }
            self::drain((int) config('whatsapp.inline_drain_limit', 25));
        } catch (\Throwable $e) {
            WhatsAppGateway::logEvent('fee queue failed', ['student_record_id' => $sr ? $sr->id : null]);
        }
    }

    /**
     * Result fan-out: one row per (exam, student_record) — the
     * exact population Notify::resultsPublished bells (session +
     * optional class scope, grad=0). Re-publish never re-sends:
     * the dedupe key is once-ever per exam+child.
     */
    public static function resultPublished($exam): int
    {
        try {
            if (!Schema::hasTable('whatsapp_messages') || !$exam) {
                return 0;
            }
            if (!WhatsAppGateway::masterEnabled()) {
                return 0;
            }

            $students = StudentRecord::with('user')
                ->where('session', $exam->year)
                ->where('grad', 0)
                ->get(['id', 'user_id', 'my_parent_id', 'my_class_id']);

            if (Schema::hasColumn('exams', 'my_class_id') && !empty($exam->my_class_id)) {
                $students = $students->where('my_class_id', (int) $exam->my_class_id);
            }

            $school = self::schoolName();
            $params = [(string) $exam->name, (string) $exam->year, $school];
            $preview = 'Results published: ' . $exam->name . ' (' . $exam->year . ') results are now available. Please check the marksheet on the ' . $school . ' portal or contact the school office.';
            $triggeredBy = auth()->id() ?: null;

            $context = self::fanoutContext($students);
            $rows = [];
            foreach ($students as $st) {
                $rows[] = self::buildFanoutRow($st, self::EVENT_RESULT, $params, $preview,
                    'result:' . $exam->id . ':' . $st->id,
                    Exam::class, (int) $exam->id, $triggeredBy, $context);
            }
            $inserted = self::bulkInsert($rows);
            if ($inserted > 0) {
                self::drain((int) config('whatsapp.inline_drain_limit', 25));
            }

            return $inserted;
        } catch (\Throwable $e) {
            WhatsAppGateway::logEvent('result fan-out failed', ['exam_id' => $exam ? $exam->id : null]);

            return 0;
        }
    }

    /**
     * Circular fan-out: one row per PARENT (dedupe circular:{id}:{parent})
     * across active, current-session students — circulars have no
     * class scope (design §6/4a).
     */
    public static function circularPublished($circular): int
    {
        try {
            if (!Schema::hasTable('whatsapp_messages') || !$circular) {
                return 0;
            }
            if (!WhatsAppGateway::masterEnabled()) {
                return 0;
            }

            $session = Qs::getCurrentSession();
            $records = StudentRecord::with('user')
                ->where('grad', 0)
                ->when($session, function ($q) use ($session) {
                    $q->where('session', $session);
                })
                ->whereNotNull('my_parent_id')
                ->get(['id', 'user_id', 'my_parent_id', 'my_class_id']);

            $byParent = [];
            foreach ($records as $rec) {
                if (!isset($byParent[(int) $rec->my_parent_id])) {
                    $byParent[(int) $rec->my_parent_id] = $rec;
                }
            }

            $school = self::schoolName();
            $title = trim(str_replace(['"', "\n", "\r", "\t"], ' ', (string) $circular->title));
            if (strlen($title) > 80) {
                $title = substr($title, 0, 80);
            }
            $params = [$title, $school];
            $preview = 'New circular from ' . $school . ': "' . $title . '". Please read it on the school portal or notice board.';
            $triggeredBy = auth()->id() ?: null;

            $context = self::fanoutContext($byParent);
            $rows = [];
            foreach ($byParent as $parentId => $rep) {
                $rows[] = self::buildFanoutRow($rep, self::EVENT_CIRCULAR, $params, $preview,
                    'circular:' . $circular->id . ':' . $parentId,
                    Circular::class, (int) $circular->id, $triggeredBy, $context);
            }
            $inserted = self::bulkInsert($rows);
            if ($inserted > 0) {
                self::drain((int) config('whatsapp.inline_drain_limit', 25));
            }

            return $inserted;
        } catch (\Throwable $e) {
            WhatsAppGateway::logEvent('circular fan-out failed', ['circular_id' => $circular ? $circular->id : null]);

            return 0;
        }
    }

    /**
     * Assignment fan-out: one row per PARENT (dedupe
     * assignment:{id}:{parent}), naming the first child; roster =
     * the assignment's class (+ section when set), grad=0,
     * current session.
     */
    public static function assignmentCreated($assignment): int
    {
        try {
            if (!Schema::hasTable('whatsapp_messages') || !$assignment) {
                return 0;
            }
            if (!WhatsAppGateway::masterEnabled()) {
                return 0;
            }

            $session = Qs::getCurrentSession();
            $records = StudentRecord::with('user')
                ->where('my_class_id', $assignment->my_class_id)
                ->when($assignment->section_id, function ($q) use ($assignment) {
                    $q->where('section_id', $assignment->section_id);
                })
                ->where('grad', 0)
                ->when($session, function ($q) use ($session) {
                    $q->where('session', $session);
                })
                ->whereNotNull('my_parent_id')
                ->get(['id', 'user_id', 'my_parent_id', 'my_class_id', 'section_id']);

            $byParent = [];
            foreach ($records as $rec) {
                if (!isset($byParent[(int) $rec->my_parent_id])) {
                    $byParent[(int) $rec->my_parent_id] = $rec;
                }
            }

            $context = self::fanoutContext($byParent);
            $school = self::schoolName();
            $title = trim(str_replace(["\n", "\r", "\t"], ' ', (string) $assignment->title));
            if (strlen($title) > 80) {
                $title = substr($title, 0, 80);
            }
            $subjectName = optional($assignment->subject)->name ?: '—';
            $due = $assignment->due_at ? Carbon::parse($assignment->due_at)->format('d M Y') : 'no due date';
            $triggeredBy = auth()->id() ?: null;

            $rows = [];
            foreach ($byParent as $parentId => $rep) {
                $childName = self::studentName($rep);
                $params = [$childName, $title, (string) $subjectName, $due, $school];
                $preview = 'New homework for ' . $childName . ': ' . $title . ' (' . $subjectName . '), due ' . $due . '. — ' . $school;
                $rows[] = self::buildFanoutRow($rep, self::EVENT_ASSIGNMENT, $params, $preview,
                    'assignment:' . $assignment->id . ':' . $parentId,
                    Assignment::class, (int) $assignment->id, $triggeredBy, $context);
            }
            $inserted = self::bulkInsert($rows);
            if ($inserted > 0) {
                self::drain((int) config('whatsapp.inline_drain_limit', 25));
            }

            return $inserted;
        } catch (\Throwable $e) {
            WhatsAppGateway::logEvent('assignment fan-out failed', ['assignment_id' => $assignment ? $assignment->id : null]);

            return 0;
        }
    }

    /* ---------------------------------------------------------------
     | Drain (sender)
     | -------------------------------------------------------------- */

    /**
     * Send queued rows (attempts < 3, oldest first). Per-row
     * isolated; stops the pass after 2 consecutive transport
     * errors and at the daily cap; rows beyond a parent's daily
     * cap stay queued for the next day. Never throws.
     *
     * @return array{sent:int, mocked:int, failed:int, remaining:int}
     */
    public static function drain(int $limit = 25, $onlyId = null): array
    {
        $stats = ['sent' => 0, 'mocked' => 0, 'failed' => 0, 'remaining' => 0];
        try {
            if (!Schema::hasTable('whatsapp_messages')) {
                return $stats;
            }
            $query = WhatsAppMessage::where('status', 'queued')
                ->where('attempts', '<', 3)
                ->orderBy('id');
            if ($onlyId !== null) {
                $query->where('id', (int) $onlyId);
            } else {
                $query->limit(max(1, $limit));
            }
            $rows = $query->get();
            if ($rows->isEmpty()) {
                return $stats;
            }

            $provider = self::provider();
            $isMock = $provider instanceof MockWhatsAppProvider;
            $cap = (int) config('whatsapp.daily_cap', 1000);
            $sentToday = self::sentMockedToday();
            $consecutiveTransport = 0;

            foreach ($rows as $row) {
                if ($sentToday >= $cap) {
                    break;
                }
                // Per-parent per-day cap: leave the row queued —
                // it goes out on a later day's drain.
                if ($row->parent_user_id
                    && self::sentMockedTodayForParent((int) $row->parent_user_id) >= self::PER_PARENT_DAILY_CAP) {
                    continue;
                }

                $row->attempts = (int) $row->attempts + 1;
                try {
                    $res = $provider->sendTemplate(
                        (string) $row->recipient_phone,
                        (string) $row->template_name,
                        (string) $row->template_language,
                        is_array($row->params) ? $row->params : []
                    );
                } catch (\Throwable $e) {
                    $res = ['ok' => false, 'provider_message_id' => null,
                        'error_code' => 'transport',
                        'error_message' => substr($e->getMessage(), 0, 500),
                        'transport' => true];
                }

                if (!empty($res['ok'])) {
                    $row->status = $isMock ? 'mocked' : 'sent';
                    $row->provider_message_id = $res['provider_message_id'] ?? null;
                    $row->error_code = null;
                    $row->error_message = null;
                    $row->sent_at = now();
                    $row->save();
                    $stats[$isMock ? 'mocked' : 'sent']++;
                    $sentToday++;
                    $consecutiveTransport = 0;
                } else {
                    $row->status = 'failed';
                    $row->error_code = isset($res['error_code']) ? substr((string) $res['error_code'], 0, 30) : null;
                    $row->error_message = isset($res['error_message']) ? substr((string) $res['error_message'], 0, 500) : null;
                    $row->save();
                    $stats['failed']++;
                    if (!empty($res['transport'])) {
                        $consecutiveTransport++;
                        if ($consecutiveTransport >= 2) {
                            break;
                        }
                    } else {
                        $consecutiveTransport = 0;
                    }
                }
            }

            $stats['remaining'] = WhatsAppMessage::where('status', 'queued')->where('attempts', '<', 3)->count();
        } catch (\Throwable $e) {
            WhatsAppGateway::logEvent('drain failed', ['error' => substr($e->getMessage(), 0, 200)]);
        }

        return $stats;
    }

    /* ---------------------------------------------------------------
     | Internals
     | -------------------------------------------------------------- */

    protected static function schoolName(): string
    {
        $name = Qs::getSetting('system_name');

        return $name && trim($name) !== '' ? trim($name) : 'School';
    }

    protected static function studentName(StudentRecord $sr): string
    {
        $name = optional($sr->user)->name;
        if (!$name && $sr->user_id) {
            $name = optional(User::find($sr->user_id))->name;
        }

        return $name ?: 'Your child';
    }

    /**
     * Resolve the recipient for a record (design §5/§8):
     * @return array{parent:?User, phone:?string, skip:?string}
     *   skip is a whatsapp_messages status (skipped_*) or 'grad'.
     */
    public static function resolveRecipient(StudentRecord $sr, string $event): array
    {
        if ((int) $sr->grad === 1) {
            return ['parent' => null, 'phone' => null, 'skip' => 'grad'];
        }
        if (!$sr->my_parent_id) {
            return ['parent' => null, 'phone' => null, 'skip' => 'skipped_no_phone'];
        }
        $parent = User::find($sr->my_parent_id);
        if (!$parent || $parent->user_type !== 'parent') {
            return ['parent' => $parent, 'phone' => null, 'skip' => 'skipped_no_phone'];
        }
        $phone = WhatsAppPhone::normalize($parent->phone ?: null)
            ?: WhatsAppPhone::normalize($parent->phone2 ?: null);
        if ($phone === null) {
            return ['parent' => $parent, 'phone' => null, 'skip' => 'skipped_no_phone'];
        }
        // Opt-out (design §8.2): the master WhatsApp switch, then the
        // per-event key. notifications_muted is checked NOWHERE here.
        try {
            if (Schema::hasTable('user_preferences')) {
                if (!UserPreference::enabled((int) $parent->id, 'notify_whatsapp')) {
                    return ['parent' => $parent, 'phone' => $phone, 'skip' => 'skipped_opt_out'];
                }
                $prefKey = self::EVENT_PREFS[$event] ?? null;
                if ($prefKey && !UserPreference::enabled((int) $parent->id, $prefKey)) {
                    return ['parent' => $parent, 'phone' => $phone, 'skip' => 'skipped_opt_out'];
                }
            }
        } catch (\Throwable $e) {
            // A broken preference read must not block messaging.
        }

        return ['parent' => $parent, 'phone' => $phone, 'skip' => null];
    }

    /** Queue (or audit-skip) one message for one student record. Returns true when a queued row was inserted. */
    protected static function queueForRecord(StudentRecord $sr, string $event, array $params, string $preview, string $dedupeKey, $relatedType = null, $relatedId = null, $triggeredBy = null): bool
    {
        if (!WhatsAppGateway::masterEnabled()) {
            return false;
        }
        if (!WhatsAppGateway::eventEnabled($event)) {
            $row = self::baseRow($sr, $event, $params, $preview, $dedupeKey, $relatedType, $relatedId, $triggeredBy);
            $row['status'] = 'skipped_disabled';
            // Fill recipient snapshot when resolvable, for the log.
            if ($sr->my_parent_id) {
                $row['parent_user_id'] = (int) $sr->my_parent_id;
            }
            self::insertRow($row);
            return false;
        }

        $resolved = self::resolveRecipient($sr, $event);
        if ($resolved['skip'] === 'grad') {
            return false;
        }
        $row = self::baseRow($sr, $event, $params, $preview, $dedupeKey, $relatedType, $relatedId, $triggeredBy, $resolved);
        if ($resolved['skip'] !== null) {
            $row['status'] = $resolved['skip'];
            self::insertRow($row);
            return false;
        }
        $row['status'] = 'queued';
        return self::insertRow($row);
    }

    /** Build one fan-out row for a record (status decided by resolution). */
    protected static function buildFanoutRow(StudentRecord $sr, string $event, array $params, string $preview, string $dedupeKey, $relatedType, $relatedId, $triggeredBy, $context = null): array
    {
        if (!WhatsAppGateway::eventEnabled($event)) {
            $row = self::baseRow($sr, $event, $params, $preview, $dedupeKey, $relatedType, $relatedId, $triggeredBy);
            $row['parent_user_id'] = $sr->my_parent_id ? (int) $sr->my_parent_id : null;
            $row['status'] = 'skipped_disabled';
            return $row;
        }
        $resolved = $context !== null
            ? self::resolveWith($sr, $event, $context[0], $context[1])
            : self::resolveRecipient($sr, $event);
        $row = self::baseRow($sr, $event, $params, $preview, $dedupeKey, $relatedType, $relatedId, $triggeredBy, $resolved);
        $row['status'] = $resolved['skip'] !== null ? $resolved['skip'] : 'queued';
        return $row;
    }

    /**
     * Bulk-load parents + preferences for a fan-out ONCE (the
     * 2026-10-30 query-storm warning in Notify's docblock): a
     * whole-school circular is thousands of parents, and resolving
     * per record with fresh queries would never finish.
     * @return array{0: \Illuminate\Support\Collection, 1: array}
     */
    protected static function fanoutContext($records): array
    {
        $parentIds = [];
        foreach ($records as $rec) {
            if ($rec->my_parent_id) {
                $parentIds[(int) $rec->my_parent_id] = true;
            }
        }
        $parentIds = array_keys($parentIds);
        $parents = $parentIds ? User::whereIn('id', $parentIds)->get()->keyBy('id') : collect();
        $prefs = [];
        if ($parentIds && Schema::hasTable('user_preferences')) {
            foreach (UserPreference::whereIn('user_id', $parentIds)->get(['user_id', 'key', 'value']) as $p) {
                $prefs[(int) $p->user_id][$p->key] = (string) $p->value;
            }
        }

        return [$parents, $prefs];
    }

    /** resolveRecipient against pre-loaded fan-out data — identical rules, no queries. */
    protected static function resolveWith(StudentRecord $sr, string $event, $parents, array $prefs): array
    {
        if ((int) $sr->grad === 1) {
            return ['parent' => null, 'phone' => null, 'skip' => 'grad'];
        }
        if (!$sr->my_parent_id) {
            return ['parent' => null, 'phone' => null, 'skip' => 'skipped_no_phone'];
        }
        $parent = $parents[(int) $sr->my_parent_id] ?? null;
        if (!$parent || $parent->user_type !== 'parent') {
            return ['parent' => $parent, 'phone' => null, 'skip' => 'skipped_no_phone'];
        }
        $phone = WhatsAppPhone::normalize($parent->phone ?: null)
            ?: WhatsAppPhone::normalize($parent->phone2 ?: null);
        if ($phone === null) {
            return ['parent' => $parent, 'phone' => null, 'skip' => 'skipped_no_phone'];
        }
        $defaults = UserPreference::defaults();
        $stored = $prefs[(int) $parent->id] ?? [];
        $wa = $stored['notify_whatsapp'] ?? ($defaults['notify_whatsapp'] ?? '0');
        if ((string) $wa !== '1') {
            return ['parent' => $parent, 'phone' => $phone, 'skip' => 'skipped_opt_out'];
        }
        $prefKey = self::EVENT_PREFS[$event] ?? null;
        if ($prefKey) {
            $v = $stored[$prefKey] ?? ($defaults[$prefKey] ?? '0');
            if ((string) $v !== '1') {
                return ['parent' => $parent, 'phone' => $phone, 'skip' => 'skipped_opt_out'];
            }
        }

        return ['parent' => $parent, 'phone' => $phone, 'skip' => null];
    }

    protected static function baseRow(StudentRecord $sr, string $event, array $params, string $preview, string $dedupeKey, $relatedType, $relatedId, $triggeredBy, $resolved = null): array
    {
        $now = now();

        return [
            'parent_user_id' => $resolved && !empty($resolved['parent']) ? (int) $resolved['parent']->id : ($sr->my_parent_id ? (int) $sr->my_parent_id : null),
            'student_record_id' => (int) $sr->id,
            'recipient_phone' => $resolved['phone'] ?? null,
            'event' => $event,
            'template_name' => self::TEMPLATES[$event] ?? '',
            'template_language' => WhatsAppGateway::templateLanguage(),
            'params' => json_encode(array_values($params)),
            'body_preview' => $preview,
            'related_type' => $relatedType,
            'related_id' => $relatedId,
            'dedupe_key' => $dedupeKey,
            'status' => 'queued',
            'provider_message_id' => null,
            'error_code' => null,
            'error_message' => null,
            'attempts' => 0,
            'triggered_by' => $triggeredBy ? (int) $triggeredBy : null,
            'sent_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** insertOrIgnore semantics: a duplicate dedupe key is a normal outcome. */
    protected static function insertRow(array $row): bool
    {
        try {
            return WhatsAppMessage::insertOrIgnore([$row]) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Bulk insert in chunks of 500 (the Notify::toUsers habit). */
    protected static function bulkInsert(array $rows): int
    {
        $inserted = 0;
        foreach (array_chunk($rows, 500) as $chunk) {
            if (!$chunk) {
                continue;
            }
            try {
                $inserted += WhatsAppMessage::insertOrIgnore($chunk);
            } catch (\Throwable $e) {
                WhatsAppGateway::logEvent('bulk insert failed', ['error' => substr($e->getMessage(), 0, 200)]);
            }
        }

        return $inserted;
    }

    protected static function sentMockedToday(): int
    {
        return WhatsAppMessage::whereIn('status', ['sent', 'mocked'])
            ->whereDate('sent_at', Carbon::today()->toDateString())
            ->count();
    }

    protected static function sentMockedTodayForParent(int $parentId): int
    {
        return WhatsAppMessage::where('parent_user_id', $parentId)
            ->whereIn('status', ['sent', 'mocked'])
            ->whereDate('sent_at', Carbon::today()->toDateString())
            ->count();
    }

    protected static function actionableTodayForParent(int $parentId): int
    {
        return WhatsAppMessage::where('parent_user_id', $parentId)
            ->whereIn('status', ['queued', 'sent', 'mocked', 'delivered', 'read'])
            ->whereDate('created_at', Carbon::today()->toDateString())
            ->count();
    }
}
