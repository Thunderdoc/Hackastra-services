<?php

namespace App\Support;

/**
 * Item 19 (2026-11-01): plain-English labels + groups for audit
 * action codes. Codes stay stored as-is; this maps at render.
 * Unknown codes fall back to a humanised form so a new action
 * never shows as a raw code.
 */
class AuditLabels
{
    /** Explicit labels for the codes the app logs most. */
    protected static $labels = [
        'auth.login' => 'Signed in',
        'auth.failed' => 'Failed sign-in attempt',
        'auth.logout' => 'Signed out',
        'marks.submitted' => 'Marks submitted',
        'marks.saved' => 'Marks saved',
        'marks.updated' => 'Marks updated',
        'marks.reopened' => 'Marks reopened',
        'marks.published' => 'Results published',
        'report.exported' => 'Report exported',
        'report.template_saved' => 'Report template saved',
        'report.template_deleted' => 'Report template deleted',
        'substitution.plan_submitted' => 'Substitution plan sent',
        'circular.published' => 'Circular published',
        'settings.updated' => 'Settings updated',
        'leave.requested' => 'Leave requested',
        'leave.approved' => 'Leave approved',
        'leave.rejected' => 'Leave rejected',
        'leave.decided' => 'Leave decided',
        'account.data_downloaded' => 'Account data downloaded',
        'account.preferences_updated' => 'Preferences updated',
    ];

    /** Group key => [pill label, pill tone]. Order = pill order. */
    public static $groups = [
        'auth' => ['Logins', 'login'],
        'marks' => ['Marks', 'marks'],
        'report' => ['Reports', 'report'],
        'substitution' => ['Substitutions', 'sub'],
        'circular' => ['Circulars', 'report'],
        'settings' => ['Settings', 'login'],
        'leave' => ['Leave', 'sub'],
        'exams' => ['Exams', 'marks'],
        'assignment' => ['Assignments', 'sub'],
        'payment' => ['Payments', 'report'],
        'fee_structure' => ['Fees', 'report'],
        'expense' => ['Expenses', 'report'],
        'user' => ['Users', 'login'],
        'account' => ['Account', 'login'],
        'ai' => ['AI', 'sub'],
        'bulk' => ['Bulk actions', 'marks'],
        'feedback' => ['Feedback', 'sub'],
        'hostel' => ['Hostel', 'sub'],
        'health_record' => ['Health records', 'sub'],
        'discipline_record' => ['Discipline', 'sub'],
    ];

    public static function label(string $action): string
    {
        if (isset(self::$labels[$action])) {
            return self::$labels[$action];
        }
        $words = str_replace(['.', '_'], ' ', $action);
        return ucfirst(trim($words));
    }

    public static function groupOf(string $action): string
    {
        foreach (['fee_structure', 'health_record', 'discipline_record'] as $multi) {
            if (strpos($action, $multi) === 0) {
                return $multi;
            }
        }
        $pos = strpos($action, '.');
        $key = $pos === false ? $action : substr($action, 0, $pos);
        // substitution.plan_submitted style: segment before '_' too.
        $us = strpos($key, '_');
        if ($us !== false) {
            $key = substr($key, 0, $us);
        }
        return isset(self::$groups[$key]) ? $key : 'other';
    }

    public static function groupLabel(string $key): string
    {
        return self::$groups[$key][0] ?? 'Other';
    }

    public static function tone(string $action): string
    {
        $key = self::groupOf($action);
        return self::$groups[$key][1] ?? 'login';
    }
}
