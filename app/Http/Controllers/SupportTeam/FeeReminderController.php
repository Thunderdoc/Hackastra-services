<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Models\FeeReminderLog;
use App\Models\MyClass;
use App\Support\CsvExport;
use App\Support\FeeReminderEngine;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * F5 (Round 4, design §6.3) — the Fee Reminders panel + log page,
 * the Run-due-now override, the CSV export of the filtered log,
 * and the signed external ping endpoint (design §3.4B).
 *
 * Every in-app route here is gated teamAccountWrite (Admin +
 * Accountant ONLY — the Super Admin, Principal, teachers,
 * students and parents are all refused; design §6.1 pins this).
 * The ping endpoint is the single exception: it is public by
 * design, guarded instead by its secret token.
 */
class FeeReminderController extends Controller
{
    public function __construct()
    {
        $this->middleware('teamAccountWrite')->except('ping');
    }

    public function index(Request $request)
    {
        $engine = new FeeReminderEngine();
        $year = Qs::getCurrentSession();

        $d['year'] = $year;
        $d['enabled'] = $engine->enabled();
        $d['offsets'] = $engine->offsets();
        $d['channel_bell'] = Qs::getSetting('fee_reminder_channel_bell');
        $d['channel_bell'] = $d['channel_bell'] === null ? '1' : $d['channel_bell'];
        $d['channel_whatsapp'] = Qs::getSetting('fee_reminder_channel_whatsapp') ?: '0';
        $d['is_admin'] = Qs::userIsAdmin();

        $todayEvents = $engine->previewDue($year);
        $d['due_today'] = $todayEvents->pluck('student_id')->unique()->count();
        $next = 0;
        for ($i = 1; $i <= 7; $i++) {
            $dayEvents = $engine->previewDue($year, Carbon::today()->addDays($i));
            $next += $dayEvents->pluck('student_id')->unique()->count();
        }
        $d['due_next_7'] = $next;
        $d['age_population'] = $engine->agePopulation($year);

        $lastRun = Qs::getSetting('fee_reminder_last_run');
        $d['last_run_label'] = $lastRun
            ? Carbon::createFromFormat('Y-m-d', $lastRun)->format('d M Y')
            : null;

        $d['filters'] = $this->logFilters($request);
        $d['classes'] = MyClass::orderBy('name')->get();
        $d['logs'] = $this->logQuery($d['filters'])->paginate(25)->appends($request->query());

        return view('pages.support_team.payments.reminders', $d);
    }

    /** The manual override for the opportunistic trigger. */
    public function runNow()
    {
        $engine = new FeeReminderEngine();
        $res = $engine->runDue(Carbon::today());

        if (!empty($res['disabled'])) {
            return back()->with('flash_danger', 'Automatic fee reminders are disabled in Settings — nothing was sent. Enable them there first.');
        }

        return back()->with('flash_success', 'Fee reminders run: ' . $res['sent'] . ' sent ('
            . $res['students'] . ' student(s)), ' . $res['skipped_paid'] . ' skipped (already paid), '
            . $res['skipped_no_channel'] . ' skipped (no channel).');
    }

    public function export(Request $request)
    {
        $filters = $this->logFilters($request);
        $rows = $this->logQuery($filters)->get()->map(function ($log) {
            return [
                $log->created_at ? $log->created_at->format('d M Y, h:i A') : '',
                optional(optional($log->student_record)->user)->name ?: optional($log->student)->name,
                optional($log->student_record)->adm_no,
                optional(optional($log->student_record)->my_class)->name,
                optional(optional($log->student_record)->section)->name,
                optional($log->parent_user)->name ?: '—',
                $log->channel === 'whatsapp' ? 'WhatsApp' : 'Bell',
                self::ruleLabel($log),
                $log->amount_due,
                $log->sent_by ? (optional($log->sender)->name ?: '—') : 'System',
                self::statusLabel($log),
            ];
        });

        return CsvExport::download('fee-reminders-log.csv',
            ['When', 'Student', 'Admission No', 'Class', 'Section', 'Parent', 'Channel', 'Rule', 'Amount Due', 'Sent By', 'Status'],
            $rows);
    }

