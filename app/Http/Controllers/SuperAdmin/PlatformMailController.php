<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Mail\PlatformMessage;
use App\Models\SentMail;
use App\Repositories\UserRepo;
use App\Support\Audit;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

/**
 * Platform mail (Workstream DF) — the Super Admin's mail composer.
 *
 * A platform tool, deliberately NOT a school tool: recipients are
 * platform accounts (a specific user, all School Admins, or all
 * accounts of a role) and the message carries no school data. The
 * school Admin's school-wide communication channel stays Circulars.
 *
 * Honesty rules: every send attempt is recorded in sent_mails and
 * in the Audit log with its real outcome. When SMTP is not
 * configured on the deployment (the shipped .env leaves MAIL_HOST
 * blank), the message is written through the log mailer instead
 * and the UI + the log both say exactly that — delivery is never
 * faked.
 */
class PlatformMailController extends Controller
{
    protected $user;

    public function __construct(UserRepo $user)
    {
        $this->middleware('super_admin');
        $this->user = $user;
    }

    public function create()
    {
        $d['accounts'] = User::whereIn('user_type', ['super_admin', 'admin'])
            ->whereNotNull('email')->where('email', '<>', '')
            ->orderBy('name', 'asc')->get(['id', 'name', 'email', 'user_type']);
        /* Audit R-18 (2026-10-23): the composer listed ALL 55 users
           (37 students included) and every role under "platform
           accounts" copy. The lists now carry platform accounts only
           — the same scope resolveRecipients() enforces server-side. */
        $d['user_types'] = $this->user->getAllTypes()
            ->whereIn('title', ['super_admin', 'admin'])->values();
        $d['mail_note'] = $this->degradationNote();

        return view('pages.super_admin.platform_mail.create', $d);
    }

    public function index()
    {
        $d['sent_mails'] = SentMail::with('sender')->latest()->paginate(25);

        return view('pages.super_admin.platform_mail.index', $d);
    }

    public function store(Request $req)
    {
        $req->validate([
            'audience' => 'required|in:user,admins,role',
            'user_id' => 'required_if:audience,user',
            'role' => 'required_if:audience,role',
            'subject' => 'required|string|max:150',
            'body' => 'required|string|max:5000',
        ]);

        [$recipients, $audienceLabel] = $this->resolveRecipients($req);

        if ($recipients->isEmpty()) {
            return back()->withInput()->with('flash_danger', 'No recipients with an email address matched that audience — nothing was sent.');
        }

        /* Driver resolution: the app's configured mailer is the
           legacy 'driver' key. SMTP without a configured host cannot
           deliver anything, so the send degrades to the log mailer
           and is recorded as 'logged', never as 'sent'. */
        $configured = config('mail.default', 'smtp');
        $driver = $configured;
        $degraded = false;
        if ($configured === 'smtp' && empty(env('MAIL_HOST'))) {
            $driver = 'log';
            $degraded = true;
        }
        if (!in_array($driver, ['smtp', 'log', 'array', 'sendmail'], true)) {
            $driver = 'log';
            $degraded = true;
        }

        $status = $driver === 'log' ? 'logged' : 'sent';
        $error = null;

        try {
            foreach ($recipients as $recipient) {
                Mail::mailer($driver)->to($recipient->email)
                    ->send(new PlatformMessage($req->subject, $req->body));
            }
        } catch (\Throwable $e) {
            $status = 'failed';
            $error = $e->getMessage();
        }

        $sent = SentMail::create([
            'sender_id' => Auth::user()->id,
            'audience' => $req->audience,
            'audience_label' => $audienceLabel,
            'recipient_count' => $recipients->count(),
            'subject' => $req->subject,
            'body' => $req->body,
            'driver' => $driver,
            'status' => $status,
            'error' => $error,
        ]);

        Audit::log('mail.sent', $sent, 'Platform mail to ' . $audienceLabel . ' — ' . $recipients->count()
            . ' recipient(s), subject "' . $req->subject . '" (driver: ' . $driver . ', status: ' . $status . ')');

        if ($status === 'failed') {
            return redirect()->route('platform_mail.index')
                ->with('flash_danger', 'Mail could not be sent: ' . $error . ' The attempt has been recorded in Sent Mail.');
        }

        if ($status === 'logged') {
            return redirect()->route('platform_mail.index')
                ->with('flash_success', 'Recorded for ' . $recipients->count() . ' recipient(s). SMTP is not configured on this deployment, so the message was written to the mail log instead of being delivered — configure SMTP in production for real delivery.');
        }

        return redirect()->route('platform_mail.index')
            ->with('flash_success', 'Mail sent to ' . $recipients->count() . ' recipient(s).');
    }

    /**
     * Recipients are accounts with a usable email address.
     * Terminated accounts are offboarded and never mailed; the
     * message itself is platform communication (no school data).
     */
    protected function resolveRecipients(Request $req): array
    {
        /* Audit R-18: platform mail reaches platform accounts only
           (super_admin + admin) — enforced here, on the shared base
           query, so a crafted POST (a student id, role=student) can
           never widen the audience beyond what the composer offers;
           it resolves to an empty set and the send is refused. */
        $base = User::whereIn('user_type', ['super_admin', 'admin'])
            ->whereNotNull('email')->where('email', '<>', '')
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '!=', User::STATUS_TERMINATED);
            });

        if ($req->audience === 'user') {
            $id = Qs::decodeHash($req->user_id);
            $user = $id ? (clone $base)->find($id) : null;

            return [
                $user ? collect([$user]) : collect(),
                $user ? 'User: ' . $user->name . ' (' . $user->email . ')' : 'User',
            ];
        }

        if ($req->audience === 'admins') {
            return [(clone $base)->where('user_type', 'admin')->orderBy('name')->get(), 'All School Admins'];
        }

        $type = $this->user->getAllTypes()->firstWhere('title', $req->role);
        $label = $type ? 'All ' . $type->name . 's' : 'All ' . ucwords(str_replace('_', ' ', (string) $req->role)) . 's';

        return [(clone $base)->where('user_type', $req->role)->orderBy('name')->get(), $label];
    }

    /** The note the composer shows when sends will be log-recorded. */
    protected function degradationNote(): ?string
    {
        $configured = config('mail.default', 'smtp');

        if ($configured === 'log') {
            return 'This deployment sends mail through the log driver — messages are recorded in the mail log, not delivered. Configure SMTP in production for real delivery.';
        }

        if ($configured === 'smtp' && empty(env('MAIL_HOST'))) {
            return 'SMTP is not configured on this deployment (no mail host set), so messages will be written to the mail log instead of being delivered. Configure SMTP in production for real delivery.';
        }

        return null;
    }
}
