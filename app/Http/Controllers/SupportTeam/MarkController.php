<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Support\Audit;
use App\Support\MarkComputation;
use App\Support\MarkScheme;
use App\Support\TeacherScope;
use App\Helpers\Mk;
use App\Http\Requests\Mark\MarkComponentUpdate;
use App\Http\Requests\Mark\MarkSelector;
use App\Models\Setting;
use App\Repositories\ExamRepo;
use App\Repositories\MarkRepo;
use App\Repositories\MyClassRepo;
use App\Http\Controllers\Controller;
use App\Repositories\StudentRepo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class MarkController extends Controller
{
    protected $my_class, $exam, $student, $year, $user, $mark;

    public function __construct(MyClassRepo $my_class, ExamRepo $exam, StudentRepo $student, MarkRepo $mark)
    {
        $this->exam =  $exam;
        $this->mark =  $mark;
        $this->student =  $student;
        $this->my_class =  $my_class;
        $this->year =  Qs::getSetting('current_session');

       // $this->middleware('teamSAT', ['except' => ['show', 'year_selected', 'year_selector', 'print_view'] ]);
    }

    public function index()
    {
        $d['exams'] = $this->exam->getExam(['year' => $this->year]);
        $d['my_classes'] = $this->my_class->all();
        $d['sections'] = $this->my_class->getAllSections();
        $d['subjects'] = $this->my_class->getAllSubjects();
        $d['selected'] = false;

        // Batch S: a teacher's selector offers only their own
        // classes and subjects (manage() already re-filters subjects
        // per teacher; the landing pickers now match it).
        if(Qs::userIsTeacher()){
            $d['my_classes'] = $d['my_classes']->whereIn('id', TeacherScope::classIds(Auth::user()))->values();
            $d['subjects'] = $this->my_class->findSubjectByTeacher(Auth::user()->id);
        }

        return view('pages.support_team.marks.index', $d);
    }

    public function year_selector($student_id)
    {
        // Batch S: the year gateway used to render for ANY signed-in
        // user (audit Addendum 2) — it is a marks surface, so the
        // ownership/scope check below now guards it too.
        if(!$this->canAccessStudentMarks($student_id)){
            abort(403, 'You can only view marks for students in your own scope.');
        }

       return $this->verifyStudentExamYear($student_id);
    }

    public function year_selected(Request $req, $student_id)
    {
        if(!$this->canAccessStudentMarks($student_id)){
            abort(403, 'You can only view marks for students in your own scope.');
        }

        if(!$this->verifyStudentExamYear($student_id, $req->year)){
            return $this->noStudentRecord();
        }

        $student_id = Qs::hash($student_id);
        return redirect()->route('marks.show', [$student_id, $req->year]);
    }

    public function show($student_id, $year)
    {
        /* Prevent Other Students/Parents from viewing Result of others */
        if(Auth::user()->id != $student_id && !Qs::userIsTeamSAT() && !Qs::userIsMyChild($student_id, Auth::user()->id)){
            return redirect(route('dashboard'))->with('pop_error', __('msg.denied'));
        }

        /* Batch S: teamSAT membership is not enough for a teacher —
           the student's class must be one of their assigned classes. */
        if(Qs::userIsTeacher()){
            $sr = $this->student->getRecord(['user_id' => $student_id])->first();
            if(!$sr || !TeacherScope::canTeachClass(Auth::user(), $sr->my_class_id)){
                return redirect(route('dashboard'))->with('pop_error', __('msg.denied'));
            }
        }

        if(Mk::examIsLocked() && !Qs::userIsTeamSA()){
            Session::put('marks_url', route('marks.show', [Qs::hash($student_id), $year]));

            if(!$this->checkPinVerified($student_id)){
                return redirect()->route('pins.enter', Qs::hash($student_id));
            }
        }

        if(!$this->verifyStudentExamYear($student_id, $year)){
            return $this->noStudentRecord();
        }

        $wh = ['student_id' => $student_id, 'year' => $year ];
        $d['marks'] = $this->exam->getMark($wh);
        $d['exam_records'] = $exr = $this->exam->getRecord($wh);
        $d['exams'] = $this->exam->getExam(['year' => $year]);
        $d['sr'] = $this->student->getRecord(['user_id' => $student_id])->first();
        $d['my_class'] = $mc = $this->my_class->getMC(['id' => $exr->first()->my_class_id])->first();
        $d['class_type'] = $this->my_class->findTypeByClass($mc->id);
        $d['subjects'] = $this->my_class->findSubjectByClass($mc->id);
        $d['year'] = $year;
        $d['student_id'] = $student_id;
        $d['skills'] = $this->exam->getSkillByClassType() ?: NULL;
        //$d['ct'] = $d['class_type']->code;
        //$d['mark_type'] = Qs::getMarkType($d['ct']);

        return view('pages.support_team.marks.show.index', $d);
    }

    public function print_view($student_id, $exam_id, $year)
    {
        /* Prevent Other Students/Parents from viewing Result of others */
        if(Auth::user()->id != $student_id && !Qs::userIsTeamSA() && !Qs::userIsMyChild($student_id, Auth::user()->id)){
            return redirect(route('dashboard'))->with('pop_error', __('msg.denied'));
        }

        if(Mk::examIsLocked() && !Qs::userIsTeamSA()){
            Session::put('marks_url', route('marks.show', [Qs::hash($student_id), $year]));

            if(!$this->checkPinVerified($student_id)){
                return redirect()->route('pins.enter', Qs::hash($student_id));
            }
        }

        if(!$this->verifyStudentExamYear($student_id, $year)){
            return $this->noStudentRecord();
        }

        $wh = ['student_id' => $student_id, 'exam_id' => $exam_id, 'year' => $year ];
        $d['marks'] = $mks = $this->exam->getMark($wh);
        $d['exr'] = $exr = $this->exam->getRecord($wh)->first();
        $d['my_class'] = $mc = $this->my_class->find($exr->my_class_id);
        $d['section_id'] = $exr->section_id;
        $d['ex'] = $exam = $this->exam->find($exam_id);
        $d['tex'] = 'tex'.$exam->term;
        $d['sr'] = $sr =$this->student->getRecord(['user_id' => $student_id])->first();
        $d['class_type'] = $this->my_class->findTypeByClass($mc->id);
        $d['subjects'] = $this->my_class->findSubjectByClass($mc->id);

        $d['ct'] = $ct = $d['class_type']->code;
        $d['year'] = $year;
        $d['student_id'] = $student_id;
        $d['exam_id'] = $exam_id;

        $d['skills'] = $this->exam->getSkillByClassType() ?: NULL;
        $d['s'] = Setting::all()->flatMap(function($s){
            return [$s->type => $s->description];
        });

        //$d['mark_type'] = Qs::getMarkType($ct);

        return view('pages.support_team.marks.print.index', $d);
    }

    public function selector(MarkSelector $req)
    {
        // Batch S: marks entry is scoped to a teacher's assigned classes.
        if(Qs::userIsTeacher() && !TeacherScope::canTeachClass(Auth::user(), $req->my_class_id)){
            abort(403, 'You can only manage marks for your assigned classes.');
        }

        $data = $req->only(['exam_id', 'my_class_id', 'section_id', 'subject_id']);
        $d2 = $req->only(['exam_id', 'my_class_id', 'section_id']);
        $d = $req->only(['my_class_id', 'section_id']);
        $d['session'] = $data['year'] = $d2['year'] = $this->year;

        $students = $this->student->getRecord($d)->get();
        if($students->count() < 1){
            return back()->with('pop_error', __('msg.rnf'));
        }

        foreach ($students as $s){
            $data['student_id'] = $d2['student_id'] = $s->user_id;
            $this->exam->createMark($data);
            $this->exam->createRecord($d2);
        }

        return redirect()->route('marks.manage', [$req->exam_id, $req->my_class_id, $req->section_id, $req->subject_id]);
    }

    public function manage($exam_id, $class_id, $section_id, $subject_id)
    {
        // Batch S: marks entry is scoped to a teacher's assigned classes.
        if(Qs::userIsTeacher() && !TeacherScope::canTeachClass(Auth::user(), $class_id)){
            abort(403, 'You can only manage marks for your assigned classes.');
        }

        $d = ['exam_id' => $exam_id, 'my_class_id' => $class_id, 'section_id' => $section_id, 'subject_id' => $subject_id, 'year' => $this->year];

        $d['marks'] = $this->exam->getMark($d);
        if($d['marks']->count() < 1){
            return $this->noStudentRecord();
        }

        $d['m'] =  $d['marks']->first();

        // Batch M2: the entry grid renders this subject's marking
        // scheme — configured components, or the legacy CA/Exam grid
        // when the subject has no scheme configured.
        $d['scheme'] = MarkScheme::forExamSubject($exam_id, $subject_id);
        if($d['scheme']['configured']){
            $d['marks']->load('markComponents');
        }

        $d['exams'] = $this->exam->all();
        $d['my_classes'] = $this->my_class->all();
        $d['sections'] = $this->my_class->getAllSections();
        $d['subjects'] = $this->my_class->getAllSubjects();
        if(Qs::userIsTeacher()){
            $d['subjects'] = $this->my_class->findSubjectByTeacher(Auth::user()->id)->where('my_class_id', $class_id);
        }
        $d['selected'] = true;
        $d['class_type'] = $this->my_class->findTypeByClass($class_id);

        return view('pages.support_team.marks.manage', $d);
    }

    public function update(MarkComponentUpdate $req, $exam_id, $class_id, $section_id, $subject_id)
    {
        // Batch S: marks entry is scoped to a teacher's assigned classes.
        if(Qs::userIsTeacher() && !TeacherScope::canTeachClass(Auth::user(), $class_id)){
            abort(403, 'You can only manage marks for your assigned classes.');
        }

        $p = ['exam_id' => $exam_id, 'my_class_id' => $class_id, 'section_id' => $section_id, 'subject_id' => $subject_id, 'year' => $this->year];

        $exam = $this->exam->find($exam_id);
        $marks = $this->exam->getMark($p);

        /* Batch M2: raw scores land first — in mark_components for a
           configured scheme, in the legacy columns otherwise. Blank
           means "not entered" (stored NULL); 0 is a real score. The
           FormRequest has already proven every cell is a whole number
           within its component's max — invalid saves are rejected,
           never silently wiped (the legacy >100 wipe is deleted). */
        if(MarkScheme::isConfigured($exam_id, $subject_id)){
            $components = MarkScheme::configuredComponents($exam_id, $subject_id);

            foreach($marks as $mk){
                $scores = [];
                foreach($components as $comp){
                    $v = $req->input('comp_'.$mk->id.'_'.$comp->id);
                    $scores[$comp->id] = ($v === NULL || $v === '') ? NULL : (int) $v;
                }
                MarkScheme::saveComponentScores($mk, $scores);
            }
        }
        else{
            foreach($marks as $mk){
                $d = [];
                foreach(['t1', 't2', 'exm'] as $field){
                    $v = $req->input($field.'_'.$mk->id);
                    $d[$field] = ($v === NULL || $v === '') ? NULL : (int) $v;
                }
                $d['tca'] = ($d['t1'] === NULL && $d['t2'] === NULL) ? NULL : (int) $d['t1'] + (int) $d['t2'];

                $this->exam->updateMark($mk->id, $d);
            }
        }

        /* Totals, grades, subject positions and exam records all flow
           through the one computation pipeline (dual-write of tex). */
        MarkComputation::recomputeSubject($exam, $class_id, $section_id, $subject_id, $this->year);
        MarkComputation::recomputeRecords($exam, $class_id, $section_id, $this->year);

        Audit::log('marks.updated', null, 'Marks updated: exam '.$exam_id.', class '.$class_id.', subject '.$subject_id);

       return Qs::jsonUpdateOk();
    }

    public function batch_fix()
    {
        $d['exams'] = $this->exam->getExam(['year' => $this->year]);
        $d['my_classes'] = $this->my_class->all();
        $d['sections'] = $this->my_class->getAllSections();
        $d['selected'] = false;

        return view('pages.support_team.marks.batch_fix', $d);
    }

    public function batch_update(Request $req): \Illuminate\Http\JsonResponse
    {
        $exam_id = $req->exam_id;
        $class_id = $req->my_class_id;
        $section_id = $req->section_id;

        $exam = $this->exam->find($exam_id);

        /* Batch M2: the batch fix now runs through the same single
           computation pipeline as the entry save (grades re-derived
           from the stored tex totals, records recomputed). */
        MarkComputation::fixClass($exam, $class_id, $section_id, $this->year);

        return Qs::jsonUpdateOk();
    }

    public function comment_update(Request $req, $exr_id)
    {
        // Batch S: a teacher comments only on their own classes' records.
        if(Qs::userIsTeacher()){
            $exr = $this->exam->getRecord(['id' => $exr_id])->first();
            if($exr && !TeacherScope::canTeachClass(Auth::user(), $exr->my_class_id)){
                abort(403, 'You can only manage marks for your assigned classes.');
            }
        }

        $d = Qs::userIsTeamSA() ? $req->only(['t_comment', 'p_comment']) : $req->only(['t_comment']);

        $this->exam->updateRecord(['id' => $exr_id], $d);
        return Qs::jsonUpdateOk();
    }

    public function skills_update(Request $req, $skill, $exr_id)
    {
        // Batch S: a teacher updates skills only on their own classes' records.
        if(Qs::userIsTeacher()){
            $exr = $this->exam->getRecord(['id' => $exr_id])->first();
            if($exr && !TeacherScope::canTeachClass(Auth::user(), $exr->my_class_id)){
                abort(403, 'You can only manage marks for your assigned classes.');
            }
        }

        $d = [];
        if($skill == 'AF' || $skill == 'PS'){
            $sk = strtolower($skill);
            $d[$skill] = implode(',', $req->$sk);
        }

        $this->exam->updateRecord(['id' => $exr_id], $d);
        return Qs::jsonUpdateOk();
    }

    public function bulk($class_id = NULL, $section_id = NULL)
    {
        $d['my_classes'] = $this->my_class->all();

        // Batch S: bulk marks is scoped to a teacher's assigned classes.
        if(Qs::userIsTeacher()){
            if($class_id && !TeacherScope::canTeachClass(Auth::user(), $class_id)){
                abort(403, 'You can only manage marks for your assigned classes.');
            }
            $d['my_classes'] = $d['my_classes']->whereIn('id', TeacherScope::classIds(Auth::user()))->values();
        }

        $d['selected'] = false;

        if($class_id && $section_id){
            $d['sections'] = $this->my_class->getAllSections()->where('my_class_id', $class_id);
            $d['students'] = $st = $this->student->getRecord(['my_class_id' => $class_id, 'section_id' => $section_id])->get()->sortBy('user.name');
            if($st->count() < 1){
                return redirect()->route('marks.bulk')->with('flash_danger', __('msg.srnf'));
            }
            $d['selected'] = true;
            $d['my_class_id'] = $class_id;
            $d['section_id'] = $section_id;
        }

        return view('pages.support_team.marks.bulk', $d);
    }

    public function bulk_select(Request $req)
    {
        return redirect()->route('marks.bulk', [$req->my_class_id, $req->section_id]);
    }

    public function tabulation($exam_id = NULL, $class_id = NULL, $section_id = NULL)
    {
        // Batch S: tabulation routes already sit behind teamSA; this is
        // the in-controller half of the same rule should a teacher ever
        // reach here — a tabulation sheet covers a whole class, so it
        // must be one of the teacher's assigned classes.
        if($class_id && Qs::userIsTeacher() && !TeacherScope::canTeachClass(Auth::user(), $class_id)){
            abort(403, 'You can only view tabulation for your assigned classes.');
        }

        $d['my_classes'] = $this->my_class->all();
        $d['exams'] = $this->exam->getExam(['year' => $this->year]);
        $d['selected'] = FALSE;

        if($class_id && $exam_id && $section_id){

            $wh = ['my_class_id' => $class_id, 'section_id' => $section_id, 'exam_id' => $exam_id, 'year' => $this->year];

            $sub_ids = $this->mark->getSubjectIDs($wh);
            $st_ids = $this->mark->getStudentIDs($wh);

            if(count($sub_ids) < 1 OR count($st_ids) < 1) {
                return Qs::goWithDanger('marks.tabulation', __('msg.srnf'));
            }

            $d['subjects'] = $this->my_class->getSubjectsByIDs($sub_ids);
            $d['students'] = $this->student->getRecordByUserIDs($st_ids)->get()->sortBy('user.name');
            $d['sections'] = $this->my_class->getAllSections();

            $d['selected'] = TRUE;
            $d['my_class_id'] = $class_id;
            $d['section_id'] = $section_id;
            $d['exam_id'] = $exam_id;
            $d['year'] = $this->year;
            $d['marks'] = $mks = $this->exam->getMark($wh);
            $d['exr'] = $exr = $this->exam->getRecord($wh);

            $d['my_class'] = $mc = $this->my_class->find($class_id);
            $d['section']  = $this->my_class->findSection($section_id);
            $d['ex'] = $exam = $this->exam->find($exam_id);
            $d['tex'] = 'tex'.$exam->term;
            //$d['class_type'] = $this->my_class->findTypeByClass($mc->id);
            //$d['ct'] = $ct = $d['class_type']->code;
        }

        return view('pages.support_team.marks.tabulation.index', $d);
    }

    public function print_tabulation($exam_id, $class_id, $section_id)
    {
        // Batch S: see tabulation() — same class-scope rule for teachers.
        if(Qs::userIsTeacher() && !TeacherScope::canTeachClass(Auth::user(), $class_id)){
            abort(403, 'You can only view tabulation for your assigned classes.');
        }

        $wh = ['my_class_id' => $class_id, 'section_id' => $section_id, 'exam_id' => $exam_id, 'year' => $this->year];

        $sub_ids = $this->mark->getSubjectIDs($wh);
        $st_ids = $this->mark->getStudentIDs($wh);

        if(count($sub_ids) < 1 OR count($st_ids) < 1) {
            return Qs::goWithDanger('marks.tabulation', __('msg.srnf'));
        }

        $d['subjects'] = $this->my_class->getSubjectsByIDs($sub_ids);
        $d['students'] = $this->student->getRecordByUserIDs($st_ids)->get()->sortBy('user.name');

        $d['my_class_id'] = $class_id;
        $d['exam_id'] = $exam_id;
        $d['year'] = $this->year;
        $wh = ['exam_id' => $exam_id, 'my_class_id' => $class_id];
        $d['marks'] = $mks = $this->exam->getMark($wh);
        $d['exr'] = $exr = $this->exam->getRecord($wh);

        $d['my_class'] = $mc = $this->my_class->find($class_id);
        $d['section']  = $this->my_class->findSection($section_id);
        $d['ex'] = $exam = $this->exam->find($exam_id);
        $d['tex'] = 'tex'.$exam->term;
        $d['s'] = Setting::all()->flatMap(function($s){
            return [$s->type => $s->description];
        });
        //$d['class_type'] = $this->my_class->findTypeByClass($mc->id);
        //$d['ct'] = $ct = $d['class_type']->code;

        return view('pages.support_team.marks.tabulation.print', $d);
    }

    public function tabulation_select(Request $req)
    {
        return redirect()->route('marks.tabulation', [$req->exam_id, $req->my_class_id, $req->section_id]);
    }

    /**
     * Batch S: who may open a student's marks surfaces at all — the
     * student themselves, their parent, teamSA staff, or a teacher
     * whose assigned classes include the student's class. This is the
     * check show() has always applied (plus the teacher-scope half);
     * the select-year gateway now applies it too, so an unrelated
     * student, another parent, the accountant or the librarian can no
     * longer reach even a student's exam-year metadata.
     */
    protected function canAccessStudentMarks($student_id)
    {
        $user = Auth::user();

        if ((int) $user->id === (int) $student_id) {
            return true;
        }

        if (Qs::userIsTeamSA()) {
            return true;
        }

        if (Qs::userIsMyChild($student_id, $user->id)) {
            return true;
        }

        if (Qs::userIsTeacher()) {
            return TeacherScope::canTeachStudent($user, $student_id);
        }

        return false;
    }

    protected function verifyStudentExamYear($student_id, $year = null)
    {
        $years = $this->exam->getExamYears($student_id);
        $student_exists = $this->student->exists($student_id);

        if(!$year){
            if($student_exists && $years->count() > 0)
            {
                $d =['years' => $years, 'student_id' => Qs::hash($student_id)];

                return view('pages.support_team.marks.select_year', $d);
            }

            return $this->noStudentRecord();
        }

        return ($student_exists && $years->contains('year', $year)) ? true  : false;
    }

    protected function noStudentRecord()
    {
        return redirect()->route('dashboard')->with('flash_danger', __('msg.srnf'));
    }

    protected function checkPinVerified($st_id)
    {
        return Session::has('pin_verified') && Session::get('pin_verified') == $st_id;
    }

}
