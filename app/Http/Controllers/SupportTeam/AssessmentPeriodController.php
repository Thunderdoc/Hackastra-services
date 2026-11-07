<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Http\Requests\Exam\AssessmentPeriodCreate;
use App\Http\Requests\Exam\AssessmentPeriodUpdate;
use App\Models\AssessmentPeriod;
use App\Models\Exam;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Exam Pattern page (Round 3 R1, design §2): the school's
 * assessment periods for the CURRENT session — presets, add/edit
 * with the §2.3 locks, ordering, copy-to-next-session and the
 * "link unlinked exams" fixup for seeder-style exam rows.
 *
 * Gates copy ExamController's constructor shape exactly: reads
 * teamOrPrincipal:SA, writes teamSAWrite (Admin writes; the
 * Super Admin oversees view-only; the Principal reads).
 */
class AssessmentPeriodController extends Controller
{
    public function __construct()
    {
        $this->middleware('teamOrPrincipal:SA', ['only' => ['index']]);
        $this->middleware('teamSAWrite', ['except' => ['index']]);
    }

    public function index(Request $req)
    {
        $year = Qs::getSetting('current_session');
        $d['year'] = $year;
        $d['periods'] = AssessmentPeriod::forYear($year)->withCount('exams')->get();
        $d['term_periods'] = $d['periods']->where('kind', 'term')->values();
        $d['unlinked_count'] = Exam::where('year', $year)->whereNull('period_id')->count();
        $d['editing'] = null;
        if ($req->filled('edit')) {
            $d['editing'] = $d['periods']->firstWhere('id', (int) $req->query('edit'));
        }
        // Default target for copy-to-session: the next YYYY-YYYY label.
        $d['next_year'] = $this->nextSessionLabel($year);
        return view('pages.support_team.exams.periods.index', $d);
    }

    public function store(AssessmentPeriodCreate $req)
    {
        $year = Qs::getSetting('current_session');
        $data = $this->periodData($req, $year, null);
        if (!isset($data['sort_order'])) {
            $data['sort_order'] = ((int) AssessmentPeriod::where('year', $year)->max('sort_order')) + 10;
        }
        AssessmentPeriod::create($data);
        return redirect()->route('exams.periods.index')->with('flash_success', 'Period added to the exam pattern.');
    }

    public function update(AssessmentPeriodUpdate $req, $id)
    {
        $period = AssessmentPeriod::find(Qs::resolveId($id));
        if (!$period) {
            return redirect()->route('exams.periods.index')->with('flash_danger', 'Period not found.');
        }

        // §2.3 locks: with exams present, kind / feeds / legacy are
        // frozen — refused server-side, not just disabled inputs.
        if ($period->exams()->exists()) {
            foreach (['kind', 'feeds_period_id', 'legacy_term'] as $field) {
                if ($req->has($field)) {
                    $posted = $req->input($field);
                    $posted = ($posted === '' || $posted === null) ? null : (is_numeric($posted) ? (int) $posted : $posted);
                    $current = $period->$field;
                    $current = ($current === null) ? null : (is_numeric($current) ? (int) $current : $current);
                    if ($posted !== $current) {
                        return back()->with('flash_danger', 'This period already has exams, so its kind, feeds-into and legacy term are locked. It can still be renamed or reordered.')->withInput();
                    }
                }
            }
        }

        $period->update($this->periodData($req, $period->year, $period));
        return redirect()->route('exams.periods.index')->with('flash_success', 'Period updated.');
    }

