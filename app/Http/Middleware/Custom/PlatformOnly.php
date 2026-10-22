<?php

namespace App\Http\Middleware\Custom;

use Closure;
use App\Helpers\Qs;
use Illuminate\Support\Facades\Auth;

/**
 * Platform-only boundary for the Super Admin (owner ruling
 * 2026-10-22 13:04 IST, Workstream DD re-scope — supersedes the
 * view-only model): the Super Admin is the platform/company
 * account. It may use the Platform Overview, the Users module
 * (platform accounts), the Audit Log, its own My Account pages and
 * the auth scaffolding — and nothing from the school domain. The
 * school is run by the school Admin.
 *
 * This is the single choke point: appended to the web middleware
 * group (so the session, and therefore the signed-in user, is
 * available), it consults an allowlist of route names for every
 * authenticated super_admin request. School-page GETs redirect to
 * the Platform Overview with a notice; non-GET school requests get
 * a hard 403. CM's write-team gates stay in place untouched as the
 * boundary for Admin/staff and a second wall.
 */
class PlatformOnly
{
    /** Route names the Super Admin may use. Everything else is school domain. */
    protected $allowed = [
        // Platform Overview (its named URL spellings; the unnamed
        // GET /home spelling is recognised by action in handle()).
        'home', 'dashboard',
        // Platform users module (CM's cross-boundary account rules
        // still apply inside UserController).
        'users.index', 'users.create', 'users.store', 'users.show',
        'users.edit', 'users.update', 'users.destroy', 'users.reset_pass',
        // Platform audit log.
        'audit_logs.index',
        // Own account pages.
        'my_account', 'my_account.update', 'my_account.change_pass',
        'my_account.preferences', 'my_account.theme', 'my_account.download',
        // Topbar search endpoint — DashboardSearchController scopes
        // Super Admin results to platform accounts only.
        'dashboard.search',
        // Location helper used by the Users module's own forms
        // (state -> LGA select); returns location lists, not school data.
        'get_lga',
        // Auth scaffolding + public pages.
        'login', 'logout', 'register',
        'password.request', 'password.email', 'password.reset',
        'password.update', 'password.confirm',
        'privacy_policy', 'terms_of_use',
    ];

    public function handle($request, Closure $next)
    {
        if (!Auth::check() || !Qs::userIsSuperAdmin()) {
            return $next($request);
        }

        $route = $request->route();
        $name = $route ? $route->getName() : null;

        // The unnamed GET /home spelling routes to the same
        // HomeController@dashboard platform overview.
        if ($name === null && $route
            && $route->getActionName() === 'App\Http\Controllers\HomeController@dashboard') {
            return $next($request);
        }

        if ($name !== null && in_array($name, $this->allowed, true)) {
            return $next($request);
        }

        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            return redirect()->route('dashboard')
                ->with('flash_danger', 'That area belongs to the school. School data is managed by the school Admin — the Super Admin workspace is the platform.');
        }

        abort(403, 'The Super Admin is a platform account; school records are managed by the school Admin.');
    }
}
