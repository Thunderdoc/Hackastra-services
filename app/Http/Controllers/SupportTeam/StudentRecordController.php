<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Support\Audit;
use App\Support\CsvExport;
use App\Support\TeacherScope;
use App\Helpers\Mk;
use App\Http\Requests\Student\StudentRecordCreate;
use App\Http\Requests\Student\StudentRecordUpdate;
use App\Repositories\LocationRepo;
use App\Repositories\MyClassRepo;
use App\Repositories\StudentRepo;
use App\Repositories\UserRepo;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StudentRecordController extends Controller
{
    protected $loc, $my_class, $user, $student;

   public function __construct(LocationRepo $loc, MyClassRepo $my_class, UserRepo $user, StudentRepo $student)
   {
       $this->middleware('teamSA', ['only' => ['edit','update', 'reset_pass', 'create', 'store', 'graduated'] ]);
       $this->middleware('teamSA', ['only' => ['destroy',] ]);

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
        $data['username'] = strtoupper(Qs::getAppCode().'/'.$ct.'/'.$sr['year_admitted'].'/'.($adm_no ?: mt_rand(1000, 99999)));

        if($req->hasFile('photo')) {
            $photo = $req->file('photo');
            $f = Qs::getFileMetaData($photo);
            $f['name'] = 'photo.' . $f['ext'];
            $f['path'] = $photo->storeAs(Qs::getUploadPath('student').$data['code'], $f['name']);
            $data['photo'] = asset('storage/' . $f['path']);
        }

        $user = $this->user->create($data); // Create User

        $sr['adm_no'] = $data['username'];
        $sr['user_id'] = $user->id;
        $sr['session'] = Qs::getSetting('current_session');

        $this->student->createRecord($sr); // Create Student
        return Qs::jsonStoreOk();
    }

    public function listByClass($class_id)
    {
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

        return view('pages.support_team.students.list', $data);
    }

    public function graduated()
    {
        $data['my_classes'] = $this->my_class->all();
        $data['students'] = $this->student->allGradStudents();

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

        return Qs::jsonUpdateOk();
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
