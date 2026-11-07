<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Models\WhatsAppMessage;
use App\Support\Audit;
use App\Support\ParentWhatsApp;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * F1 (Round 4, design §9) — the WhatsApp sent-log.
 *
 * Gates: index — Admin + Principal + Super Admin (view; SA is
 * allow-listed in PlatformOnly for this one page, following the
 * settings-unlock precedent). retry — teamSAWrite route
 * middleware (school Admin only; SA is view-only inside schools,
 * Principal cannot retry). Teachers / parents / students /
 * accountant: 403.
 *
 * Phone numbers render MASKED only — the full number exists
 * solely in the DB row.
 */
class WhatsAppLogController extends Controller
{
    public const EVENTS = [
        'attendance_absent' => 'Absence',
        'fee_reminder' => 'Fee Reminder',
        'result_published' => 'Result Published',
        'circular_published' => 'Circular',
        'assignment_created' => 'Homework',
    ];

    public const STATUSES = [
        'queued', 'sent', 'mocked', 'delivered', 'read', 'failed',
        'skipped_no_phone', 'skipped_opt_out', 'skipped_disabled',
        'suppressed_cooldown',
    ];

    public function index(Request $request)
    {
        $type = auth()->user() ? auth()->user()->user_type : null;
        if (!in_array($type, ['admin', 'principal', 'super_admin'], true)) {
            abort(403);
        }

        $filters = [
            'event' => in_array($request->query('event'), array_keys(self::EVENTS), true) ? $request->query('event') : '',
            'status' => in_array($request->query('status'), self::STATUSES, true) ? $request->query('status') : '',
            'from' => $request->query('from') ?: '',
            'to' => $request->query('to') ?: '',
            'q' => trim((string) $request->query('q', '')),
        ];

        $hasTable = Schema::hasTable('whatsapp_messages');

        $query = WhatsAppMessage::with(['parent', 'studentRecord.user', 'studentRecord.my_class'])
            ->latest('id');
        if (!$hasTable) {
            $query->whereRaw('1 = 0');
        }
        if ($filters['event'] !== '') {
            $query->where('event', $filters['event']);
        }
        if ($filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }
        if ($filters['from'] !== '') {
            $query->whereDate('created_at', '>=', $filters['from']);
        }
        if ($filters['to'] !== '') {
            $query->whereDate('created_at', '<=', $filters['to']);
        }
        if ($filters['q'] !== '') {
            $term = $filters['q'];
            $query->where(function ($w) use ($term) {
                $w->whereHas('parent', function ($p) use ($term) {
                    $p->where('name', 'like', '%' . $term . '%');
                })->orWhereHas('studentRecord.user', function ($p) use ($term) {
                    $p->where('name', 'like', '%' . $term . '%');
                });
            });
        }

        $d['messages'] = $query->paginate(25)->appends($request->query());
        $d['filters'] = $filters;
        $d['events'] = self::EVENTS;
        $d['statuses'] = self::STATUSES;
        $d['is_admin'] = Qs::userIsAdmin();
        $d['queued_count'] = $hasTable ? WhatsAppMessage::where('status', 'queued')->count() : 0;
        $d['stats'] = [
            'sent_today' => $hasTable ? WhatsAppMessage::whereIn('status', ['sent', 'delivered', 'read'])->whereDate('sent_at', Carbon::today()->toDateString())->count() : 0,
            'mocked' => $hasTable ? WhatsAppMessage::where('status', 'mocked')->count() : 0,
            'failed' => $hasTable ? WhatsAppMessage::where('status', 'failed')->count() : 0,
            'skipped' => $hasTable ? WhatsAppMessage::whereIn('status', ['skipped_no_phone', 'skipped_opt_out', 'skipped_disabled', 'suppressed_cooldown'])->count() : 0,
        ];

        return view('pages.support_team.whatsapp_log.index', $d);
    }

    public function retry($id)
    {
        $message = WhatsAppMessage::findOrFail($id);
        if ($message->status !== 'failed' || (int) $message->attempts >= 3) {
            return back()->with('flash_danger', 'Only a failed message with attempts left can be retried.');
        }

        $message->update([
            'status' => 'queued',
            'error_code' => null,
            'error_message' => null,
        ]);

        Audit::log('whatsapp.retried', $message, 'WhatsApp message #' . $message->id . ' (' . $message->event . ') requeued from the sent log.');

        ParentWhatsApp::drain((int) config('whatsapp.inline_drain_limit', 25), $message->id);

        return back()->with('flash_success', 'Message requeued and sent again if the provider accepts it.');
    }

    public static function statusLabel(WhatsAppMessage $m): string
    {
        switch ($m->status) {
            case 'mocked':
                return 'Mock — not sent';
            case 'skipped_no_phone':
                return 'Skipped — no phone';
            case 'skipped_opt_out':
                return 'Skipped — opted out';
            case 'skipped_disabled':
                return 'Skipped — disabled';
            case 'suppressed_cooldown':
                return 'Suppressed — cooldown';
            default:
                return ucfirst((string) $m->status);
        }
    }

    public static function statusBadge(WhatsAppMessage $m): string
    {
        switch ($m->status) {
            case 'sent':
            case 'delivered':
            case 'read':
                return 'badge-success';
            case 'mocked':
                return 'badge-info';
            case 'failed':
                return 'badge-danger';
            case 'queued':
                return 'badge-warning';
            default:
                return 'badge-secondary';
        }
    }
}
