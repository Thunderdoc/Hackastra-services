<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Models\RolePermission;
use App\Support\RolePermission as Grants;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Custom Roles / Permissions — grant administration (2026-10-28).
 *
 * Controller-guarded (routes carry no team middleware): every action
 * aborts 403 unless the caller is an admin (Qs::userIsAdmin()).
 * ADMIN ONLY, and grant-management itself is NEVER grantable: the
 * permission whitelist in App\Support\RolePermission contains no
 * role_permissions.* key, so no grant can ever open this UI.
 *
 * A grant does NOT add menu items and does NOT widen any older
 * module: the grantee reaches a granted action by direct route only,
 * and only the whitelisted Wave 3 actions check grants at all.
 * Base rules are never weakened — deleting a grant restores them.
 */
class RolePermissionController extends Controller
{
    protected function requireAdmin(): void
    {
        if (!Qs::userIsAdmin()) {
            abort(403);
        }
    }

    public function index()
    {
        $this->requireAdmin();

        $d['grants'] = RolePermission::with(['user', 'granter'])
            ->orderBy('created_at', 'desc')
            ->get();

        return view('pages.support_team.role_permissions.index', $d);
    }

    public function create()
    {
        $this->requireAdmin();

        // Staff picker only (students/parents hold no grantable
        // staff action); store() still validates any user id, so the
        // dropdown is presentation, not the security check.
        $d['users'] = User::whereNotIn('user_type', ['student', 'parent'])
            ->orderBy('name')
            ->get();
        $d['permissions'] = Grants::PERMISSIONS;

        return view('pages.support_team.role_permissions.create', $d);
    }

    public function store(Request $request)
    {
        $this->requireAdmin();

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            // Whitelist only: any key outside it — including anything
            // resembling grant administration — fails validation.
            'permission' => ['required', Rule::in(Grants::PERMISSIONS)],
        ]);

        if (RolePermission::where('user_id', $validated['user_id'])
            ->where('permission', $validated['permission'])
            ->exists()) {
            return back()->withInput()
                ->with('flash_danger', 'That grant already exists for this user.');
        }

        RolePermission::create([
            'user_id' => $validated['user_id'],
            'permission' => $validated['permission'],
            'granted_by' => Auth::id(),
        ]);

        return redirect()->route('role_permissions.index')
            ->with('flash_success', 'Grant added: ' . Grants::label($validated['permission']) . '.');
    }

    public function destroy(RolePermission $grant)
    {
        $this->requireAdmin();

        $grant->delete();

        return redirect()->route('role_permissions.index')
            ->with('flash_success', 'Grant revoked. The base rule applies again.');
    }
}
