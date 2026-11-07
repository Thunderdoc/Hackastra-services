<?php

namespace App\Support;

use App\Helpers\Qs;
use App\Models\FeeHead;
use App\Models\FeeReminderLog;
use App\Models\PaymentRecord;
use App\Models\StudentRecord;
use App\Models\UserNotification;
use App\Support\WhatsApp\WhatsAppGateway;
use App\Repositories\SettingRepo;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * F5 (Round 4, design §3) — the scheduled, deduped, logged fee
 * reminder engine on top of the ONE dues source (FeeDefaulter)
 * and the bulk bell writer (Notify::insertMany).
 *
 * Three entry points (design §3.1):
 *  - runDue($today)   the scheduled pass, with missed-day catchup
 *  - sendNow(...)     the manual Defaulters-page send, rewired
 *  - previewDue($year) read-only "who would be reminded and why"
 *
 * Recipients and amounts come ONLY from FeeDefaulter::rows() /
 * outstandingFor(). The engine never sums payment_records for a
 * dues figure. The WhatsApp leg rides F1 (Round 4): when the
 * WhatsApp gateway's fee event is enabled, dispatch hands each
 * defaulter to ParentWhatsApp (which owns dedupe, cooldown,
 * opt-out and the mocked/sent truth in whatsapp_messages); when
 * it is not, WhatsApp events are logged skipped_no_channel,
 * bells proceed, nothing throws (F5 design §7).
 */
class FeeReminderEngine
{
    /* ----------------------------------------------------------------
     | Entry points
     | --------------------------------------------------------------- */

    /**
     * The scheduled pass for $today (design §3.2/§3.3/§3.4).
     * No-op while the master switch is off. Evaluates every
     * missed day since fee_reminder_last_run as well as today —
     * dedupe keys are date-independent, so a late run still sends
     * the correct rule for the correct due date, never a duplicate.
     */
    public function runDue($today = null): array
    {
        $today = $today ? Carbon::parse($today)->startOfDay() : Carbon::today();
        $empty = ['sent' => 0, 'bells' => 0, 'students' => 0, 'skipped_paid' => 0, 'skipped_no_channel' => 0, 'disabled' => false];

        if (!$this->enabled()) {
            return array_merge($empty, ['disabled' => true]);
        }
        if (!Schema::hasTable('fee_reminder_logs')) {
            return $empty;
        }

        $year = Qs::getCurrentSession();
        $channels = $this->scheduledChannels();

        $days = [$today->copy()];
        $lastRun = $this->lastRunDate();
        if ($lastRun && $lastRun->lt($today)) {
            $days = [];
            for ($d = $lastRun->copy()->addDay(); $d->lte($today); $d->addDay()) {
                $days[] = $d->copy();
            }
        }

        $totals = $empty;
        foreach ($days as $day) {
            $events = $this->eventsForDate($day, $year);
            $res = $this->dispatch($events, 'scheduled', $channels, null, null, false);
            $totals['sent'] += $res['sent'];
            $totals['bells'] += $res['bells'];
            $totals['students'] += $res['students'];
            $totals['skipped_paid'] += $res['skipped_paid'];
            $totals['skipped_no_channel'] += $res['skipped_no_channel'];
        }

        $this->stampLastRun($today);

        return $totals;
    }

