<?php

namespace App\Http\Controllers;

use App\Support\Notify;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    /** Bell opened: clear the caller's unread notification badge. */
    public function markRead()
    {
        Notify::markAllRead(Auth::id());

        return response()->json(['ok' => true]);
    }
}
