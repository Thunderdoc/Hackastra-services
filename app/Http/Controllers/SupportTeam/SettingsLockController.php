<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Models\SettingsUnlockRequest;
use App\Support\Audit;
use App\Support\Notify;
use App\Support\SettingsLock;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Settings lock + two-stage unlock approval (owner order
 * 2026-10-29). The Admin locks the school settings when the
 * school's configuration is final. Editing reopens only via a
 * WRITTEN request (what changes, and why): the Principal
 * approves first, then the Super Admin; on the Super Admin's
 * approval the settings unlock and BOTH the Admin and the
 * Principal are told the edit right is live. A rejection at
 * either stage ends the request and tells the Admin.
 */
class SettingsLockController extends Controller
{
    /** Admin: lock the settings now. */
    public function lock()
    {
        if (!Qs::userIsTeamSAWrite()) {
            abort(403, 'Only the Admin can lock the settings.');
        }
        SettingsLock::lock();
        Audit::log('settings.locked', null, 'School settings locked by ' . Auth::user()->name);

        return redirect()->route('settings')
            ->with('flash_success', 'Settings locked. Editing is closed until an unlock request is approved by the Principal and the Super Admin.');
    }

    /** Admin: file the written unlock request. */
    public function requestUnlock(Request $request)
    {
        if (!Qs::userIsTeamSAWrite()) {
            abort(403, 'Only the Admin can request an unlock.');
        }
        if (!SettingsLock::isLocked()) {
            return redirect()->route('settings')
                ->with('flash_success', 'Settings are not locked — you can edit them directly.');
        }
        if (SettingsLock::openRequest()) {
            return redirect()->route('settings')
                ->with('flash_danger', 'An unlock request is already waiting for approval. You will be notified when it is decided.');
        }
        $data = $request->validate([
            'changes' => 'required|string|min:10|max:2000',
        ]);
        $unlock = SettingsUnlockRequest::create([
            'requested_by' => Auth::id(),
            'changes' => $data['changes'],
            'status' => 'pending_principal',
        ]);
        Audit::log('settings.unlock_requested', null,
            'Unlock request ' . $unlock->id . ' filed by ' . Auth::user()->name);
        foreach (User::where('user_type', 'principal')->get() as $principal) {
            Notify::toUser($principal->id, 'settings_unlock_requested',
                'Settings unlock requested',
                Auth::user()->name . ' has asked to unlock the school settings: "' . $data['changes'] . '" Review and approve it to send it to the Super Admin.',
                route('settings_unlock.index'));
        }

        return redirect()->route('settings')
            ->with('flash_success', 'Unlock request sent to the Principal. After the Principal approves it, the Super Admin takes the final decision.');
    }

    /**
     * The shared requests page. Each role sees its own stage:
     * the Principal decides pending_principal, the Super Admin
     * decides pending_super_admin, the Admin follows his own
     * requests.
     */
    public function index()
    {
        $user = Auth::user();
        if (!in_array($user->user_type, ['admin', 'principal', 'super_admin'], true)) {
            abort(403);
        }
        $d['role'] = $user->user_type;
        $d['locked'] = SettingsLock::isLocked();
        $d['requests'] = SettingsUnlockRequest::with('requester')->latest()->take(30)->get();

        return view('pages.support_team.settings_unlock.index', $d);
    }

    /** Stage-aware decision: principal first, Super Admin last. */
    public function decide(Request $request, $unlock_request)
    {
        $unlock = SettingsUnlockRequest::findOrFail($unlock_request);
        $approve = $request->input('decision') === 'approve';
        $user = Auth::user();

        if ($unlock->status === 'pending_principal') {
            if ($user->user_type !== 'principal') {
                abort(403, 'This request is waiting for the Principal.');
            }
            $unlock->principal_decided_by = $user->id;
            $unlock->principal_decided_at = now();
            if ($approve) {
                $unlock->status = 'pending_super_admin';
                $unlock->save();
                Audit::log('settings.unlock_principal_approved', null,
                    'Unlock request ' . $unlock->id . ' approved by Principal ' . $user->name . ' — sent to the Super Admin');
                Notify::toUser($unlock->requested_by, 'settings_unlock_progress',
                    'Unlock request: Principal approved',
                    'The Principal approved your settings unlock request. It is now with the Super Admin for the final decision.',
                    route('settings_unlock.index'));

                return back()->with('flash_success', 'Approved — the request is now with the Super Admin for the final decision.');
            }
            $unlock->status = 'rejected';
            $unlock->save();
            Audit::log('settings.unlock_rejected', null,
                'Unlock request ' . $unlock->id . ' rejected by Principal ' . $user->name);
            Notify::toUser($unlock->requested_by, 'settings_unlock_rejected',
                'Unlock request rejected',
                'The Principal rejected your settings unlock request. The settings stay locked.',
                route('settings'));

            return back()->with('flash_success', 'Request rejected. The settings stay locked.');
        }

        if ($unlock->status === 'pending_super_admin') {
            if ($user->user_type !== 'super_admin') {
                abort(403, 'This request is waiting for the Super Admin.');
            }
            $unlock->super_decided_by = $user->id;
            $unlock->super_decided_at = now();
            if ($approve) {
                $unlock->status = 'approved';
                $unlock->save();
                SettingsLock::unlock();
                Audit::log('settings.unlocked', null,
                    'Unlock request ' . $unlock->id . ' approved by Super Admin ' . $user->name . ' — settings unlocked');
                // Owner order: BOTH the Admin and the Principal
                // are told the edit right is live.
                Notify::toUser($unlock->requested_by, 'settings_unlock_approved',
                    'Settings unlocked — you can edit now',
                    'The Super Admin approved your unlock request. Settings editing is open on your portal until you lock it again.',
                    route('settings'));
                foreach (User::where('user_type', 'principal')->get() as $principal) {
                    Notify::toUser($principal->id, 'settings_unlock_approved',
                        'Settings unlocked for the Admin',
                        'The Super Admin approved the settings unlock request you approved. The Admin can edit the school settings again.',
                        route('settings_unlock.index'));
                }

                return back()->with('flash_success', 'Approved — the settings are unlocked and the Admin and Principal have been notified.');
            }
            $unlock->status = 'rejected';
            $unlock->save();
            Audit::log('settings.unlock_rejected', null,
                'Unlock request ' . $unlock->id . ' rejected by Super Admin ' . $user->name);
            Notify::toUser($unlock->requested_by, 'settings_unlock_rejected',
                'Unlock request rejected',
                'The Super Admin rejected your settings unlock request. The settings stay locked.',
                route('settings'));
            foreach (User::where('user_type', 'principal')->get() as $principal) {
                Notify::toUser($principal->id, 'settings_unlock_rejected',
                    'Settings unlock rejected by the Super Admin',
                    'The Super Admin rejected the settings unlock request you had approved. The settings stay locked.',
                    route('settings_unlock.index'));
            }

            return back()->with('flash_success', 'Request rejected. The settings stay locked.');
        }

        return back()->with('flash_danger', 'That request has already been decided.');
    }
}
