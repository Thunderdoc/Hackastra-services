<?php

namespace App\Http\Middleware\Custom;

use Closure;
use App\Helpers\Qs;
use Illuminate\Support\Facades\Auth;

/**
 * Principal read gates (owner order 2026-10-28, limited principal
 * role): a route that a team may read is also readable by the
 * principal, whose powers are read + designated approvals only.
 * The $team parameter selects the base team (SA, SAT, SAWrite,
 * Account); writes stay on their original middleware and are
 * never opened here, so a read opening cannot become a write.
 */
class TeamOrPrincipal
{
    public function handle($request, Closure $next, $team = 'SA')
    {
        $base = false;
        switch ($team) {
            case 'SA':
                $base = Qs::userIsTeamSA();
                break;
            case 'SAT':
                $base = Qs::userIsTeamSAT();
                break;
            case 'SAWrite':
                $base = Qs::userIsTeamSAWrite();
                break;
            case 'Account':
                $base = Qs::userIsTeamAccount();
                break;
        }

        return (Auth::check() && ($base || Qs::userIsPrincipal())) ? $next($request) : redirect()->route('login');
    }
}
