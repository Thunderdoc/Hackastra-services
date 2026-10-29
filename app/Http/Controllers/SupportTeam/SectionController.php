<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Requests\Section\SectionCreate;
use App\Http\Requests\Section\SectionUpdate;
use App\Models\Subject;
use App\Repositories\MyClassRepo;
use App\Http\Controllers\Controller;
use App\Repositories\UserRepo;
use App\Support\Audit;
use App\Support\Streams;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SectionController extends Controller
{
    protected $my_class, $user;

    public function __construct(MyClassRepo $my_class, UserRepo $user)
    {
        // Workstream CM: reads (index/show) stay teamSA — SA keeps
        // oversight; every write goes to the Admin write team.
        $this->middleware('teamSA', ['only' => ['index', 'show']]);
        $this->middleware('teamSAWrite', ['except' => ['index', 'show']]);

        $this->my_class = $my_class;
        $this->user = $user;
    }

    public function index()
    {
        $d['my_classes'] = $this->my_class->all();
        $d['teachers'] = $this->user->getUserByType('teacher');

        // Batch 4: one class at a time via ?class_id= (unknown/absent
        // id falls back to the first class); sections are filtered
        // server-side instead of rendering every class's table.
        $d['selected_class'] = request('class_id')
            ? $d['my_classes']->firstWhere('id', (int) request('class_id')) ?? $d['my_classes']->first()
            : $d['my_classes']->first();
        $d['sections'] = $d['selected_class']
            ? $this->my_class->getClassSections($d['selected_class']->id)
            : collect();
        // Allotment audit 2026-10-28: overrides were invisible on
        // this list — an admin had to open every section's edit
        // form to see them. One grouped query for the counts.
        $d['overrideCounts'] = $d['sections']->isNotEmpty()
            ? DB::table('section_subject_teachers')
                ->whereIn('section_id', $d['sections']->pluck('id'))
                ->selectRaw('section_id, COUNT(*) as c')->groupBy('section_id')
                ->pluck('c', 'section_id')
            : collect();

        // Allotment round 2 (2026-10-28): readiness — which of
        // each section's subjects have NO teacher resolution at
        // all (no class-wide subject teacher AND no override).
        // A section with unresolved subjects must be visible
        // HERE, before timetable generation, not discovered
        // after a grid comes out with untaught periods. The
        // universe matches the generator exactly: a streamed
        // section is judged only on common + own-stream subjects
        // (Streams::subjectsForSection), so a PCM section is
        // never flagged for lacking a Commerce teacher. Two
        // grouped queries, no per-section query loop. A
        // resolution counts only when it points at a LIVE
        // teacher row — a stale id (deleted teacher) is
        // unresolved, not silently staffed. Round 3: "live"
        // also excludes demoted (user_type changed) and
        // suspended/terminated teachers — the same live standard
        // as Staffing::eligibleTeacherIds — since the row still
        // exists but the person cannot teach.
        $d['unresolvedSubjects'] = collect();
        $liveTeacherIds = $d['teachers']->filter(function ($t) {
            return !$t->getAttribute('status') || $t->getAttribute('status') === 'active';
        })->pluck('id')->map(function ($id) { return (int) $id; })->flip();
        $d['liveTeacherIds'] = $liveTeacherIds;
        if ($d['selected_class'] && $d['sections']->isNotEmpty()) {
            $classSubjects = Subject::where('my_class_id', $d['selected_class']->id)->orderBy('id')->get();
            $liveTeachers = $liveTeacherIds;
            $overrideMap = DB::table('section_subject_teachers')
                ->whereIn('section_id', $d['sections']->pluck('id'))
                ->get()->groupBy('section_id');
            foreach ($d['sections'] as $sec) {
                $mine = Streams::subjectsForSection($classSubjects, $sec);
                $unresolved = [];
                foreach ($mine as $sub) {
                    $ov = ($overrideMap[$sec->id] ?? collect())->firstWhere('subject_id', $sub->id);
                    $resolved = ($ov && $liveTeachers->has((int) $ov->teacher_id))
                        || ($sub->teacher_id && $liveTeachers->has((int) $sub->teacher_id));
                    if (!$resolved) {
                        $unresolved[] = $sub->name;
                    }
                }
                $d['unresolvedSubjects'][$sec->id] = $unresolved;
            }
        }

        return view('pages.support_team.sections.index', $d);
    }

    /**
     * Allotment round 32 (F-R32-1): the Section FormRequests
     * check teacher_id only on exists + user_type='teacher' —
     * a suspended/terminated teacher passed validation and was
     * stored as section leader through the plain UI, while the
     * index already flags such a leader "(not an active
     * teacher)" and TeacherScope still grants her the led class
     * in code. Same live standard as Staffing/LeaveCover and
     * F-R31-1: status null (legacy) or 'active'. Refusal shape
     * is back + flash_danger naming the teacher.
     * Pre-existing dead leaders are NOT auto-cleared —
     * suspension is temporary; the admin re-assigns a live
     * teacher to clear one.
     */
    protected function livenessRefusal($teacherId)
    {
        if (!$teacherId) {
            return null;
        }
        $teacher = $this->user->find(Qs::resolveId($teacherId));
        if ($teacher && $teacher->user_type === 'teacher'
            && $teacher->getAttribute('status') && $teacher->getAttribute('status') !== 'active') {
            return back()->with('flash_danger', $teacher->name
                . ' is not an active teacher — suspended or terminated accounts cannot lead a section.')->withInput();
        }

        return null;
    }

    public function store(SectionCreate $req)
    {
        // Allotment audit 2026-10-28: $req->all() mass-assigned
        // through Section::create, whose fillable includes 'active'
        // — a crafted POST could create a hidden inactive section.
        // The update path already whitelists with only(); store
        // now matches it.
        $data = $req->only(['name', 'my_class_id', 'teacher_id', 'stream', 'capacity']);
        if ($refusal = $this->livenessRefusal($data['teacher_id'] ?? null)) {
            return $refusal;
        }
        $this->my_class->createSection($data);

        // Loop 112: post-save lands on the sections index (owner ruling).
        return Qs::storeOk('sections.index');
    }

    public function edit($id)
    {
        // Audit Fix Wave 1 (X-2): resolve raw OR Qs-hashed ids.
        $d['s'] = $s = $this->my_class->findSection(Qs::resolveId($id));
        $d['teachers'] = $this->user->getUserByType('teacher');
        // Round 3: the matrix flags ghost/demoted/suspended
        // teachers — same live standard as the index readiness.
        $d['liveTeacherIds'] = $d['teachers']->filter(function ($t) {
            return !$t->getAttribute('status') || $t->getAttribute('status') === 'active';
        })->pluck('id')->map(function ($id) { return (int) $id; })->flip();
        // Section build (2026-10-25): the class's subjects plus this
        // section's teacher overrides, for the matrix below the form.
        if ($s) {
            $d['subjects'] = Subject::where('my_class_id', $s->my_class_id)->orderBy('id')->get();
            $d['overrides'] = DB::table('section_subject_teachers')
                ->where('section_id', $s->id)->pluck('teacher_id', 'subject_id');
            // Allotment round 2 (2026-10-28): STALE overrides —
            // rows pointing at subjects this section's class does
            // not offer (e.g. left behind by a subject move/delete
            // or a forged request). The matrix below lists only
            // class subjects, so these rows are invisible there
            // and no save ever touches them — yet the index
            // Overrides count includes them. Surface them here
            // with a clear checkbox (same PUT, no new route) so
            // the admin can actually remove them.
            $d['staleOverrides'] = DB::table('section_subject_teachers as sst')
                ->join('subjects as sub', 'sub.id', '=', 'sst.subject_id')
                ->leftJoin('users as u', 'u.id', '=', 'sst.teacher_id')
                ->where('sst.section_id', $s->id)
                ->where('sub.my_class_id', '!=', $s->my_class_id)
                ->select('sst.subject_id', 'sub.name as subject_name', 'sub.my_class_id as subject_class_id', 'u.name as teacher_name')
                ->orderBy('sub.name')->get();
        }

        return is_null($s) ? Qs::goWithDanger('sections.index') :view('pages.support_team.sections.edit', $d);
    }

    public function update(SectionUpdate $req, $id)
    {
        $data = $req->only(['name', 'teacher_id', 'stream', 'capacity']);
        if ($refusal = $this->livenessRefusal($data['teacher_id'] ?? null)) {
            return $refusal;
        }
        $this->my_class->updateSection(Qs::resolveId($id), $data);

        // Loop 112: post-save lands on the sections index (owner ruling).
        return Qs::updateOk('sections.index');
    }

    /**
     * Section build (2026-10-25): per-section subject teachers.
     * Blank = no override (the subject's class teacher teaches this
     * section); a chosen teacher teaches that subject in THIS
     * section only. Only the section's own class subjects are
     * honoured, so foreign keys in the request are ignored.
     */
    public function updateTeachers(Request $req, $id)
    {
        $section = $this->my_class->findSection(Qs::resolveId($id));
        if (!$section) {
            return Qs::goWithDanger('sections.index');
        }

        $input = (array) $req->input('teachers', []);
        $subjects = Subject::where('my_class_id', $section->my_class_id)->get();
        // Allotment round 2 (2026-10-28): clear_stale[] from the
        // edit page's stale-overrides block. A row is cleared
        // ONLY when it is genuinely stale for this section
        // (its subject belongs to another class) — a forged
        // clear_stale entry naming a valid class subject is
        // ignored, so this path can never delete a live
        // override. Valid overrides are untouched by it.
        $cleared = 0;
        foreach ((array) $req->input('clear_stale', []) as $staleSubjectId) {
            $staleSubjectId = (int) $staleSubjectId;
            if (!$staleSubjectId) {
                continue;
            }
            $staleSubject = Subject::find($staleSubjectId);
            if (!$staleSubject || (int) $staleSubject->my_class_id === (int) $section->my_class_id) {
                continue;
            }
            $cleared += DB::table('section_subject_teachers')
                ->where('section_id', $section->id)
                ->where('subject_id', $staleSubjectId)->delete();
        }
        // Allotment audit 2026-10-28: forged/non-teacher values
        // were silently dropped while the flash still claimed a
        // clean save. The save stays forgiving (one bad row must
        // not block the rest), but the admin is now told which
        // subjects were skipped.
        $skipped = [];
        $changes = [];
        foreach ($subjects as $subject) {
            $raw = $input[$subject->id] ?? '';
            if ($raw === '' || $raw === null) {
                $deleted = DB::table('section_subject_teachers')
                    ->where('section_id', $section->id)
                    ->where('subject_id', $subject->id)->delete();
                if ($deleted) {
                    $changes[] = $subject->name . ' override cleared';
                }
                continue;
            }
            $teacherId = Qs::decodeHash($raw);
            $teacher = $teacherId ? $this->user->find($teacherId) : null;
            // Allotment round 31 (F-R31-1): the save previously
            // accepted ANY teacher row — a suspended/terminated
            // teacher gained an override through the plain matrix
            // UI (the edit form offers every teacher), even though
            // readiness, the edit-page flag and Staffing all treat
            // her as dead, and the stored row then froze the live
            // direct teacher's marks entry under F-R24-1
            // (teacherIdFor still resolves the override). Non-live
            // teachers are now skipped exactly like forged values:
            // named in the flash, never stored. The live standard
            // matches Staffing/LeaveCover: status null (legacy) or
            // 'active'. Pre-existing rows for teachers suspended
            // AFTER assignment are untouched — suspension is
            // temporary, and the admin clears them with a blank
            // save; the edit page already flags them.
            if (!$teacher || $teacher->user_type !== 'teacher'
                || ($teacher->getAttribute('status') && $teacher->getAttribute('status') !== 'active')) {
                $skipped[] = $subject->name;
                continue; // forged, non-teacher, or non-live value: ignore, never store
            }
            $existing = DB::table('section_subject_teachers')
                ->where('section_id', $section->id)
                ->where('subject_id', $subject->id)->first();
            if ($existing) {
                if ((int) $existing->teacher_id !== (int) $teacher->id) {
                    $oldName = DB::table('users')->where('id', $existing->teacher_id)->value('name')
                        ?: 'unknown teacher';
                    $changes[] = $subject->name . ': ' . $oldName . ' → ' . $teacher->name;
                }
                DB::table('section_subject_teachers')
                    ->where('section_id', $section->id)
                    ->where('subject_id', $subject->id)
                    ->update(['teacher_id' => $teacher->id, 'updated_at' => now()]);
            } else {
                $changes[] = $subject->name . ' → ' . $teacher->name;
                DB::table('section_subject_teachers')->insert([
                    'section_id' => $section->id, 'subject_id' => $subject->id,
                    'teacher_id' => $teacher->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        // Allotment round 5 (2026-10-28): overrides whose subject
        // row is GONE can never apply again, yet they survive every
        // save (the loop above visits only live class subjects) and
        // are invisible in the round-2 stale block (which
        // inner-joins subjects) while still inflating the index
        // Overrides count. Clean them here. This touches ONLY rows
        // for nonexistent subjects — stale rows for live subjects
        // of other classes keep the round-2 checkbox flow.
        $orphans = DB::table('section_subject_teachers')
            ->where('section_id', $section->id)
            ->whereNotIn('subject_id', function ($q) {
                $q->select('id')->from('subjects');
            })->delete();
        // Allotment round 5 (2026-10-28): the matrix save changed
        // allotments with no audit trail (Planning apply logs; this
        // did not). Log real changes only — a no-op re-save writes
        // nothing, and a save that only skipped invalid entries
        // already reports itself in the flash.
        if ($changes || $cleared || $orphans) {
            $segments = $changes;
            if ($cleared) {
                $segments[] = 'cleared ' . $cleared . ' stale override' . ($cleared > 1 ? 's' : '');
            }
            if ($orphans) {
                $segments[] = 'removed ' . $orphans . ' orphan override' . ($orphans > 1 ? 's' : '') . ' for deleted subjects';
            }
            if ($skipped) {
                $segments[] = 'ignored invalid entries for: ' . implode(', ', $skipped);
            }
            Audit::log('sections.teachers_updated', $section,
                'Subject teachers for section ' . $section->name . ': ' . implode('; ', $segments) . '.');
        }

        return redirect()->route('sections.index')
            ->with('flash_success', 'Subject teachers saved for ' . $section->name . '.'
                . ($cleared ? ' Cleared ' . $cleared . ' stale override' . ($cleared > 1 ? 's' : '') . '.' : '')
                . ($skipped ? ' Ignored invalid entries for: ' . implode(', ', $skipped) . '.' : ''));
    }

    public function destroy($id)
    {
        $id = Qs::resolveId($id);
        if($this->my_class->isActiveSection($id)){
            return back()->with('pop_warning', 'Every class must have a default section, You Cannot Delete It');
        }

        // Allotment audit 2026-10-28 (pass 2): the overrides
        // table has no FK, so deleting the section orphaned its
        // override rows (harmless for scope, which joins
        // sections, but stale for any future section reusing the
        // id). Remove them with the section.
        DB::table('section_subject_teachers')->where('section_id', $id)->delete();

        $this->my_class->deleteSection($id);
        return back()->with('flash_success', __('msg.del_ok'));
    }


    public function create()
    {
        // No separate create page exists: the create form lives on the
        // index page (the resource route otherwise returned 500).
        return $this->index();
    }

    public function show($id)
    {
        // No detail page exists for this module; land on its index
        // instead of returning 500.
        return redirect()->route('sections.index');
    }

}
