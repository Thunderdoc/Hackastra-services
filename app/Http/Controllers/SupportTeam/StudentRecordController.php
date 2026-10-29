<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Support\Audit;
use App\Support\CsvExport;
use App\Support\TeacherScope;
use App\Helpers\Mk;
use App\Http\Requests\Student\QuickAdmitRequest;
use App\Http\Requests\Student\StudentRecordCreate;
use App\Http\Requests\Student\StudentRecordUpdate;
use App\Repositories\LocationRepo;
use App\Repositories\MyClassRepo;
use App\Repositories\StudentRepo;
use App\Repositories\UserRepo;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StudentRecordController extends Controller
{
    protected $loc, $my_class, $user, $student;

   public function __construct(LocationRepo $loc, MyClassRepo $my_class, UserRepo $user, StudentRepo $student)
   {
       // Workstream CM: student writes belong to the school Admin —
       // the write team (teamSA minus super_admin) gates every
       // mutating method; reads stay on the in-method/policy guards.
       // (graduated() is a GET list view — SA keeps it; the write it
       // sits next to, not_graduated, was previously ungated and is
       // now on the write team too.)
       $this->middleware('teamSAWrite', ['only' => ['edit','update', 'reset_pass', 'create', 'store', 'quickStore', 'not_graduated'] ]);
       $this->middleware('teamSAWrite', ['only' => ['destroy',] ]);
       $this->middleware('teamSA', ['only' => ['graduated']]);

        $this->loc = $loc;
        $this->my_class = $my_class;
        $this->user = $user;
        $this->student = $student;
   }

    public function index()
    {
        // The resource route registers GET /students, but this controller
        // never had an index() — the URL returned 500. The app browses
        // students per class, so land on the first class's list instead.
        // Batch S: for a teacher the landing class must be one of their
        // assigned classes, or listByClass would (correctly) 403 them.
        if (Qs::userIsTeacher()) {
            $ids = TeacherScope::classIds(Auth::user());
            $first = $ids ? $this->my_class->getMC(['id' => $ids[0]])->first() : null;
            return $first
                ? redirect()->route('students.list', $first->id)
                : redirect()->route('dashboard')->with('pop_error', __('msg.denied'));
        }

        $first = $this->my_class->all()->first();
        return $first
            ? redirect()->route('students.list', $first->id)
            : redirect()->route('classes.index');
    }

    public function reset_pass($st_id)
    {
        $st_id = Qs::decodeHash($st_id);
        $data['password'] = Hash::make('student');
        $this->user->update($st_id, $data);
        return back()->with('flash_success', __('msg.p_reset'));
    }

    public function create()
    {
        $data['my_classes'] = $this->my_class->all();
        $data['parents'] = $this->user->getUserByType('parent');
        $data['dorms'] = $this->student->getAllDorms();
        $data['states'] = $this->loc->getStates();
        $data['nationals'] = $this->loc->getAllNationals();
        return view('pages.support_team.students.add', $data);
    }

    public function store(StudentRecordCreate $req)
    {
       $data =  $req->only(Qs::getUserRecord());
       $sr =  $req->only(Qs::getStudentData());

        $type = $this->my_class->findTypeByClass($req->my_class_id);
        if (is_null($type)) {
            return Qs::goWithDanger();
        }
        $ct = $type->code;
       /* $ct = ($ct == 'J') ? 'JSS' : $ct;
        $ct = ($ct == 'S') ? 'SS' : $ct;*/

        $data['user_type'] = 'student';
        $data['name'] = ucwords($req->name);
        $data['code'] = strtoupper(Str::random(10));
        $data['password'] = Hash::make('student');
        $data['photo'] = Qs::getDefaultUserImage();
        $adm_no = $req->adm_no;
        $data['username'] = $adm_no
            ? Qs::admNoPrefix($ct, $sr['year_admitted']).strtoupper($adm_no)
            : Qs::generateAdmNo($ct, $sr['year_admitted']);

        if($req->hasFile('photo')) {
            $photo = $req->file('photo');
            $f = Qs::getFileMetaData($photo);
            $f['name'] = 'photo.' . $f['ext'];
            $f['path'] = $photo->storeAs(Qs::getUploadPath('student').$data['code'], $f['name']);
            $data['photo'] = asset('storage/' . $f['path']);
        }

        /* ADM-01 (2026-10-23): the user + record now commit in one
           transaction, and a system-minted admission number goes
           through Qs::mintStudent so a concurrent admission minting
           the same number retries with a fresh one instead of 500ing
           (and orphaning the login). An admin-typed number is used
           exactly as given — a collision there must surface, never
           silently renumber the student. */
        /* Section capacity (owner order 2026-10-29): a full
           section never silently overflows — the student is
           allotted the next section of the class with room and
           the admin is told; if the whole class is full the
           admission is refused with the reason. */
        $placement = \App\Support\SectionCapacity::resolve((int) $sr['my_class_id'], (int) $sr['section_id']);
        if ($placement['all_full']) {
            $className = optional($this->my_class->find($sr['my_class_id']))->name ?? 'that class';
            return back()->withInput()->with('flash_danger', \App\Support\SectionCapacity::allFullMessage($className));
        }
        $movedNote = '';
        if ($placement['moved']) {
            $sr['section_id'] = $placement['section_id'];
            $movedNote = ' ' . \App\Support\SectionCapacity::movedMessage($placement['from'], $placement['to']);
        }

        $sr['session'] = Qs::getSetting('current_session');
        $createStudent = function ($username) use ($data, $sr) {
            $data['username'] = $username;
            $sr['adm_no'] = $username;
            DB::transaction(function () use ($data, $sr) {
                $user = $this->user->create($data); // Create User
                $sr['user_id'] = $user->id;
                $this->student->createRecord($sr); // Create Student
            });
        };
        if ($adm_no) {
            $createStudent($data['username']);
        } else {
            Qs::mintStudent($createStudent, $ct, $sr['year_admitted']);
        }
        // Loop 112: post-save lands on the student's class list (owner
        // ruling) — was Qs::jsonStoreOk() (a raw JSON response to a
        // plain form POST).
        return redirect()->route('students.list', $req->my_class_id)
            ->with('flash_success', __('msg.store_ok') . $movedNote);
    }

    /**
     * Quick Admit (owner-ordered 2026-10-22): admit with the essentials
     * only. Creates the user + student record with EXACTLY the shape
     * store() creates — same generated username/admission number, same
     * default password ('student'), same default photo, same session —
     * with every non-essential profile field left NULL (all nullable
     * in the schema) for the office to complete later via the Edit
     * form. store() sets nothing else in motion at admission time
     * (fee/payment records are created per class by
     * PaymentController@select_class, for quick- and form-admitted
     * students alike), so a quick-admitted student is functionally
     * identical to a form-admitted one. Parent linking is link-only,
     * resolved by QuickAdmitRequest (importer parity). The full form's
     * validation is untouched.
     */
    public function quickStore(QuickAdmitRequest $req)
    {
        $type = $this->my_class->findTypeByClass($req->q_my_class_id);
        if (is_null($type)) {
            return Qs::goWithDanger();
        }
        $ct = $type->code;

        $parent = $req->resolvedParent();

        $data = [
            'name' => ucwords($req->q_name),
            'email' => NULL,
            'phone' => NULL,
            'phone2' => NULL,
            'dob' => NULL,
            'gender' => $req->q_gender,
            'address' => NULL,
            'bg_id' => NULL,
            'nal_id' => NULL,
            'state_id' => NULL,
            'lga_id' => NULL,
            'user_type' => 'student',
            'code' => strtoupper(Str::random(10)),
            'password' => Hash::make('student'),
            'photo' => Qs::getDefaultUserImage(),
        ];
        $adm_no = $req->q_adm_no;
        $data['username'] = $adm_no
            ? Qs::admNoPrefix($ct, $req->q_year_admitted).strtoupper($adm_no)
            : Qs::generateAdmNo($ct, $req->q_year_admitted);

        $sr = [
            'my_class_id' => (int) $req->q_my_class_id,
            'section_id' => (int) $req->q_section_id,
            'my_parent_id' => $parent ? $parent->id : NULL,
            'dorm_id' => NULL,
            'dorm_room_no' => NULL,
            'year_admitted' => $req->q_year_admitted,
            'house' => NULL,
            'age' => NULL,
            'adm_no' => $data['username'],
            'session' => Qs::getSetting('current_session'),
        ];

        /* Section capacity (owner order 2026-10-29): same rule as
           the full admission form — a full section moves the
           student to the next section with room (and the receipt
           says so); a fully-full class refuses the admission. */
        $placement = \App\Support\SectionCapacity::resolve((int) $sr['my_class_id'], (int) $sr['section_id']);
        if ($placement['all_full']) {
            $className = optional($this->my_class->find($sr['my_class_id']))->name ?? 'that class';
            return back()->withInput()->with('flash_danger', \App\Support\SectionCapacity::allFullMessage($className));
        }
        $movedNote = '';
        if ($placement['moved']) {
            $sr['section_id'] = $placement['section_id'];
            $movedNote = ' ' . \App\Support\SectionCapacity::movedMessage($placement['from'], $placement['to']);
        }

        /* ADM-01 companion: a system-minted number is allocated via
           Qs::mintStudent (collision retry); an admin-typed number is
           attempted exactly once, as given. */
        $createStudent = function ($username) use ($data, $sr) {
            $data['username'] = $username;
            $sr['adm_no'] = $username;
            return DB::transaction(function () use ($data, $sr) {
                $user = $this->user->create($data); // Create User
                $sr['user_id'] = $user->id;
                return $this->student->createRecord($sr); // Create Student
            });
        };
        $record = $adm_no
            ? $createStudent($data['username'])
            : Qs::mintStudent($createStudent, $ct, $req->q_year_admitted);

        Audit::log('student.quick_admitted', $record,
            'Quick-admitted ' . $data['name'] . ' (' . $data['username'] . ') — profile details pending completion.');

        return redirect()->route('students.create')
            ->with('flash_success', 'Student admitted successfully. Admission Number: ' . $data['username'] . $movedNote)
            ->with('quick_admitted', [
                'name' => $data['name'],
                'adm_no' => $data['username'],
                'sr_id' => $record->id,
            ]);
    }

    public function listByClass($class_id = null)
    {
        // Owner ruling 2026-10-22: the Students menu carries ONE
        // "Student Information" link instead of one item per class.
        // With no class in the URL this page is the landing — pick a
        // class (and optionally a section) and land on that class's
        // list. A submitted picker choice redirects to the canonical
        // per-class URL, so every existing filtered behaviour of the
        // list below (section, search, sort, print, CSV, bulk delete)
        // is untouched.
        if (is_null($class_id)) {
            $picked = (int) request('class_id');
            if ($picked) {
                $mc = $this->my_class->getMC(['id' => $picked])->first();
                $allowed = $mc && (!Qs::userIsTeacher() || TeacherScope::canTeachClass(Auth::user(), $mc->id));
                if ($allowed) {
                    $params = [$mc->id];
                    $section_id = (int) request('section_id');
                    if ($section_id && $this->my_class->getClassSections($mc->id)->contains('id', $section_id)) {
                        $params['section_id'] = $section_id;
                    }
                    return redirect()->route('students.list', $params);
                }
                return redirect()->route('students.list')
                    ->with('flash_danger', 'Please choose a class you have access to.');
            }

            $classes = $this->my_class->all();
            if (Qs::userIsTeacher()) {
                // Same scoping the old per-class submenu applied:
                // a teacher's picker offers only their assigned classes.
                $classes = $classes->whereIn('id', TeacherScope::classIds(Auth::user()))->values();
            }
            $data['classes'] = $classes;
            $data['sections'] = $this->my_class->getAllSections()
                ->whereIn('my_class_id', $classes->pluck('id'))->values();

            return view('pages.support_team.students.list_picker', $data);
        }

        $data['my_class'] = $mc = $this->my_class->getMC(['id' => $class_id])->first();
        if (is_null($mc)) {
            return Qs::goWithDanger();
        }

        // Batch S: teamSAT membership is not enough for a teacher —
        // the class must be one of their assigned classes.
        if (Qs::userIsTeacher() && !TeacherScope::canTeachClass(Auth::user(), $mc->id)) {
            abort(403, 'You can only view students in your assigned classes.');
        }

        $data['students'] = $this->student->findStudentsByClass($class_id);
        $data['sections'] = $this->my_class->getClassSections($class_id);

        // Batch 3B (additive): the pattern list filters server-side —
        // ?section_id= restricts to one section, ?q= matches name /
        // admission number / email. $students stays the FULL class
        // roster (the print header's total and the export contract
        // are unchanged); the screen table renders $listed.
        $data['selected_section'] = NULL;
        $data['q'] = $q = trim((string) request('q'));
        $listed = $data['students'];
        $section_id = (int) request('section_id');
        if ($section_id && $data['sections']->contains('id', $section_id)) {
            $data['selected_section'] = $data['sections']->firstWhere('id', $section_id);
            $listed = $listed->where('section_id', $section_id);
        }
        if ($q !== '') {
            $listed = $listed->filter(function ($s) use ($q) {
                return stripos((string) optional($s->user)->name, $q) !== false
                    || stripos((string) $s->adm_no, $q) !== false
                    || stripos((string) optional($s->user)->email, $q) !== false;
            });
        }
        // Loop 83 (additive): sort + pagination + view mode for the
        // redesigned Student Information page. $listed stays the FULL
        // filtered set — the stat cards and the print document read
        // it; the screen table/grid renders $paged. The default sort
        // is the repo's own order (name A–Z), so nothing changes
        // unless ?sort= is passed.
        $sort = (string) request('sort');
        if (!in_array($sort, ['name_asc', 'name_desc', 'adm_no'], true)) {
            $sort = 'name_asc';
        }
        if ($sort === 'name_desc') {
            $listed = $listed->sortByDesc('user.name')->values();
        } elseif ($sort === 'adm_no') {
            $listed = $listed->sortBy('adm_no', SORT_NATURAL | SORT_FLAG_CASE)->values();
        } else {
            $listed = $listed->values();
        }
        $data['listed'] = $listed;
        $data['sort'] = $sort;
        $data['view_mode'] = request('view') === 'grid' ? 'grid' : 'list';
        $data['print_mode'] = request('print') === '1';

        $per_page = 25;
        $page = max(1, (int) request('page', 1));
        $data['paged'] = new LengthAwarePaginator(
            $listed->forPage($page, $per_page),
            $listed->count(),
            $per_page,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );

        return view('pages.support_team.students.list', $data);
    }

    public function graduated()
    {
        $data['my_classes'] = $this->my_class->all();
        $data['students'] = $this->student->allGradStudents();

        // Batch 3: optional server-side class filter (?class_id=) so the
        // page renders ONE table instead of one tab pane per class.
        $data['selected_class'] = $class_id = (int) request('class_id');
        if ($class_id) {
            $data['students'] = $data['students']->where('my_class_id', $class_id)->values();
        }

        return view('pages.support_team.students.graduated', $data);
    }

    public function not_graduated($sr_id)
    {
        $d['grad'] = 0;
        $d['grad_date'] = NULL;
        $d['session'] = Qs::getSetting('current_session');
        $this->student->updateRecord($sr_id, $d);

        return back()->with('flash_success', __('msg.update_ok'));
    }

    public function show($sr_id)
    {
        $sr_id = Qs::decodeHash($sr_id);
        if(!$sr_id){return Qs::goWithDanger();}

        $data['sr'] = $this->student->getRecord(['id' => $sr_id])->first();

        /* Prevent Other Students/Parents from viewing Profile of others */
        if(Auth::user()->id != $data['sr']->user_id && !Qs::userIsTeamSAT() && !Qs::userIsMyChild($data['sr']->user_id, Auth::user()->id)){
            return redirect(route('dashboard'))->with('pop_error', __('msg.denied'));
        }

        /* Batch S: a teacher may open a profile only when the student's
           class is one of their assigned classes. */
        if(Qs::userIsTeacher() && !TeacherScope::canTeachClass(Auth::user(), $data['sr']->my_class_id)){
            return redirect(route('dashboard'))->with('pop_error', __('msg.denied'));
        }

        return view('pages.support_team.students.show', $data);
    }

    public function edit($sr_id)
    {
        $sr_id = Qs::decodeHash($sr_id);
        if(!$sr_id){return Qs::goWithDanger();}

        $data['sr'] = $this->student->getRecord(['id' => $sr_id])->first();
        $this->authorize('update', $data['sr']);
        $data['my_classes'] = $this->my_class->all();
        $data['parents'] = $this->user->getUserByType('parent');
        $data['dorms'] = $this->student->getAllDorms();
        $data['states'] = $this->loc->getStates();
        $data['nationals'] = $this->loc->getAllNationals();
        return view('pages.support_team.students.edit', $data);
    }

    public function update(StudentRecordUpdate $req, $sr_id)
    {
        $sr_id = Qs::decodeHash($sr_id);
        if(!$sr_id){return Qs::goWithDanger();}

        $sr = $this->student->getRecord(['id' => $sr_id])->first();
        $this->authorize('update', $sr);
        $d =  $req->only(Qs::getUserRecord());
        $d['name'] = ucwords($req->name);

        if($req->hasFile('photo')) {
            $photo = $req->file('photo');
            $f = Qs::getFileMetaData($photo);
            $f['name'] = 'photo.' . $f['ext'];
            $f['path'] = $photo->storeAs(Qs::getUploadPath('student').$sr->user->code, $f['name']);
            $d['photo'] = asset('storage/' . $f['path']);
        }

        $this->user->update($sr->user->id, $d); // Update User Details

        $srec = $req->only(Qs::getStudentData());

        $this->student->updateRecord($sr_id, $srec); // Update St Rec

        /*** If Class/Section is Changed in Same Year, Delete Marks/ExamRecord of Previous Class/Section ****/
        Mk::deleteOldRecord($sr->user->id, $srec['my_class_id']);

        // Loop 112: post-save lands on the student's class list (owner
        // ruling) — was Qs::jsonUpdateOk() (AJAX stay-on-form).
        return redirect()->route('students.list', $srec['my_class_id'])->with('flash_success', __('msg.update_ok'));
    }

    public function destroy($st_id)
    {
        $st_id = Qs::decodeHash($st_id);
        if(!$st_id){return Qs::goWithDanger();}

        $sr = $this->student->getRecord(['user_id' => $st_id])->first();
        $this->authorize('delete', $sr);
        $path = Qs::getUploadPath('student').$sr->user->code;
        Storage::exists($path) ? Storage::deleteDirectory($path) : false;
        $this->user->delete($sr->user->id);
        $sr->delete(); // Integration fix: the record row was left orphaned (its user gone), which broke class lists reading $record->user->photo
        Audit::log('student.deleted', $sr, 'Student record deleted');

        return back()->with('flash_success', __('msg.del_ok'));
    }


    /**
     * CSV export of the students list (Loop 8) — same class/section
     * filter as the list page, so what the office sees is what prints.
     */
    public function export($class_id, $section_id = NULL)
    {
        $mc = $this->my_class->getMC(['id' => $class_id])->first();
        if (is_null($mc)) {
            return Qs::goWithDanger();
        }

        // Batch S: the CSV is the same roster as listByClass — same scope.
        if (Qs::userIsTeacher() && !TeacherScope::canTeachClass(Auth::user(), $mc->id)) {
            abort(403, 'You can only view students in your assigned classes.');
        }

        $students = $this->student->findStudentsByClass($class_id)->load('section', 'my_parent');

        $section = NULL;
        if ($section_id) {
            $section = $this->my_class->getClassSections($class_id)->firstWhere('id', (int) $section_id);
            $students = $students->where('section_id', (int) $section_id);
        }

        // Loop 83 (additive): honour the list page's search box so the
        // CSV is exactly the filtered set the user is viewing — same
        // match rule as listByClass (name / admission no. / email).
        $q = trim((string) request('q'));
        if ($q !== '') {
            $students = $students->filter(function ($s) use ($q) {
                return stripos((string) optional($s->user)->name, $q) !== false
                    || stripos((string) $s->adm_no, $q) !== false
                    || stripos((string) optional($s->user)->email, $q) !== false;
            });
        }

        $rows = $students->map(function ($s) use ($mc) {
            return [
                optional($s->user)->name,
                $s->adm_no,
                $mc->name,
                optional($s->section)->name,
                optional($s->user)->gender,
                optional($s->my_parent)->name,
                optional($s->my_parent)->phone,
                optional($s->my_parent)->email,
            ];
        });

        $filename = 'students-' . Str::slug($mc->name)
            . ($section ? '-' . Str::slug($section->name) : '')
            . '.csv';

        return CsvExport::download($filename,
            ['Name', 'Admission No', 'Class', 'Section', 'Gender', 'Parent Name', 'Parent Phone', 'Parent Email'],
            $rows);
    }
}
