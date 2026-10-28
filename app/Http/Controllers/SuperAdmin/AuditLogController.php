<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;

class AuditLogController extends Controller
{
    public function index()
    {
        $query = AuditLog::with('user')->latest();

        /* Workstream CM — audit visibility split (owner ruling): the
           Super Admin sees the whole platform trail. The school Admin
           sees the SCHOOL trail — every entry except those acted by a
           super_admin (the company's own activity stays platform-side).
           Actor-less entries (e.g. failed logins) are school security
           events and stay visible to the Admin. Round 4: the
           principal reads the SAME school trail as the Admin — the
           route was widened to principals without widening this
           scope, so principals briefly saw super_admin-acted rows. */
        if (Qs::userIsAdmin() || Qs::userIsPrincipal()) {
            $query->where(function ($q) {
                $q->whereNull('user_id')
                  ->orWhereHas('user', function ($u) {
                      $u->where('user_type', '!=', 'super_admin');
                  });
            });
        }

        $d['logs'] = $query->paginate(50);

        return view('pages.super_admin.audit_logs.index', $d);
    }
}
