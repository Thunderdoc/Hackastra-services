<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\SchoolLicence;
use App\Support\Audit;
use Illuminate\Http\Request;

/**
 * Licence record management (Round 5 Slice 4, P14) — Super
 * Admin only. The SA maintains the single current licence
 * record for this school (plan name exactly as sold, status,
 * dates, notes). There are NO pricing fields and nothing in
 * the app enforces or gates on this record; the school Admin
 * sees it read-only on their dashboard.
 */
class LicenceController extends Controller
{
    public function __construct()
    {
        $this->middleware('super_admin');
    }

    public function edit()
    {
        $d['licence'] = SchoolLicence::current();
        $d['statuses'] = SchoolLicence::STATUSES;

        return view('pages.super_admin.licence.edit', $d);
    }

    public function update(Request $req)
    {
        $data = $req->validate([
            'plan_name' => 'required|string|max:100',
            'status' => 'required|in:' . implode(',', SchoolLicence::STATUSES),
            'starts_on' => 'nullable|date',
            'renews_on' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',
        ]);

        $licence = SchoolLicence::current();
        if ($licence) {
            $licence->update($data);
            $action = 'licence.updated';
        } else {
            $licence = SchoolLicence::create($data);
            $action = 'licence.created';
        }

        Audit::log($action, $licence, 'Licence record: ' . $licence->plan_name . ' (' . $licence->status . ')');

        return redirect()->route('platform_licence.edit')
            ->with('flash_success', 'Licence record saved.');
    }
}
