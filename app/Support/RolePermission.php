<?php

namespace App\Support;

use App\Models\RolePermission as Grant;

/**
 * Custom permission grants (2026-10-28, Wave 3 add-on).
 *
 * A grant layer ON TOP OF the fixed user_type system — it only ADDS
 * access, never removes base rules and never widens older modules or
 * menus. The base rule stays in the guarded controller, e.g.:
 *
 *   if (!Qs::userIsAdmin() && !RolePermission::allows($user, 'electives.approve')) {
 *       abort(403);
 *   }
 *
 * allows() answers ONLY "does this user hold this grant?" — it does
 * NOT return true for admins. An admin passes through the admin
 * branch of the guard, not through a grant, so removing a grant
 * restores the base rule exactly.
 *
 * Whitelist: the ONLY grantable keys. Anything else — including any
 * key that would touch grant administration itself (role_permissions.*,
 * grants.*, permissions.*) — is refused by store validation AND by
 * allows() returning false. The grants UI is therefore ADMIN ONLY and
 * is NEVER itself grantable: granting grant-management would be
 * privilege escalation.
 */
class RolePermission
{
    /** Small fixed whitelist of Wave 3 admin-only actions. */
    public const PERMISSIONS = [
        'electives.approve',
        'electives.reject',
        'documents.destroy',
    ];

    public const LABELS = [
        'electives.approve' => 'Approve elective choices',
        'electives.reject' => 'Reject elective choices',
        'documents.destroy' => 'Delete student documents',
    ];

    public static function isGrantable(string $key): bool
    {
        return in_array($key, self::PERMISSIONS, true);
    }

    public static function label(string $key): string
    {
        return self::LABELS[$key] ?? $key;
    }

    /**
     * True only when $user holds a row for $key. Null user, unknown
     * key, and admin-without-grant all return false.
     */
    public static function allows($user, string $key): bool
    {
        if (!$user || !self::isGrantable($key)) {
            return false;
        }

        return Grant::where('user_id', $user->id)
            ->where('permission', $key)
            ->exists();
    }
}
