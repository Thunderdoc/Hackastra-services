<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\AuditLabels;
use App\User;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    /**
     * The visibility scope (Workstream CM): SA sees the whole
     * platform trail; admin/principal see the school trail (no
     * super_admin-acted rows; actor-less rows included).
     */
    protected function scopedQuery()
    {
        $query = AuditLog::with('user')->latest();

        if (Qs::userIsAdmin() || Qs::userIsPrincipal()) {
            $query->where(function ($q) {
                $q->whereNull('user_id')
                  ->orWhereHas('user', function ($u) {
                      $u->where('user_type', '!=', 'super_admin');
                  });
            });
        }

        return $query;
    }

    /** Item 19: search / user / action / date-range filters. */
    protected function filteredQuery(Request $request)
    {
        $query = $this->scopedQuery();

        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('description', 'like', '%' . $q . '%')
                  ->orWhere('action', 'like', '%' . $q . '%')
                  ->orWhere('ip_address', 'like', '%' . $q . '%')
                  ->orWhereHas('user', function ($u) use ($q) {
                      $u->where('name', 'like', '%' . $q . '%');
                  });
            });
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->query('user_id'));
        }
        if ($request->filled('action')) {
            $query->where('action', $request->query('action'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->query('date_to'));
        }

        return $query;
    }

    /**
     * Distinct values of one column over a query, ORDER-FREE.
     *
     * The scoped/filtered queries carry latest() (ORDER BY
     * created_at). Cloning one and adding select()->distinct()
     * without dropping that order emits SELECT DISTINCT action …
     * ORDER BY created_at — MySQL/TiDB error 3065 ("ORDER BY
     * clause is not in SELECT list … incompatible with DISTINCT"),
     * which 500'd this page on the live TiDB while SQLite accepted
     * it (the /materials lesson). reorder() strips the order so the
     * SQL is portable; the caller sorts/filters in PHP if needed.
     */
    protected function distinctValues($query, string $column)
    {
        return (clone $query)->reorder()->select($column)->distinct()->pluck($column);
    }

    /** Item 19: group filter (quick pills) applied on top. */
    protected function applyGroup($query, ?string $group)
    {
        if (!$group) {
            return $query;
        }
        $actions = $this->distinctValues($query, 'action')
            ->filter(function ($a) use ($group) {
                return AuditLabels::groupOf($a) === $group;
            })->values()->all();
        return $query->whereIn('action', $actions ?: ['__none__']);
    }

    /** Resolve subject names in bulk for the page being rendered. */
    protected function subjectNames($logs): array
    {
        $wanted = [];
        foreach ($logs as $log) {
            if ($log->subject_type && $log->subject_id) {
                $wanted[$log->subject_type][$log->subject_id] = true;
            }
        }
        $names = [];
        foreach ($wanted as $type => $ids) {
            if (!class_exists($type)) {
                continue;
            }
            try {
                $rows = $type::whereIn('id', array_keys($ids))->get();
                foreach ($rows as $row) {
                    $name = $row->name ?? null;
                    if (!$name && $type === \App\Models\MyClass::class) {
                        $name = $row->name;
                    }
                    if (!$name && method_exists($row, 'user')) {
                        $name = optional($row->user)->name;
                    }
                    if ($name) {
                        $names[$type . '#' . $row->id] = $name;
                    }
                }
            } catch (\Throwable $e) {
                // A subject type the renderer cannot resolve (gone
                // class, non-model string, broken relation) must
                // never 500 the page — the blade falls back to
                // "ClassName #id" for the unresolved subject.
                continue;
            }
        }
        return $names;
    }

    public function index(Request $request)
    {
        $filtered = $this->filteredQuery($request);
        $group = $request->query('group');

        // Quick-pill counts describe the filtered set (scope +
        // search/user/action/dates), before the group is applied.
        $counts = [];
        foreach ($this->distinctValues($filtered, 'action') as $action) {
            $g = AuditLabels::groupOf($action);
            $counts[$g] = ($counts[$g] ?? 0) + (clone $filtered)->where('action', $action)->count();
        }

        $logs = $this->applyGroup($filtered, $group)
            ->paginate(15)
            ->appends($request->query());

        $d['logs'] = $logs;
        $d['subject_names'] = $this->subjectNames($logs->items());
        $d['group_counts'] = $counts;
        $d['active_group'] = $group;
        $d['filter_users'] = User::whereIn('id', $this->distinctValues($this->scopedQuery(), 'user_id')->filter())
            ->orderBy('name')->get(['id', 'name']);
        $d['filter_actions'] = $this->distinctValues($this->scopedQuery(), 'action')->sort()->values();
        $d['filters'] = $request->only(['q', 'user_id', 'action', 'date_from', 'date_to']);

        return view('pages.super_admin.audit_logs.index', $d);
    }

    /** Item 19: CSV export of the currently filtered set. */
    public function export(Request $request)
    {
        $logs = $this->applyGroup($this->filteredQuery($request), $request->query('group'))->get();
        $names = $this->subjectNames($logs);

        $filename = 'audit-log-' . now()->format('Ymd-Hi') . '.csv';
        return response()->streamDownload(function () use ($logs, $names) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['When', 'User', 'Action', 'Subject', 'Details', 'IP']);
            foreach ($logs as $log) {
                $subject = '—';
                if ($log->subject_type && $log->subject_id) {
                    $subject = $names[$log->subject_type . '#' . $log->subject_id]
                        ?? class_basename($log->subject_type) . ' #' . $log->subject_id;
                }
                fputcsv($out, [
                    $log->created_at ? $log->created_at->format('d M Y, h:i A') : '',
                    optional($log->user)->name ?: '—',
                    AuditLabels::label($log->action),
                    $subject,
                    $log->description ?: '',
                    $log->ip_address ?: '',
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