    /**
     * Manual send (design §6.2): the Defaulters page set, narrowed
     * by exactly the listing's filters. Bell output is identical
     * to the old Notify::feeReminders path (refresh unread, don't
     * duplicate), plus one trigger=manual log row per student per
     * channel. Works while the scheduled master switch is off.
     */
    public function sendNow($year, $selected_class = null, array $filters = [], $actor = null): array
    {
        $empty = ['defaulters' => 0, 'notifications' => 0, 'sent' => 0, 'skipped_paid' => 0, 'skipped_no_channel' => 0, 'whatsapp_skipped' => 0];
        if (!$year || !Schema::hasTable('fee_reminder_logs')) {
            return $empty;
        }

        $rows = FeeDefaulter::rows($year, $selected_class, $filters);
        $events = [];
        foreach ($rows as $row) {
            $sr = $row['student'];
            if (!$sr || !$sr->user_id) {
                continue;
            }
            $events[] = [
                'student_id' => (int) $sr->user_id,
                'student_record_id' => (int) $sr->id,
                'parent_user_id' => $sr->my_parent_id ? (int) $sr->my_parent_id : null,
                'year' => $year,
                'rule_offset' => null,
                'due_date' => null,
                'fee_head_id' => null,
                'installment_id' => null,
                'amount_due' => (int) $row['total_due'],
                'dedupe_part' => null,
                'label' => null,
            ];
        }

        $channels = ['bell'];
        if ($this->setting('fee_reminder_channel_whatsapp', '0') === '1') {
            $channels[] = 'whatsapp';
        }

        $res = $this->dispatch($events, 'manual', $channels, $actor ? (int) $actor->id : null, (string) Str::uuid(), true);

        return [
            'defaulters' => $res['students'],
            'notifications' => $res['bells'],
            'sent' => $res['sent'],
            'skipped_paid' => $res['skipped_paid'],
            'skipped_no_channel' => $res['skipped_no_channel'],
            'whatsapp_skipped' => $res['skipped_no_channel'],
        ];
    }

    /**
     * Read-only preview (design §3.1): the events that would fire
     * on $date (default today) for the session — no writes at all.
     */
    public function previewDue($year, $date = null): Collection
    {
        $date = $date ? Carbon::parse($date)->startOfDay() : Carbon::today();

        return collect($this->eventsForDate($date, $year));
    }