    /** Normalised write payload shared by store/update. */
    protected function periodData($req, $year, ?AssessmentPeriod $existing): array
    {
        $kind = $req->input('kind', $existing ? $existing->kind : 'term');
        $data = [
            'year' => $year,
            'name' => $req->input('name'),
            'kind' => $kind,
            'is_active' => $req->has('is_active') ? (bool) $req->input('is_active') : ($existing ? $existing->is_active : true),
        ];
        if ($req->has('sort_order') && $req->input('sort_order') !== null && $req->input('sort_order') !== '') {
            $data['sort_order'] = (int) $req->input('sort_order');
        }
        // Feeds: unit only; everyone else stores NULL.
        $data['feeds_period_id'] = $kind === 'unit' ? (int) $req->input('feeds_period_id') : null;
        // Legacy term: term kind only.
        $legacy = $req->input('legacy_term');
        $data['legacy_term'] = ($kind === 'term' && $legacy !== '' && $legacy !== null) ? (int) $legacy : null;
        // Counts toward annual: unit never, term always, standalone = posted choice.
        if ($kind === 'unit') {
            $data['counts_toward_annual'] = false;
        } elseif ($kind === 'term') {
            $data['counts_toward_annual'] = true;
        } else {
            $data['counts_toward_annual'] = $req->has('counts_toward_annual')
                ? (bool) $req->input('counts_toward_annual')
                : ($existing ? $existing->counts_toward_annual : true);
        }
        return $data;
    }

    public function destroy($id)
    {
        $period = AssessmentPeriod::find(Qs::resolveId($id));
        if (!$period) {
            return back()->with('flash_danger', 'Period not found.');
        }
        if ($period->exams()->exists()) {
            return back()->with('flash_danger', 'This period has exams and cannot be deleted. Deactivate it instead — its exams and marks stay untouched.');
        }
        if ($period->fedByPeriods()->exists()) {
            return back()->with('flash_danger', 'Unit periods feed this period, so it cannot be deleted. Remove or re-point those unit periods first.');
        }
        $period->delete();
        return back()->with('flash_success', 'Period deleted.');
    }

    /** Activate/deactivate toggle (deactivation hides the period
     *  from the exam picker; exams/marks/cards are untouched). */
    public function deactivate($id)
    {
        $period = AssessmentPeriod::find(Qs::resolveId($id));
        if (!$period) {
            return back()->with('flash_danger', 'Period not found.');
        }
        $period->update(['is_active' => !$period->is_active]);
        return back()->with('flash_success', $period->is_active ? 'Period activated.' : 'Period deactivated. It no longer appears in the exam picker; its exams are unchanged.');
    }

    /** ▲▼ ordering: swap with the neighbour, then re-space the
     *  whole session's sort_order to 10/20/30… (§2.2). */
    public function move(Request $req, $id)
    {
        $period = AssessmentPeriod::find(Qs::resolveId($id));
        if (!$period) {
            return back()->with('flash_danger', 'Period not found.');
        }
        $periods = AssessmentPeriod::forYear($period->year)->get()->values();
        $idx = $periods->search(function ($p) use ($period) { return (int) $p->id === (int) $period->id; });
        $swap = $req->input('direction') === 'up' ? $idx - 1 : $idx + 1;
        if ($idx === false || $swap < 0 || $swap >= $periods->count()) {
            return back();
        }
        $tmp = $periods[$idx];
        $periods[$idx] = $periods[$swap];
        $periods[$swap] = $tmp;
        foreach ($periods as $i => $p) {
            if ((int) $p->sort_order !== ($i + 1) * 10) {
                $p->update(['sort_order' => ($i + 1) * 10]);
            }
        }
        return back()->with('flash_success', 'Period order updated.');
    }

    /** Presets (§2.2): a starting point only — refused when the
     *  session already has any periods. */
    public function applyPreset(Request $req)
    {
        $year = Qs::getSetting('current_session');
        if (AssessmentPeriod::where('year', $year)->exists()) {
            return back()->with('flash_danger', 'This session already has an exam pattern. Presets are a starting point for an empty pattern and never overwrite existing periods.');
        }
        $preset = (string) $req->input('preset');
        DB::transaction(function () use ($preset, $year) {
            $mk = function ($name, $kind, $sort, $legacy = null, $feeds = null, $counts = true) use ($year) {
                return AssessmentPeriod::create([
                    'year' => $year, 'name' => $name, 'kind' => $kind,
                    'feeds_period_id' => $feeds, 'counts_toward_annual' => $counts,
                    'sort_order' => $sort, 'legacy_term' => $legacy, 'is_active' => true,
                ]);
            };
            if ($preset === 'terms3') {
                $mk('Term 1', 'term', 10, 1);
                $mk('Term 2', 'term', 20, 2);
                $mk('Term 3', 'term', 30, 3);
            } elseif ($preset === 'unit_terms') {
                $t1 = $mk('Term 1', 'term', 20, 1);
                $t2 = $mk('Term 2', 'term', 40, 2);
                $t3 = $mk('Term 3', 'term', 60, 3);
                $mk('Unit Test 1', 'unit', 10, null, $t1->id, false);
                $mk('Unit Test 2', 'unit', 30, null, $t2->id, false);
                $mk('Unit Test 3', 'unit', 50, null, $t3->id, false);
            } elseif ($preset === 'two_terms') {
                $mk('Term 1', 'term', 10, 1);
                $mk('Term 2', 'term', 20, 2);
            } elseif ($preset === 'blank') {
                // No rows — the admin adds periods one by one.
            } else {
                abort(422, 'Unknown preset.');
            }
        });
        return redirect()->route('exams.periods.index')->with('flash_success', 'Preset applied. You can rename, reorder or add periods below.');
    }

