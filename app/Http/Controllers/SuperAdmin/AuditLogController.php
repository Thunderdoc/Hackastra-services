<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;

class AuditLogController extends Controller
{
    public function index()
    {
        $d['logs'] = AuditLog::with('user')->latest()->paginate(50);

        return view('pages.super_admin.audit_logs.index', $d);
    }
}
