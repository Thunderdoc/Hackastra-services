<?php

namespace App\Http\Controllers\SupportTeam;

use App\Http\Controllers\Controller;
use App\Models\SavedView;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Round 5 Slice 3 (P12) — saved views for the operational lists.
 *
 * A view is owner-private (the ReportTemplate discipline):
 * store() writes only for the signed-in user, and apply() /
 * destroy() answer 404 for anyone else's row, so a view's
 * existence never leaks across accounts. The params a view may
 * carry come from the single whitelist on the SavedView model;
 * anything outside it is dropped at store time, never applied.
 * Routes live in the auth group (routes/web.php, applied by the
 * coordinator) — no extra role gate: a saved view only ever
 * redirects its owner to a list that list's own gate controls.
 */
class SavedViewController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'list_key' => 'required|string|in:' . implode(',', array_keys(SavedView::LISTS)),
            'name' => 'required|string|max:100',
        ]);

        SavedView::create([
            'user_id' => Auth::id(),
            'list_key' => $data['list_key'],
            'name' => trim($data['name']),
            'params' => SavedView::filterParams($data['list_key'], $request->all()),
        ]);

        return back()->with('flash_success', 'View saved — it is in the Saved views list on this page.');
    }

    public function apply(SavedView $saved_view)
    {
        $this->ownOr404($saved_view);

        $def = SavedView::LISTS[$saved_view->list_key] ?? null;
        if (!$def) {
            abort(404);
        }

        $params = is_array($saved_view->params) ? $saved_view->params : [];

        /* The students list takes its class as a PATH parameter
           (students.list/{class_id?}), not a query param — lift a
           stored class_id back into the path so the redirect
           lands on the same class the view was saved from. */
        if ($saved_view->list_key === 'students' && !empty($params['class_id'])) {
            $classId = $params['class_id'];
            unset($params['class_id']);
            return redirect()->route($def['route'], array_merge(['class_id' => $classId], $params));
        }

        return redirect()->route($def['route'], $params);
    }

    public function destroy(SavedView $saved_view)
    {
        $this->ownOr404($saved_view);

        $saved_view->delete();

        return back()->with('flash_success', 'Saved view deleted.');
    }

    /** Owner-private rows: another user's view reads as missing. */
    protected function ownOr404(SavedView $savedView): void
    {
        if ((int) $savedView->user_id !== (int) Auth::id()) {
            abort(404);
        }
    }
}