    /** Clone the current session's ACTIVE periods into a target
     *  session, remapping feeds_period_id (§2.2). */
    public function copyToSession(Request $req)
    {
        $req->validate(['target_year' => 'required|string|max:40']);
        $target = trim((string) $req->input('target_year'));
        $year = Qs::getSetting('current_session');
        if (AssessmentPeriod::where('year', $target)->exists()) {
            return back()->with('flash_danger', 'The session ' . $target . ' already has an exam pattern — copying is refused so nothing is overwritten.');
        }
        $source = AssessmentPeriod::forYear($year)->where('is_active', true)->get();
        if ($source->isEmpty()) {
            return back()->with('flash_danger', 'There are no active periods in the current session to copy.');
        }
        DB::transaction(function () use ($source, $target) {
            $map = [];
            foreach ($source as $p) {
                $clone = AssessmentPeriod::create([
                    'year' => $target, 'name' => $p->name, 'kind' => $p->kind,
                    'feeds_period_id' => null, 'counts_toward_annual' => $p->counts_toward_annual,
                    'sort_order' => $p->sort_order, 'legacy_term' => $p->legacy_term, 'is_active' => true,
                ]);
                $map[$p->id] = $clone->id;
            }
            foreach ($source as $p) {
                if ($p->feeds_period_id && isset($map[$p->feeds_period_id])) {
                    AssessmentPeriod::where('id', $map[$p->id])
                        ->update(['feeds_period_id' => $map[$p->feeds_period_id]]);
                }
            }
        });
        return redirect()->route('exams.periods.index')->with('flash_success', 'Exam pattern copied to ' . $target . '. Switch the current session in Settings to manage it there.');
    }

    /**
     * "Link unlinked exams" fixup (§3.3): post-migration seeder
     * inserts can create exams with period_id NULL. Run the §3.1
     * mapping on demand for the current session.
     */
    public function linkUnlinked()
    {
        $year = Qs::getSetting('current_session');
        $unlinked = Exam::where('year', $year)->whereNull('period_id')->get();
        if ($unlinked->isEmpty()) {
            return back()->with('flash_info', 'Every exam in this session is already linked to a period.');
        }
        DB::transaction(function () use ($unlinked, $year) {
            foreach ($unlinked->groupBy('term') as $term => $exams) {
                $period = AssessmentPeriod::where('year', $year)
                    ->where('legacy_term', (int) $term)->where('kind', 'term')->first();
                if (!$period) {
                    $period = AssessmentPeriod::create([
                        'year' => $year, 'name' => 'Term ' . $term, 'kind' => 'term',
                        'feeds_period_id' => null, 'counts_toward_annual' => true,
                        'sort_order' => ((int) $term) * 10, 'legacy_term' => (int) $term, 'is_active' => true,
                    ]);
                }
                Exam::whereIn('id', $exams->pluck('id'))->update(['period_id' => $period->id]);
            }
        });
        return back()->with('flash_success', $unlinked->count() . ' unlinked exam(s) linked to their periods.');
    }

    protected function nextSessionLabel($year): string
    {
        if (preg_match('/^(\d{4})-(\d{4})$/', (string) $year, $m)) {
            return ((int) $m[1] + 1) . '-' . ((int) $m[2] + 1);
        }
        return (string) $year;
    }
}
