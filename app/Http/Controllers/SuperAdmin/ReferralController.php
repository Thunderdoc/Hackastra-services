<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Referral;
use App\Repositories\SettingRepo;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Referral plumbing (Round 5 Slice 4, P16) — Super Admin only.
 *
 * Two parts, both SA-side:
 *  - the programme settings: referral_code + referral_reward_text
 *    (stored as settings via SettingRepo::update / updateOrCreate).
 *    The programme is OFF while referral_reward_text is blank
 *    (Referral::programmeOn) — then no school-side surface renders.
 *  - the referral LOG: entries the SA records by hand at
 *    onboarding. No school-side submission exists.
 *
 * No reward is invented, computed or paid here: the reward text
 * and each entry's reward note are exactly what the SA typed.
 */
class ReferralController extends Controller
{
    protected $setting;

    public function __construct(SettingRepo $setting)
    {
        $this->middleware('super_admin');
        $this->setting = $setting;
    }

    public function index()
    {
        $d['referrals'] = Referral::with('creator')->latest()->paginate(25);
        $d['statuses'] = Referral::STATUSES;
        $d['referral_code'] = Referral::code();
        $d['referral_reward_text'] = Referral::rewardText();
        $d['programme_on'] = Referral::programmeOn();

        return view('pages.super_admin.referrals.index', $d);
    }

    public function store(Request $req)
    {
        $data = $req->validate([
            'referrer_name' => 'required|string|max:255',
            'referred_name' => 'required|string|max:255',
            'status' => 'required|in:' . implode(',', Referral::STATUSES),
            'reward_note' => 'nullable|string|max:2000',
        ]);
        $data['created_by'] = Auth::user()->id;

        $referral = Referral::create($data);

        Audit::log('referral.logged', $referral, 'Referral logged: ' . $referral->referrer_name . ' referred ' . $referral->referred_name . ' (' . $referral->status . ')');

        return redirect()->route('platform_referrals.index')
            ->with('flash_success', 'Referral entry added.');
    }

    public function updateStatus(Request $req, $referral)
    {
        $data = $req->validate([
            'status' => 'required|in:' . implode(',', Referral::STATUSES),
        ]);

        $entry = Referral::findOrFail($referral);
        $entry->update(['status' => $data['status']]);

        Audit::log('referral.status_updated', $entry, 'Referral status: ' . $entry->referrer_name . ' -> ' . $entry->referred_name . ' is now ' . $entry->status);

        return redirect()->route('platform_referrals.index')
            ->with('flash_success', 'Referral status updated.');
    }

    /** Save the programme settings (code as typed + reward text). */
    public function updateProgramme(Request $req)
    {
        $data = $req->validate([
            'referral_code' => 'nullable|string|max:50',
            'referral_reward_text' => 'nullable|string|max:500',
        ]);

        $this->setting->update('referral_code', trim((string) ($data['referral_code'] ?? '')));
        $this->setting->update('referral_reward_text', trim((string) ($data['referral_reward_text'] ?? '')));

        Audit::log('referral.programme_updated', null, 'Referral programme settings saved (programme ' . (Referral::programmeOn() ? 'on' : 'off') . ').');

        return redirect()->route('platform_referrals.index')
            ->with('flash_success', 'Referral programme settings saved.');
    }

    /** Replace the referral code with a fresh generated one. */
    public function regenerateCode()
    {
        $code = Referral::generateCode();
        $this->setting->update('referral_code', $code);

        Audit::log('referral.code_regenerated', null, 'Referral code regenerated: ' . $code);

        return redirect()->route('platform_referrals.index')
            ->with('flash_success', 'New referral code generated: ' . $code);
    }
}