    /**
     * Signed external ping (design §3.4B): a free external pinger
     * hits this daily so reminders can fire even on a day nobody
     * opens the app. No session, no login — the token IS the
     * credential. Off (404) until a token is configured, via the
     * FEE_REMINDER_CRON_TOKEN env var or the fee_reminder_cron_token
     * setting (written directly, never through the Settings form).
     * A leaked token lets someone trigger reminders EARLY only —
     * the engine is idempotent, so nothing can double-send.
     */
    public function ping($token)
    {
        $configured = env('FEE_REMINDER_CRON_TOKEN') ?: Qs::getSetting('fee_reminder_cron_token');
        if (!$configured) {
            abort(404);
        }
        if (!hash_equals((string) $configured, (string) $token)) {
            abort(403);
        }

        $res = (new FeeReminderEngine())->runDue(Carbon::today());

        return response()->json([
            'status' => 'ok',
            'sent' => $res['sent'],
            'skipped_paid' => $res['skipped_paid'],
            'skipped_no_channel' => $res['skipped_no_channel'],
            'disabled' => !empty($res['disabled']),
        ]);
    }

    public static function ruleLabel(FeeReminderLog $log): string
    {
        if ($log->trigger === 'manual') {
            return 'Manual';
        }
        if ($log->rule_offset === null) {
            return 'Auto';
        }
        if ((int) $log->rule_offset < 0) {
            return 'Auto · ' . abs((int) $log->rule_offset) . 'd before';
        }
        if ((int) $log->rule_offset === 0) {
            return 'Auto · On due date';
        }

        return 'Auto · +' . (int) $log->rule_offset . 'd';
    }

    public static function statusLabel(FeeReminderLog $log): string
    {
        $labels = [
            'sent' => 'Sent',
            'skipped_paid' => 'Skipped – paid',
            'skipped_no_channel' => 'Skipped – no channel',
            'failed' => 'Failed',
        ];

        return $labels[$log->status] ?? ucfirst(str_replace('_', ' ', (string) $log->status));
    }

    protected function logFilters(Request $request): array
    {
        $parse = function ($key) use ($request) {
            $value = $request->query($key);
            if (!$value) {
                return null;
            }
            try {
                return Carbon::createFromFormat('Y-m-d', $value);
            } catch (\Exception $e) {
                return null;
            }
        };

        return [
            'from' => $parse('from'),
            'to' => $parse('to'),
            'from_str' => $request->query('from') ?: '',
            'to_str' => $request->query('to') ?: '',
            'channel' => in_array($request->query('channel'), ['bell', 'whatsapp'], true) ? $request->query('channel') : '',
            'trigger' => in_array($request->query('trigger'), ['scheduled', 'manual'], true) ? $request->query('trigger') : '',
            'class_id' => $request->query('class_id') ? (int) $request->query('class_id') : null,
        ];
    }

    protected function logQuery(array $filters)
    {
        $q = FeeReminderLog::with(['student', 'parent_user', 'sender', 'student_record.user', 'student_record.my_class', 'student_record.section'])
            ->latest('id');

        if (!Schema::hasTable('fee_reminder_logs')) {
            return $q->whereRaw('1 = 0');
        }
        if ($filters['from']) {
            $q->where('created_at', '>=', $filters['from']->copy()->startOfDay());
        }
        if ($filters['to']) {
            $q->where('created_at', '<=', $filters['to']->copy()->endOfDay());
        }
        if ($filters['channel']) {
            $q->where('channel', $filters['channel']);
        }
        if ($filters['trigger']) {
            $q->where('trigger', $filters['trigger']);
        }
        if ($filters['class_id']) {
            $classId = $filters['class_id'];
            $q->whereHas('student_record', function ($sub) use ($classId) {
                $sub->where('my_class_id', $classId);
            });
        }

        return $q;
    }
}
