<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Http\Requests\FeedbackSave;
use App\Models\FeedbackItem;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Feedback & issue flow (Workstream CW, owner spec 2026-10-22):
 *
 *   everyone in the school ──reports──▶ school Admin inbox
 *   Admin ──dedupes (merge duplicates) + compiles──▶ forwards
 *   Super Admin ──sees ONLY forwarded items──▶ replies / fixes
 *   reporter ──watches live status + the SA reply in My Feedback
 *
 * The Admin is the filter: the Super Admin never sees raw school
 * feedback, only what the Admin forwarded. Access is enforced per
 * action below (role checks in the controller, mirroring the way
 * CircularController splits its audiences).
 *
 * Owner ruling 2026-10-25 (multi-school, standing): feedback is
 * PER-SCHOOL and is never merged across schools. Today the app runs
 * one school so there is nothing to mix; when the SaaS school_id
 * lands, every feedback row, list, forward and SA reply MUST scope
 * to the originating school, and each school gets its own separate
 * feedback thread back. Merging happens only inside one school
 * (the Admin's pre-forward dedupe via duplicate_of_id).
 *
 * Owner ruling 2026-10-25 (Wave 16, ticket close/reopen): once the
 * SA closes a report (Fixed), the SA cannot reopen it — the detail
 * control is disabled and saUpdate refuses the change server-side.
 * Only the school Admin can reopen it (feedback.reopen), which puts
 * it back to Received on the SA board (re-escalation). Replies on a
 * closed report still work through the detail page.
 */
class FeedbackController extends Controller
{
    /* ----------------------------------------------------------------
     | Reporter side — every signed-in role
     | ---------------------------------------------------------------- */

    public function create()
    {
        $d['categories'] = FeedbackItem::categories();
        $d['types'] = FeedbackItem::types();
        $d['priorities'] = FeedbackItem::priorities();

        return view('pages.support_team.feedback.create', $d);
    }

    public function store(FeedbackSave $request)
    {
        $data = $request->validated();
        $user = Auth::user();

        $item = new FeedbackItem();
        $item->reporter_id = $user->id;
        $item->reporter_name = $user->name;
        $item->reporter_role = $user->user_type;
        $item->type = $data['type'];
        $item->category = $data['category'];
        $item->priority = $data['priority'];
        $item->title = $data['title'];
        $item->description = $data['description'];
        $item->context = $data['context'] ?? null;
        $item->status = 'new';

        // Auto-log (the reporter never types these): the page the
        // report came from — the form's hidden field carries the
        // ?from= deep-link target or the referring page; only its
        // path+query is kept — plus the browser/device string.
        $item->page_url = $this->cleanPageUrl($data['page_url'] ?? null);
        $item->user_agent = Str::limit((string) $request->userAgent(), 500, '');

        $item->save();

        foreach ($request->file('attachments', []) as $file) {
            if (!$file) {
                continue;
            }
            $f = Qs::getFileMetaData($file);
            $name = 'feedback_' . $item->id . '_' . Str::random(8) . '.' . $f['ext'];
            // Explicit public disk (same as materials): the attachments
            // are served via asset('storage/...'), so they must land on
            // the public disk whatever the default disk is set to.
            $path = $file->storeAs(Qs::getPublicUploadPath() . 'feedback', $name, 'public');
            $item->attachments()->create([
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime' => $file->getMimeType(),
            ]);
        }

        return redirect()->route('feedback.mine')
            ->with('flash_success', 'Thank you — your feedback has been sent to the school Admin.');
    }

    /** Keep only path+query of the reported-from URL (no host tricks). */
    protected function cleanPageUrl(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['path'])) {
            return null;
        }

        $clean = $parts['path'] . (!empty($parts['query']) ? '?' . $parts['query'] : '');

        return Str::limit($clean, 1024, '');
    }

    /** My Feedback: the reporter's own items, with live status. */
    public function mine(Request $request)
    {
        $user = Auth::user();

        // Per-status counts over ALL of the reporter's own items —
        // they drive the summary cards and the status tabs, whatever
        // filter is currently applied to the list below.
        $d['counts'] = FeedbackItem::where('reporter_id', $user->id)
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')->pluck('c', 'status');
        $d['total'] = (int) $d['counts']->sum();

        $query = FeedbackItem::with(['duplicateOf', 'attachments'])
            ->where('reporter_id', $user->id);

        // Status tab — only a real workflow status may filter.
        $d['active_status'] = '';
        if ($request->filled('status') && array_key_exists($request->input('status'), FeedbackItem::statuses())) {
            $d['active_status'] = $request->input('status');
            $query->where('status', $d['active_status']);
        }

        // Search over the reporter's own titles/descriptions only.
        $d['q'] = trim((string) $request->input('q', ''));
        if ($d['q'] !== '') {
            $q = $d['q'];
            $query->where(function ($w) use ($q) {
                $w->where('title', 'like', '%' . $q . '%')
                    ->orWhere('description', 'like', '%' . $q . '%');
            });
        }

        $d['sort'] = $request->input('sort') === 'oldest' ? 'oldest' : 'newest';
        $query = $d['sort'] === 'oldest' ? $query->oldest() : $query->latest();

        $d['items'] = $query->paginate(15)->appends($request->except('page'));

        // Opening My Feedback marks everything as seen (bell hook).
        FeedbackItem::where('reporter_id', $user->id)
            ->update(['reporter_seen_at' => now()]);

        $d['statuses'] = FeedbackItem::statuses();
        $d['sa_statuses'] = FeedbackItem::saStatuses();

        return view('pages.support_team.feedback.mine', $d);
    }

    public function showMine(FeedbackItem $feedback)
    {
        if ($feedback->reporter_id !== Auth::id()) {
            abort(403);
        }

        $feedback->update(['reporter_seen_at' => now()]);

        $d['item'] = $feedback->load(['reporter', 'duplicateOf', 'duplicates', 'attachments']);
        $d['statuses'] = FeedbackItem::statuses();
        $d['sa_statuses'] = FeedbackItem::saStatuses();

        return view('pages.support_team.feedback.show_mine', $d);
    }

    /* ----------------------------------------------------------------
     | School Admin inbox
     | ---------------------------------------------------------------- */

    public function index(Request $request)
    {
        $this->mustBeAdmin();

        $query = FeedbackItem::with('reporter')->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }
        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }
        if ($request->filled('priority')) {
            $query->where('priority', $request->input('priority'));
        }

        $d['items'] = $query->paginate(15)->appends($request->except('page'));
        $d['categories'] = FeedbackItem::categories();
        $d['types'] = FeedbackItem::types();
        $d['priorities'] = FeedbackItem::priorities();
        $d['statuses'] = FeedbackItem::statuses();
        $d['counts'] = FeedbackItem::selectRaw('status, count(*) as c')
            ->groupBy('status')->pluck('c', 'status');

        return view('pages.support_team.feedback.index', $d);
    }

    public function show(FeedbackItem $feedback)
    {
        $this->mustBeAdmin();

        $d['item'] = $feedback->load(['reporter', 'duplicateOf', 'duplicates.reporter', 'forwarder', 'attachments']);
        $d['statuses'] = FeedbackItem::adminSettableStatuses();
        // Merge candidates: canonical items (never duplicates themselves),
        // same category first, excluding the item being merged.
        $d['candidates'] = FeedbackItem::with('reporter')
            ->whereNull('duplicate_of_id')
            ->where('id', '<>', $feedback->id)
            ->orderByRaw('case when category = ? then 0 else 1 end', [$feedback->category])
            ->latest()
            ->take(50)
            ->get();
        $d['sa_statuses'] = FeedbackItem::saStatuses();

        return view('pages.support_team.feedback.show', $d);
    }

    /** Admin sets the school-side status and/or an internal note. */
    public function update(Request $request, FeedbackItem $feedback)
    {
        $this->mustBeAdmin();

        $data = $request->validate([
            'status' => 'required|in:' . implode(',', array_keys(FeedbackItem::adminSettableStatuses())),
            'admin_note' => 'nullable|string|max:2000',
        ]);

        $feedback->update($data);

        return redirect()->route('feedback.show', $feedback->id)
            ->with('flash_success', 'Feedback updated.');
    }

    /** Merge this report into a canonical one (Admin dedupe step). */
    public function markDuplicate(Request $request, FeedbackItem $feedback)
    {
        $this->mustBeAdmin();

        $data = $request->validate([
            'duplicate_of_id' => 'required|integer|exists:feedback_items,id',
        ]);

        $canonical = FeedbackItem::findOrFail($data['duplicate_of_id']);

        if ($canonical->id === $feedback->id || $canonical->isDuplicate() || $feedback->isForwarded()) {
            return back()->with('pop_error', 'That item cannot be the canonical report for this merge.');
        }

        $feedback->update([
            'status' => 'duplicate',
            'duplicate_of_id' => $canonical->id,
        ]);

        Audit::log('feedback.merged_duplicate', $feedback,
            'Merged feedback #' . $feedback->id . ' ("' . $feedback->title . '") as duplicate of #' . $canonical->id);

        return redirect()->route('feedback.show', $canonical->id)
            ->with('flash_success', 'Report merged as a duplicate of #' . $canonical->id . '.');
    }

    /**
     * Compile & forward — PLATFORM ISSUES ONLY (owner redesign
     * 2026-10-28): the Super Admin sees only app glitches, UI/UX
     * problems and the like; everything else is sorted by the
     * school alone and never leaves the inbox. An item forwards
     * when its category is App / Software Issue, or when the Admin
     * explicitly attests it is a platform issue (platform_issue
     * flag — covers reports filed under the wrong category).
     * Accepts ids[] (bulk select on the inbox) or a single id
     * (the Forward button on the detail page). Duplicates and items
     * already forwarded are skipped — dedupe happens BEFORE forward.
     */
    public function forward(Request $request)
    {
        $this->mustBeAdmin();

        $ids = $request->input('ids', []);
        if ($request->filled('id')) {
            $ids[] = $request->input('id');
        }

        $ids = array_unique(array_map('intval', (array) $ids));
        if (!$ids) {
            return back()->with('pop_error', 'Select at least one report to forward.');
        }

        $platformAttested = $request->boolean('platform_issue');

        $items = FeedbackItem::whereIn('id', $ids)
            ->whereNull('duplicate_of_id')
            ->whereNull('forwarded_at')
            ->get();

        $forwardable = $items->filter(function ($item) use ($platformAttested) {
            return $item->category === 'app_bug' || $platformAttested;
        });

        foreach ($forwardable as $item) {
            $item->update([
                'forwarded_at' => now(),
                'forwarded_by' => Auth::id(),
                'sa_status' => 'received',
            ]);

            Audit::log('feedback.forwarded', $item,
                'Forwarded feedback #' . $item->id . ' ("' . $item->title . '", ' . $item->categoryLabel() . ') to Super Admin');
        }

        $skippedDedupe = count($ids) - $items->count();
        $skippedPlatform = $items->count() - $forwardable->count();
        $msg = $forwardable->count() . ' item' . ($forwardable->count() === 1 ? '' : 's') . ' forwarded to the Super Admin.';
        if ($skippedPlatform > 0) {
            $msg .= ' ' . $skippedPlatform . ' skipped (not a platform issue — the school handles those).';
        }
        if ($skippedDedupe > 0) {
            $msg .= ' ' . $skippedDedupe . ' skipped (duplicate or already forwarded).';
        }

        return back()->with('flash_success', $msg);
    }

    /* ----------------------------------------------------------------
     | Super Admin inbox — forwarded items ONLY
     | ---------------------------------------------------------------- */

    public function saIndex(Request $request)
    {
        $this->mustBeSuperAdmin();

        $query = FeedbackItem::with('reporter')->forwarded()->latest('forwarded_at');

        if ($request->filled('sa_status')) {
            $query->where('sa_status', $request->input('sa_status'));
        }
        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }
        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }
        if ($request->filled('priority')) {
            $query->where('priority', $request->input('priority'));
        }

        $d['items'] = $query->paginate(15)->appends($request->except('page'));
        $d['categories'] = FeedbackItem::categories();
        $d['types'] = FeedbackItem::types();
        $d['priorities'] = FeedbackItem::priorities();
        $d['sa_statuses'] = FeedbackItem::saStatuses();
        $d['summary'] = [
            'total' => FeedbackItem::forwarded()->count(),
            'by_status' => FeedbackItem::forwarded()
                ->selectRaw('sa_status, count(*) as c')
                ->groupBy('sa_status')->pluck('c', 'sa_status'),
            'duplicates_merged' => FeedbackItem::where('status', 'duplicate')->count(),
        ];

        return view('pages.support_team.feedback.sa_index', $d);
    }

    public function saShow(FeedbackItem $feedback)
    {
        $this->mustBeSuperAdmin();
        $this->mustBeForwarded($feedback);

        $d['item'] = $feedback->load(['reporter', 'duplicates.reporter', 'forwarder', 'attachments']);
        $d['sa_statuses'] = FeedbackItem::saStatuses();
        $d['statuses'] = FeedbackItem::statuses();

        return view('pages.support_team.feedback.sa_show', $d);
    }

    /** SA records the handling status + a platform-side note.
     *  Owner redesign 2026-10-28: the note is NEVER shown to the
     *  reporter — they see only the school-side status. When the SA
     *  newly marks an item Fixed, the school side auto-resolves
     *  with it, so the reporter reads "Resolved" with no Super
     *  Admin content at all.
     *  Wave 16: a closed (Fixed) report cannot be reopened by the SA
     *  — changing its status away from Fixed is refused here even if
     *  the disabled UI control is bypassed. Saving a note on a
     *  closed report is still allowed (notes go through the detail
     *  page by design). */
    public function saUpdate(Request $request, FeedbackItem $feedback)
    {
        $this->mustBeSuperAdmin();
        $this->mustBeForwarded($feedback);

        $data = $request->validate([
            'sa_status' => 'required|in:' . implode(',', array_keys(FeedbackItem::saStatuses())),
            'sa_reply' => 'nullable|string|max:2000',
        ]);

        if ($feedback->isClosedForSa() && $data['sa_status'] !== 'fixed') {
            return back()->with('pop_error', 'This report is closed — the Super Admin cannot reopen it. Only the school Admin can reopen it from the school side.');
        }

        $wasFixed = $feedback->isClosedForSa();
        if (!$wasFixed && $data['sa_status'] === 'fixed') {
            $data['status'] = 'resolved';
        }

        $feedback->update($data);

        Audit::log('feedback.sa_updated', $feedback,
            'Super Admin set feedback #' . $feedback->id . ' to "' . $feedback->saStatusLabel() . '"'
            . (($data['sa_reply'] ?? null) ? ' with a platform-side note' : '')
            . (!$wasFixed && $data['sa_status'] === 'fixed' ? '; school status auto-resolved for the reporter' : ''));

        return redirect()->route('feedback.sa_show', $feedback->id)
            ->with('flash_success', 'Saved — the reporter sees only the school-side status.');
    }

    /**
     * Owner ask 2026-10-25: closing reports one by one on the detail
     * page is a long manual process. From the list, the SA ticks
     * reports (or one row's Close button) and closes them all at once
     * — marked Fixed, no detail page needed. A reply still needs the
     * detail page, because a reply is per-report words. Only FORWARDED
     * items ever close here: the SA never touches raw school feedback,
     * so ids that are not forwarded are silently ignored, never closed.
     */
    public function saBulkClose(Request $request)
    {
        $this->mustBeSuperAdmin();

        $ids = collect($request->input('ids', []))
            ->map(function ($id) { return (int) $id; })
            ->filter(function ($id) { return $id > 0; })
            ->unique()->values();

        if ($ids->isEmpty()) {
            return redirect()->route('feedback.sa_index')
                ->with('flash_danger', 'Select at least one report to close.');
        }

        $items = FeedbackItem::forwarded()->whereIn('id', $ids)->get();
        $alreadyClosed = $items->filter(function ($item) { return $item->isClosedForSa(); });
        // Wave 16: already-closed reports are never re-closed (no
        // duplicate audit noise) — they are simply skipped.
        $items = $items->reject(function ($item) { return $item->isClosedForSa(); });
        foreach ($items as $item) {
            // Redesign 2026-10-28: a bulk close resolves the school
            // side too, so the reporter reads "Resolved" with no
            // Super Admin content.
            $item->update(['sa_status' => 'fixed', 'status' => 'resolved']);
            Audit::log('feedback.sa_closed', $item,
                'Super Admin closed feedback #' . $item->id . ' from the list (marked Fixed).');
        }

        if ($items->isEmpty() && $alreadyClosed->isEmpty()) {
            return redirect()->route('feedback.sa_index')
                ->with('flash_danger', 'No forwarded reports matched the selection.');
        }

        if ($items->isEmpty()) {
            return redirect()->route('feedback.sa_index')
                ->with('flash_info', 'Selected report(s) are already closed — nothing was changed.');
        }

        return redirect()->route('feedback.sa_index')
            ->with('flash_success', $items->count() . ' report(s) closed (marked Fixed).');
    }

    /**
     * School Admin reopens a closed report (Wave 16 ticket rule):
     * the ONLY way a Fixed report comes back — it returns to
     * Received on the Super Admin's board, i.e. a re-escalation.
     * Redesign 2026-10-28: the school side returns to In Progress
     * with it — the auto-resolve from the SA fix must not keep
     * telling the reporter "Resolved" for an open issue. The Admin
     * can still set any school status afterwards on the detail page.
     * Non-closed and non-forwarded items cannot be "reopened".
     */
    public function reopen(FeedbackItem $feedback)
    {
        $this->mustBeAdmin();
        $this->mustBeForwarded($feedback);

        if (!$feedback->isClosedForSa()) {
            return back()->with('pop_error', 'Only a closed (Fixed) report can be reopened.');
        }

        $feedback->update(['sa_status' => 'received', 'status' => 'in_progress']);
        Audit::log('feedback.reopened', $feedback,
            'School Admin reopened feedback #' . $feedback->id . ' — back to Received for the Super Admin.');

        return back()->with('flash_success', 'Report reopened — the Super Admin sees it as Received again.');
    }

    /* ----------------------------------------------------------------
     | Guards
     | ---------------------------------------------------------------- */

    protected function mustBeAdmin(): void
    {
        if (!Qs::userIsAdmin()) {
            abort(403);
        }
    }

    protected function mustBeSuperAdmin(): void
    {
        if (!Qs::userIsSuperAdmin()) {
            abort(403);
        }
    }

    protected function mustBeForwarded(FeedbackItem $feedback): void
    {
        if (!$feedback->isForwarded()) {
            abort(404);
        }
    }
}
