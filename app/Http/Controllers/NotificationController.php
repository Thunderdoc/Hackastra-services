<?php

namespace App\Http\Controllers;

use App\Models\UserNotification;
use App\Support\Notify;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    /**
     * The full notifications page (owner order 2026-10-30:
     * notifications done properly) — everything the bell has
     * ever brought this user, newest first, unread marked, with
     * an explicit Mark all read. The bell dropdown only ever
     * shows the latest few; this is the whole list.
     */
    public function index(Request $request)
    {
        $userId = (int) Auth::id();
        $onlyUnread = $request->input('filter') === 'unread';

        $query = UserNotification::where('user_id', $userId)->latest();
        if ($onlyUnread) {
            $query->whereNull('read_at');
        }

        $d['notifications'] = $query->take(100)->get();
        $d['unread_count'] = Notify::unreadCount($userId);
        $d['only_unread'] = $onlyUnread;

        return view('pages.notifications.index', $d);
    }

    /** Bell opened (or Mark all read pressed): clear the caller's unread badge. */
    public function markRead(Request $request)
    {
        Notify::markAllRead(Auth::id());

        // The notifications page's form asks for a redirect back;
        // the bell's script (and every other caller) keeps the
        // original JSON contract.
        if ($request->boolean('redirect')) {
            return back()->with('flash_success', 'All notifications marked as read.');
        }

        return response()->json(['ok' => true]);
    }
}