    /**
     * How many defaulters have dues but NO resolvable due date —
     * the age-event population the panel surfaces (design §6.3).
     */
    public function agePopulation($year): int
    {
        $rows = FeeDefaulter::rows($year);
        $heads = $this->headMap($year);
        $count = 0;
        foreach ($rows as $row) {
            $sr = $row['student'];
            if (!$sr || !$sr->user_id) {
                continue;
            }
            if (!$this->studentHasDatedInstallment((int) $sr->user_id, $year, $heads)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * The opportunistic trigger (design §3.4C): at most one run
     * per calendar day, guarded by fee_reminder_last_run. Called
     * from the accountant/admin dashboard load and the Defaulters
     * page load. A disabled master switch never stamps the day,
     * so enabling the schedule later the same day still runs.
     */
    public static function maybeRunOpportunistically(): ?array
    {
        try {
            if (!Schema::hasTable('fee_reminder_logs')) {
                return null;
            }
            $engine = new static();
            if (!$engine->enabled()) {
                return null;
            }
            $today = Carbon::today();
            $lastRun = $engine->lastRunDate();
            if ($lastRun && $lastRun->isSameDay($today)) {
                return null;
            }

            return $engine->runDue($today);
        } catch (\Throwable $e) {
            // A reminder hiccup must never break the dashboard.
            return null;
        }
    }

    /* ----------------------------------------------------------------
     | Settings
     | --------------------------------------------------------------- */

    protected function setting(string $key, $default = null)
    {
        $value = Qs::getSetting($key);

        return ($value === null || $value === '') ? $default : (string) $value;
    }

    public function enabled(): bool
    {
        return $this->setting('fee_reminders_enabled', '0') === '1';
    }

    /** @return int[] signed day offsets, canonical order */
    public function offsets(): array
    {
        $raw = $this->setting('fee_reminder_offsets', '-3,0,7');
        $out = [];
        foreach (explode(',', (string) $raw) as $part) {
            $part = trim($part);
            if ($part !== '' && is_numeric($part)) {
                $out[] = (int) $part;
            }
        }
        $out = array_values(array_unique($out));
        sort($out);

        return $out ?: [-3, 0, 7];
    }

    protected function scheduledChannels(): array
    {
        $channels = [];
        if ($this->setting('fee_reminder_channel_bell', '1') === '1') {
            $channels[] = 'bell';
        }
        if ($this->setting('fee_reminder_channel_whatsapp', '0') === '1') {
            $channels[] = 'whatsapp';
        }

        return $channels;
    }

    protected function lastRunDate(): ?Carbon
    {
        $raw = $this->setting('fee_reminder_last_run');
        if (!$raw) {
            return null;
        }
        try {
            return Carbon::createFromFormat('Y-m-d', trim($raw))->startOfDay();
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function stampLastRun(Carbon $today): void
    {
        (new SettingRepo())->update('fee_reminder_last_run', $today->format('Y-m-d'));
    }

    /**
     * The WhatsApp channel (F1) is available when its outbox
     * table exists and the gateway's fee_reminder event is
     * enabled (mock or live — ParentWhatsApp decides which,
     * and its rows carry the delivery truth).
     */
    protected function whatsappAvailable(): bool
    {
        return Schema::hasTable('whatsapp_messages')
            && WhatsAppGateway::enabled('fee_reminder');
    }

    /* ----------------------------------------------------------------
     | Event derivation (design §3.2)
     | --------------------------------------------------------------- */

    /** FeeHeads of the session keyed by the apply() triple. */
    protected function headMap($year): array
    {
        $map = [];
        foreach (FeeHead::with('installments')->where('year', $year)->orderBy('id')->get() as $head) {
            $key = $head->title . '|' . $head->my_class_id . '|' . $head->year;
            if (!isset($map[$key])) {
                $map[$key] = $head;
            }
        }

        return $map;
    }

    protected function matchHead($payment, $year, array $headMap): ?FeeHead
    {
        if (!$payment) {
            return null;
        }
        $key = $payment->title . '|' . $payment->my_class_id . '|' . $year;

        return $headMap[$key] ?? null;
    }

    protected function studentHasDatedInstallment(int $studentUserId, $year, array $headMap): bool
    {
        $records = PaymentRecord::with('payment')
            ->where('year', $year)->where('paid', 0)
            ->where('student_id', $studentUserId)->get();
        foreach ($records as $record) {
            $head = $this->matchHead($record->payment, $year, $headMap);
            if ($head && $head->installments->whereNotNull('due_date')->isNotEmpty()) {
                return true;
            }
        }

        return false;
    }

    protected function eventsForDate(Carbon $day, $year): array
    {
        if (!Schema::hasTable('fee_reminder_logs')) {
            return [];
        }
        $rows = FeeDefaulter::rows($year);
        $headMap = $this->headMap($year);
        $offsets = $this->offsets();
        $events = [];

        foreach ($rows as $row) {
            $sr = $row['student'];
            if (!$sr || !$sr->user_id) {
                continue;
            }
            $records = PaymentRecord::with('payment')
                ->where('year', $year)->where('paid', 0)
                ->where('student_id', $sr->user_id)->get();
            if ($records->isEmpty()) {
                continue;
            }

            $base = [
                'student_id' => (int) $sr->user_id,
                'student_record_id' => (int) $sr->id,
                'parent_user_id' => $sr->my_parent_id ? (int) $sr->my_parent_id : null,
                'year' => $year,
                'amount_due' => (int) $row['total_due'],
            ];

            // 1. Instalment events (primary).
            $hasDated = false;
            $installmentEvents = [];
            foreach ($records as $record) {
                $head = $this->matchHead($record->payment, $year, $headMap);
                if (!$head) {
                    continue;
                }
                $dated = $head->installments->whereNotNull('due_date');
                if ($dated->isEmpty()) {
                    continue;
                }
                $hasDated = true;
                $coverage = FeePlanPresenter::fifoCoverage($head, (int) ($record->amt_paid ?: 0));
                foreach ($dated as $inst) {
                    $covered = (int) ($coverage[$inst->id] ?? 0);
                    if ($covered >= (int) $inst->amount) {
                        continue; // fully covered — no event for it
                    }
                    $due = Carbon::parse($inst->due_date)->startOfDay();
                    foreach ($offsets as $offset) {
                        if ($day->isSameDay($due->copy()->addDays($offset))) {
                            $uncovered = (int) $inst->amount - $covered;
                            $installmentEvents[] = array_merge($base, [
                                'rule_offset' => $offset,
                                'due_date' => $due->format('Y-m-d'),
                                'fee_head_id' => (int) $head->id,
                                'installment_id' => (int) $inst->id,
                                'dedupe_part' => 'inst:' . $inst->id,
                                'label' => 'Instalment ' . (int) $inst->installment_no . ' of ' . $head->title
                                    . ' — ₹' . number_format($uncovered) . ' due on ' . $due->format('d M Y'),
                            ]);
                        }
                    }
                }
            }
            if ($hasDated) {
                foreach ($installmentEvents as $event) {
                    $events[] = $event;
                }
                continue;
            }

            // 2. Age events (fallback — dues with no resolvable
            // due date). Non-negative offsets only: there is no
            // "before" for a fee that has no due date.
            $oldest = $records->sortBy('created_at')->first();
            if (!$oldest || !$oldest->created_at) {
                continue;
            }
            $oldestDate = Carbon::parse($oldest->created_at)->startOfDay();
            foreach ($offsets as $offset) {
                if ($offset < 0) {
                    continue;
                }
                if ($day->isSameDay($oldestDate->copy()->addDays($offset))) {
                    $events[] = array_merge($base, [
                        'rule_offset' => $offset,
                        'due_date' => null,
                        'fee_head_id' => null,
                        'installment_id' => null,
                        'dedupe_part' => 'age:' . $oldest->id,
                        'label' => null,
                    ]);
                }
            }
        }

        return $events;
    }

    /* ----------------------------------------------------------------
     | The one sender (design §3.3 steps 2–6)
     | --------------------------------------------------------------- */

    private function dispatch(array $events, string $trigger, array $channels, $actorId, ?string $runUuid, bool $refreshBells): array
    {
        $result = ['sent' => 0, 'bells' => 0, 'students' => 0, 'skipped_paid' => 0, 'skipped_no_channel' => 0];
        if (!$events || !$channels) {
            return $result;
        }
        $year = $events[0]['year'];

        // Candidate (event, channel) pairs with their dedupe keys.
        $candidates = [];
        foreach ($events as $event) {
            foreach ($channels as $channel) {
                if ($trigger === 'manual') {
                    $key = 'manual|' . $event['student_id'] . '|' . $year . '|run:' . $runUuid . '|' . $channel;
                } else {
                    $key = 'sched|' . $event['student_id'] . '|' . $year . '|' . $event['dedupe_part']
                        . '|off:' . $event['rule_offset'] . '|' . $channel;
                }
                $candidates[] = ['event' => $event, 'channel' => $channel, 'key' => $key];
            }
        }

        // (2)+(3) drop keys already logged — the unique index, not
        // the trigger's discipline, is the guarantee.
        if ($trigger !== 'manual') {
            $existing = FeeReminderLog::whereIn('dedupe_key', array_column($candidates, 'key'))
                ->pluck('dedupe_key')->all();
            if ($existing) {
                $existing = array_flip($existing);
                $candidates = array_values(array_filter($candidates, function ($c) use ($existing) {
                    return !isset($existing[$c['key']]);
                }));
            }
        }
        if (!$candidates) {
            return $result;
        }

        // (4) Send-time re-check, once per student: a student who
        // paid in full since selection is logged skipped_paid and
        // receives nothing; that row also consumes the key.
        $outstanding = [];
        foreach (array_unique(array_column(array_column($candidates, 'event'), 'student_id')) as $studentId) {
            $outstanding[$studentId] = FeeDefaulter::outstandingFor($studentId, $year);
        }

        $now = now();
        $logRows = [];
        $log = function (array $candidate, string $status) use (&$logRows, $trigger, $actorId, $now) {
            $event = $candidate['event'];
            $logRows[] = [
                'student_id' => $event['student_id'],
                'student_record_id' => $event['student_record_id'],
                'parent_user_id' => $event['parent_user_id'],
                'year' => $event['year'],
                'channel' => $candidate['channel'],
                'trigger' => $trigger,
                'rule_offset' => $event['rule_offset'],
                'due_date' => $event['due_date'],
                'fee_head_id' => $event['fee_head_id'],
                'installment_id' => $event['installment_id'],
                'amount_due' => $event['amount_due'],
                'sent_by' => $trigger === 'manual' ? $actorId : null,
                'status' => $status,
                'provider_ref' => null,
                'dedupe_key' => $candidate['key'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        };

        $paidStudents = [];
        $liveCandidates = [];
        foreach ($candidates as $candidate) {
            if (($outstanding[$candidate['event']['student_id']] ?? 0) <= 0) {
                $log($candidate, 'skipped_paid');
                $result['skipped_paid']++;
                $paidStudents[$candidate['event']['student_id']] = true;
            } else {
                $liveCandidates[] = $candidate;
            }
        }

        // (5) WhatsApp leg: hand each defaulter to F1's
        // ParentWhatsApp engine (queue + inline drain; its
        // dedupe key makes repeat dispatches safe). An
        // unavailable channel logs skipped_no_channel and
        // never throws. One engine call per student per
        // dispatch — F1 owns per-child dedupe + cooldown.
        $bellCandidates = [];
        $whatsappByStudent = [];
        foreach ($liveCandidates as $candidate) {
            if ($candidate['channel'] === 'whatsapp') {
                if (!$this->whatsappAvailable()) {
                    $log($candidate, 'skipped_no_channel');
                    $result['skipped_no_channel']++;
                    continue;
                }
                $whatsappByStudent[$candidate['event']['student_record_id']][] = $candidate;
                continue;
            }
            $bellCandidates[] = $candidate;
        }
        foreach ($whatsappByStudent as $recordId => $studentCandidates) {
            $sr = StudentRecord::find($recordId);
            if ($sr) {
                ParentWhatsApp::feeReminder($sr, (int) $studentCandidates[0]['event']['amount_due'], (string) $year, $actorId ? (int) $actorId : null);
            }
            foreach ($studentCandidates as $candidate) {
                $log($candidate, 'sent');
                $result['sent']++;
            }
        }

        // Bells: ONE per student for this dispatch day, events
        // merged (design §3.2.3), written via insertMany only.
        if ($bellCandidates) {
            $byStudent = [];
            foreach ($bellCandidates as $candidate) {
                $byStudent[$candidate['event']['student_id']][] = $candidate;
            }

            if ($refreshBells) {
                // Manual re-send semantics (Wave 5, unchanged):
                // refresh unread copies, don't duplicate.
                $recipientIds = [];
                foreach ($byStudent as $studentCandidates) {
                    $event = $studentCandidates[0]['event'];
                    $recipientIds[] = $event['student_id'];
                    if ($event['parent_user_id']) {
                        $recipientIds[] = $event['parent_user_id'];
                    }
                }
                UserNotification::where('type', 'fee_reminder')->whereNull('read_at')
                    ->whereIn('user_id', array_unique($recipientIds))->delete();
            }

            $bells = [];
            foreach ($byStudent as $studentId => $studentCandidates) {
                $event = $studentCandidates[0]['event'];
                $due = '₹' . number_format((int) $event['amount_due']);
                $title = 'Fee reminder: ' . $due . ' outstanding';
                $labels = [];
                foreach ($studentCandidates as $candidate) {
                    if (!empty($candidate['event']['label'])) {
                        $labels[] = $candidate['event']['label'];
                    }
                }
                if ($labels) {
                    $shown = array_slice($labels, 0, 2);
                    $body = implode('. ', $shown) . '.';
                    if (count($labels) > 2) {
                        $body .= ' And more instalments are due.';
                    }
                    $body .= ' Total outstanding for session ' . $year . ': ' . $due . '. Please clear it at the school office.';
                } else {
                    $body = $due . ' is outstanding for session ' . $year . '. Please clear it at the school office.';
                }

                $bells[] = [
                    'user_id' => (int) $studentId, 'type' => 'fee_reminder',
                    'title' => $title, 'body' => $body, 'link' => route('student.fees'),
                ];
                if ($event['parent_user_id']) {
                    $bells[] = [
                        'user_id' => (int) $event['parent_user_id'], 'type' => 'fee_reminder',
                        'title' => $title, 'body' => $body, 'link' => route('my_fees'),
                    ];
                }
                $result['students']++;
                foreach ($studentCandidates as $candidate) {
                    $log($candidate, 'sent');
                    $result['sent']++;
                }
            }

            Notify::insertMany($bells);
            $result['bells'] = count($bells);
        }

        // (6) Bulk-insert the log rows.
        foreach (array_chunk($logRows, 500) as $chunk) {
            FeeReminderLog::insert($chunk);
        }

        return $result;
    }
}
