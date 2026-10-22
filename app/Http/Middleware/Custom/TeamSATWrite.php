<?php

namespace App\Http\Middleware\Custom;

use Closure;
use App\Helpers\Qs;
use Illuminate\Support\Facades\Auth;

class TeamSATWrite
{
    /**
     * School-write gate (Workstream CM): the read team minus the
     * Super Admin. SA may view the school but never write its records.
     */
    public function handle($request, Closure $next)
    {
        return (Auth::check() && Qs::userIsTeamSATWrite()) ? $next($request) : redirect()->route('login');
    }
}
