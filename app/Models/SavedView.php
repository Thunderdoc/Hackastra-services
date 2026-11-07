<?php

namespace App\Models;

use App\User;
use Eloquent;

/**
 * A saved filter set for one operational list (Round 5 Slice 3,
 * P12) — the ReportTemplate pattern ported to list filters.
 * Views are owner-private: a row belongs to exactly one user and
 * only that user may apply or delete it (SavedViewController
 * answers 404 for anyone else's row, like ReportsController's
 * ownTemplate guard).
 *
 * LISTS is the single whitelist source: for each list_key it
 * names the route apply() redirects to and the exact GET params
 * a view may carry. store() filters the request down to these
 * keys (scalars only), so a crafted POST can never smuggle an
 * extra param into a redirect. The whitelists were verified
 * against each list's controller:
 *  - students: StudentRecordController@listByClass honours
 *    section_id / q / sort; class_id is the list route's own
 *    path parameter (apply() lifts it back into the path).
 *  - users: UserController@filteredCollection honours
 *    user_type / status / q / sort / dir.
 *  - defaulters: FeeReportController@defaulterFilters honours
 *    section_id / status / q; class_id scopes the page itself.
 *  - audit: AuditLogController@filteredQuery honours q /
 *    user_id / action / date_from / date_to, and its index
 *    additionally honours the group quick-pill.
 */
class SavedView extends Eloquent
{
    protected $fillable = ['user_id', 'list_key', 'name', 'params'];

    protected $casts = ['params' => 'array'];

    const LISTS = [
        'students' => [
            'route' => 'students.list',
            'params' => ['class_id', 'section_id', 'q', 'sort'],
        ],
        'users' => [
            'route' => 'users.index',
            'params' => ['user_type', 'status', 'q', 'sort', 'dir'],
        ],
        'defaulters' => [
            'route' => 'payments.defaulters',
            'params' => ['class_id', 'section_id', 'status', 'q'],
        ],
        'audit' => [
            'route' => 'audit_logs.index',
            'params' => ['q', 'user_id', 'action', 'date_from', 'date_to', 'group'],
        ],
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Reduce arbitrary input to the list's whitelisted, scalar,
     * non-empty params — the only shape a stored view may hold.
     */
    public static function filterParams(string $listKey, array $input): array
    {
        $whitelist = self::LISTS[$listKey]['params'] ?? [];
        $params = [];
        foreach ($whitelist as $key) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $value = $input[$key];
            if (!is_scalar($value)) {
                continue;
            }
            $value = trim((string) $value);
            if ($value !== '') {
                $params[$key] = $value;
            }
        }

        return $params;
    }
}
