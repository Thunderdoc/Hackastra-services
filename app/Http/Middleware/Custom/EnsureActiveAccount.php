<?php

namespace App\Http\Middleware\Custom;

use Closure;
use Illuminate\Support\Facades\Auth;

class EnsureActiveAccount
{
    /**
     * Account lifecycle enforcement (Workstream DF). Runs on every
     * web request: if the signed-in account has been suspended or
     * terminated since it logged in, its session ends here — the
     * user is logged out, the session is invalidated, and they land
     * back on the login page with the reason. This is what makes a
     * lifecycle change take effect on the target's NEXT request
     * without needing access to their browser or session store
     * (the app uses the file session driver).
     *
     * A missing status attribute (database not yet migrated) is
     * treated as active — an un-migrated install never locks the
     * whole school out.
     */
    public function handle($request, Closure $next)
    {
        $user = $request->user();

        if ($user) {
            $status = $user->getAttribute('status');

            if ($status && $status !== 'active') {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                $message = $status === 'terminated'
                    ? 'This account has been terminated. Please contact the platform administrator.'
                    : 'This account has been suspended. Please contact the platform administrator.';

                return redirect()->route('login')->withErrors(['identity' => $message]);
            }
        }

        return $next($request);
    }
}
