<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Support\Audit;
use App\Support\MarkComputation;
use App\Support\MarkScheme;
use App\Support\ReportCard;
use App\Support\TeacherScope;
use App\Models\Section;
use App\Models\Subject;
use App\Http\Requests\Mark\MarkComponentUpdate;
use App\Http\Requests\Mark\MarkSelector;
use App\Models\Setting;
use App\Repositories\ExamRepo;
use PDF;
use App\Repositories\MarkRepo;
use App\Repositories\MyClassRepo;
use App\Http\Controllers\Controller;
use App\Repositories\StudentRepo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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

    /**
     * Fee-defaulter document block (owner ruling 2026-10-22): a
     * student whose current-session fees are outstanding — or their
     * parent — cannot generate the marksheet (term or annual; screen,
     * print or PDF) until the dues are paid. The definition is
     * App\Support\FeeDefaulter's, the very one the Fee Defaulters
     * page lists, so the block lifts itself the moment the balance
     * clears. teamSA (the school office) generation is never
     * blocked, and the notice view receives no marks data at all.
     * Returns the notice response, or null when generation proceeds.
     */
    protected function feeDefaulterBlock($student_id, $document)
    {
        if (Qs::userIsTeamSA()) {
            return null;
        }

        $due = \App\Support\FeeDefaulter::outstandingFor($student_id, Qs::getCurrentSession());
        if ($due <= 0) {
            return null;
        }

        return view('pages.support_team.fee_blocked', [
            'document' => $document,
            'sr' => $this->student->getRecord(['user_id' => $student_id])->first(),
            'due' => $due,
            'year' => Qs::getCurrentSession(),
            'viewer_is_parent' => Qs::userIsMyChild($student_id, Auth::user()->id),
        ]);
    }

    /* Map a stored upload URL (/storage/...) to a base64 data URI read
       from the local file, for renderers that cannot fetch URLs
       (dompdf). Anything that is not a local storage file is returned
       unchanged. */
    protected function pdfImageDataUri($url)
    {
        $path = parse_url($url, PHP_URL_PATH);
        if ($path && \Illuminate\Support\Str::startsWith($path, '/storage/')) {
            $local = storage_path('app/public/' . \Illuminate\Support\Str::after($path, '/storage/'));
            if (is_file($local)) {
                $mime = mime_content_type($local) ?: 'image/png';
                return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($local));
            }
        }
        return $url;
    }

    public function show($student_id, $year)
    {
        /* Prevent Other Students/Parents from viewing Result of others.
           Owner ruling 2026-10-22: the marksheet is a student/office
           document — teachers no longer open it at all (they keep
           marks ENTRY). The gate is therefore teamSA, not teamSAT:
           a teacher fails this check like any unrelated user. */
        if(Auth::user()->id != $student_id && !Qs::userIsTeamSA() && !Qs::userIsMyChild($student_id, Auth::user()->id)){
            return redirect(route('dashboard'))->with('pop_error', __('msg.denied'));
        }

        if ($block = $this->feeDefaulterBlock($student_id, 'Marksheet')) {
            return $block;
        }

        if(!$this->verifyStudentExamYear($student_id, $year)){
            return $this->noStudentRecord();
        }

        $wh = ['student_id' => $student_id, 'year' => $year ];
        $d['marks'] = $this->exam->getMark($wh);
        $d['exam_records'] = $exr = $this->exam->getRecord($wh);
        $d['exams'] = $this->exam->getExam(['year' => $year]);

        /* Workstream BH — Result Publish gate (replaces the retired
           PIN gate): students and parents see only exams whose results
           the school has published from the Exam List. Staff (teamSAT)
           are never filtered. Unpublished exams' marks/records never
           reach the view, and when nothing is published the honest
           not-published page renders instead of an empty sheet. */
        if(!Qs::userIsTeamSAT()){
            $published_ids = $d['exams']->filter(function($ex){ return $ex->results_published; })->pluck('id');
            $d['exams'] = $d['exams']->whereIn('id', $published_ids);
            $d['exam_records'] = $exr = $d['exam_records']->whereIn('exam_id', $published_ids);
            $d['marks'] = $d['marks']->whereIn('exam_id', $published_ids);

            if($d['exams']->isEmpty() || $d['exam_records']->isEmpty()){
                return view('pages.support_team.marks.not_published', [
                    'sr' => $this->student->getRecord(['user_id' => $student_id])->first(),
                    'year' => $year,
                ]);
            }
        }

        $d['sr'] = $sr = $this->student->getRecord(['user_id' => $student_id])->first();
        $d['my_class'] = $mc = $this->my_class->getMC(['id' => $exr->first()->my_class_id])->first();
        $d['class_type'] = $this->my_class->findTypeByClass($mc->id);
        $d['subjects'] = $this->my_class->findSubjectByClass($mc->id);
        $d['year'] = $year;
        $d['student_id'] = $student_id;
        $d['skills'] = $this->exam->getSkillByClassType() ?: NULL;
        $d['ct'] = $d['class_type']->code;
        $d['s'] = Setting::all()->flatMap(function($s){
            return [$s->type => $s->description];
        });

        /* Batch M3: one report-card assembly per exam, shared with
           the printed document (print_view) — screen and print are
           the same template over this same data. */
        $d['cards'] = [];
        foreach ($d['exams'] as $ex) {
            $d['cards'][$ex->id] = ReportCard::build($sr, $ex, $d['subjects'], $d['marks']);
        }
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

        if ($block = $this->feeDefaulterBlock($student_id, 'Marksheet')) {
            return $block;
        }

        /* Workstream BH — Result Publish gate (replaces the retired
           PIN gate): a student or parent may print only a published
           exam's result. teamSA staff printing is never gated. */
        $gate_exam = $this->exam->find($exam_id);
        if(!Qs::userIsTeamSA() && $gate_exam && !$gate_exam->results_published){
            return view('pages.support_team.marks.not_published', [
                'sr' => $this->student->getRecord(['user_id' => $student_id])->first(),
                'exam' => $gate_exam,
                'year' => $year,
            ]);
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

        /* Batch M3: the printed document renders the same report
           card as the on-screen marksheet — one assembly, one
           template (marks/partials/report_card). */
        $d['card'] = ReportCard::build($sr, $exam, $d['subjects'], $mks);

        //$d['mark_type'] = Qs::getMarkType($ct);

        return view('pages.support_team.marks.print.index', $d);
    }

    /**
     * Batch M4 — the annual / cumulative report card for a session:
     * one card over every term's totals (ReportCard::buildAnnual).
     * Gating mirrors show(): self / teamSAT / my-child, plus the
     * Batch S teacher scope. BH publish gate (replacing BO's retired
     * PIN gate): students and parents see only published exams'
     * terms in the annual figures; staff are never filtered, and
     * when nothing is published the not-published page renders.
     */
    public function show_annual($student_id, $year)
    {
        /* Prevent Other Students/Parents from viewing Result of others.
           Owner ruling 2026-10-22 (see show()): teachers are denied
           the annual report card exactly like the term marksheet. */
        if(Auth::user()->id != $student_id && !Qs::userIsTeamSA() && !Qs::userIsMyChild($student_id, Auth::user()->id)){
            return redirect(route('dashboard'))->with('pop_error', __('msg.denied'));
        }

        if ($block = $this->feeDefaulterBlock($student_id, 'Annual Report Card')) {
            return $block;
        }

        if(!$this->verifyStudentExamYear($student_id, $year)){
            return $this->noStudentRecord();
        }

        $exams = $this->exam->getExam(['year' => $year]);
        if(!Qs::userIsTeamSAT()){
            $exams = $exams->filter(function($ex){ return $ex->results_published; });
            if($exams->isEmpty()){
                return view('pages.support_team.marks.not_published', [
                    'sr' => $this->student->getRecord(['user_id' => $student_id])->first(),
                    'year' => $year,
                ]);
            }
        }

        $d = $this->annualCardData($student_id, $year, $exams);

        return view('pages.support_team.marks.show.annual', $d);
    }

    /**
     * Batch M4 — the printed annual / cumulative report. Gating is
     * print_view()'s (teamSA print permission), with the BH publish
     * gate for non-teamSA viewers: only published terms count toward
     * the printed annual figures, and with nothing published the
     * not-published page renders instead.
     */
    public function print_annual($student_id, $year)
    {
        /* Prevent Other Students/Parents from viewing Result of others */
        if(Auth::user()->id != $student_id && !Qs::userIsTeamSA() && !Qs::userIsMyChild($student_id, Auth::user()->id)){
            return redirect(route('dashboard'))->with('pop_error', __('msg.denied'));
        }

        if ($block = $this->feeDefaulterBlock($student_id, 'Annual Report Card')) {
            return $block;
        }

        if(!$this->verifyStudentExamYear($student_id, $year)){
            return $this->noStudentRecord();
        }

        $exams = $this->exam->getExam(['year' => $year]);
        if(!Qs::userIsTeamSA()){
            $exams = $exams->filter(function($ex){ return $ex->results_published; });
            if($exams->isEmpty()){
                return view('pages.support_team.marks.not_published', [
                    'sr' => $this->student->getRecord(['user_id' => $student_id])->first(),
                    'year' => $year,
                ]);
            }
        }

        $d = $this->annualCardData($student_id, $year, $exams);

        return view('pages.support_team.marks.print.annual', $d);
    }

    /**
     * Loop 85 — the annual / cumulative report as a downloadable PDF.
     * Gating is print_annual()'s, verbatim (teamSA print permission,
     * self / my-child, plus the BH publish gate for non-teamSA
     * viewers), and the document is the SAME annual_report_card
     * partial fed by the SAME annualCardData assembly — screen,
     * print and PDF can therefore never disagree on a figure.
     */
    public function pdf_annual($student_id, $year)
    {
        /* Prevent Other Students/Parents from viewing Result of others */
        if(Auth::user()->id != $student_id && !Qs::userIsTeamSA() && !Qs::userIsMyChild($student_id, Auth::user()->id)){
            return redirect(route('dashboard'))->with('pop_error', __('msg.denied'));
        }

        /* The defaulter block answers with the notice page rather
           than a PDF — the same response print_annual's publish gate
           already gives in that situation. */
        if ($block = $this->feeDefaulterBlock($student_id, 'Annual Report Card')) {
            return $block;
        }

        if(!$this->verifyStudentExamYear($student_id, $year)){
            return $this->noStudentRecord();
        }

        $exams = $this->exam->getExam(['year' => $year]);
        if(!Qs::userIsTeamSA()){
            $exams = $exams->filter(function($ex){ return $ex->results_published; });
            if($exams->isEmpty()){
                return view('pages.support_team.marks.not_published', [
                    'sr' => $this->student->getRecord(['user_id' => $student_id])->first(),
                    'year' => $year,
                ]);
            }
        }

        $d = $this->annualCardData($student_id, $year, $exams);

        /* dompdf in this stack does not fetch asset() URLs (remote
           images disabled), so a PDF rendered straight from the card
           data silently drops every image — logo, stamp and, since the
           2026-10-22 digital-signature ruling, the principal's
           signature. The PDF path therefore inlines those three from
           local storage as data URIs; screen/print keep the URLs. */
        foreach (['stamp', 'principal_signature'] as $imgKey) {
            if (!empty($d['card'][$imgKey])) {
                $d['card'][$imgKey] = $this->pdfImageDataUri($d['card'][$imgKey]);
            }
        }
        if (!empty($d['s']['logo'])) {
            $d['s']['logo'] = $this->pdfImageDataUri($d['s']['logo']);
        }

        $pdf_name = 'Annual_Report_'.\Illuminate\Support\Str::slug($d['sr']->user->name).'_'.$year.'.pdf';

        return PDF::loadView('pages.support_team.marks.print.annual_pdf', $d)->setPaper('a4')->download($pdf_name);
    }

    /**
     * Batch M4 — shared data assembly for the two annual surfaces:
     * the student's year marks + the whole class's year marks (the
     * peer group the assembler's positions rank against), fed to
     * ReportCard::buildAnnual. When a publish-filtered $exams set is
     * passed, marks are restricted to those exams so an unpublished
     * term never leaks into the annual figures.
     */
    protected function annualCardData($student_id, $year, $exams = null)
    {
        $wh = ['student_id' => $student_id, 'year' => $year];
        $d['marks'] = $this->exam->getMark($wh);
        $d['exams'] = $exams ?: $this->exam->getExam(['year' => $year]);
        $d['sr'] = $sr = $this->student->getRecord(['user_id' => $student_id])->first();
        $d['my_class'] = $mc = $this->my_class->getMC(['id' => $sr->my_class_id])->first();
        $d['class_type'] = $this->my_class->findTypeByClass($mc->id);
        $d['subjects'] = $this->my_class->findSubjectByClass($mc->id);
        $d['year'] = $year;
        $d['student_id'] = $student_id;
        $d['ct'] = $d['class_type']->code;
        $d['s'] = Setting::all()->flatMap(function($s){
            return [$s->type => $s->description];
        });

        $classMarks = $this->exam->getMark(['my_class_id' => $mc->id, 'year' => $year]);
        if($exams){
            $ids = $d['exams']->pluck('id');
            $d['marks'] = $d['marks']->whereIn('exam_id', $ids);
            $classMarks = $classMarks->whereIn('exam_id', $ids);
        }
        $d['card'] = ReportCard::buildAnnual($sr, $year, $d['exams'], $d['subjects'], $d['marks'], $classMarks, $d['class_type']->id);

        return $d;
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

        /* Batch 114 (Part A audit): snapshot the stored scores BEFORE
           the save so the audit entry below can name every changed
           cell with its old -> new value. The pre-114 entry recorded
           only that "marks updated" — no values, which made a disputed
           mark untraceable. */
        $oldScores = [];
        if(MarkScheme::isConfigured($exam_id, $subject_id)){
            foreach($marks as $mk){
                foreach($mk->markComponents as $mc){
                    $oldScores[$mk->id][$mc->component_id] = $mc->score === NULL ? NULL : (int) $mc->score;
                }
            }
        }
        else{
            foreach($marks as $mk){
                foreach(['t1', 't2', 'exm'] as $field){
                    $oldScores[$mk->id][$field] = $mk->$field === NULL ? NULL : (int) $mk->$field;
                }
            }
        }

        /* Batch M2: raw scores land first — in mark_components for a
           configured scheme, in the legacy columns otherwise. Blank
           means "not entered" (stored NULL); 0 is a real score. The
           FormRequest has already proven every cell is a whole number
           within its component's max — invalid saves are rejected,
           never silently wiped (the legacy >100 wipe is deleted). */
        $changes = [];
        $fmtVal = function($v){ return $v === NULL ? 'blank' : (string) $v; };
        if(MarkScheme::isConfigured($exam_id, $subject_id)){
            $components = MarkScheme::configuredComponents($exam_id, $subject_id);

            foreach($marks as $mk){
                $scores = [];
                foreach($components as $comp){
                    $v = $req->input('comp_'.$mk->id.'_'.$comp->id);
                    $scores[$comp->id] = ($v === NULL || $v === '') ? NULL : (int) $v;
                    $old = $oldScores[$mk->id][$comp->id] ?? NULL;
                    if($old !== $scores[$comp->id]){
                        $changes[] = $mk->user->name.' — '.$comp->name.': '.$fmtVal($old).' -> '.$fmtVal($scores[$comp->id]);
                    }
                }
                MarkScheme::saveComponentScores($mk, $scores);
            }
        }
        else{
            $legacyNames = ['t1' => '1st CA', 't2' => '2nd CA', 'exm' => 'Exam'];
            foreach($marks as $mk){
                $d = [];
                foreach(['t1', 't2', 'exm'] as $field){
                    $v = $req->input($field.'_'.$mk->id);
                    $d[$field] = ($v === NULL || $v === '') ? NULL : (int) $v;
                    $old = $oldScores[$mk->id][$field] ?? NULL;
                    if($old !== $d[$field]){
                        $changes[] = $mk->user->name.' — '.$legacyNames[$field].': '.$fmtVal($old).' -> '.$fmtVal($d[$field]);
                    }
                }
                $d['tca'] = ($d['t1'] === NULL && $d['t2'] === NULL) ? NULL : (int) $d['t1'] + (int) $d['t2'];

                $this->exam->updateMark($mk->id, $d);
            }
        }

        /* Totals, grades, subject positions and exam records all flow
           through the one computation pipeline (dual-write of tex). */
        MarkComputation::recomputeSubject($exam, $class_id, $section_id, $subject_id, $this->year);
        MarkComputation::recomputeRecords($exam, $class_id, $section_id, $this->year);

        $auditMsg = 'Marks updated: exam '.$exam_id.', class '.$class_id.', subject '.$subject_id;
        if($changes){
            $shown = array_slice($changes, 0, 40);
            $auditMsg .= '. Changes: '.implode('; ', $shown);
            if(count($changes) > 40){
                $auditMsg .= '; … and '.(count($changes) - 40).' more';
            }
        }
        Audit::log('marks.updated', null, $auditMsg);

       return Qs::jsonUpdateOk();
    }

    /* batch_fix() / batch_update() removed 2026-10-23 (owner decision):
       the standalone Marks Correction page is gone. The recompute
       pipeline it called (MarkComputation::fixClass and friends) is
       untouched — marks entry above and the assignment internal-marks
       push still recompute through it. */

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
        // Owner grant 2026-10-25: the assigned class teacher reads
        // their own students' tabulation sheet (routes now sit
        // behind teamSAT; print stays teamSA). A teacher sees only
        // their assigned classes, and only their sections within
        // them — a subject teacher sees every section of the classes
        // they teach; a pure class teacher sees only the sections
        // they lead.
        if($class_id && Qs::userIsTeacher() && !TeacherScope::canTeachClass(Auth::user(), $class_id)){
            abort(403, 'You can only view tabulation for your assigned classes.');
        }
        if($section_id && Qs::userIsTeacher() && !in_array((int) $section_id, $this->teacherSectionIds(Auth::user(), $class_id), true)){
            abort(403, 'You can only view tabulation for your assigned sections.');
        }

        $d['my_classes'] = $this->my_class->all();
        $d['exams'] = $this->exam->getExam(['year' => $this->year]);
        $d['selected'] = FALSE;

        if(Qs::userIsTeacher()){
            $teacher = Auth::user();
            $d['my_classes'] = $d['my_classes']->whereIn('id', TeacherScope::classIds($teacher))->values();
        }

        if($class_id && $exam_id && $section_id){

            $wh = ['my_class_id' => $class_id, 'section_id' => $section_id, 'exam_id' => $exam_id, 'year' => $this->year];

            $sub_ids = $this->mark->getSubjectIDs($wh);
            $st_ids = $this->mark->getStudentIDs($wh);

            if(count($sub_ids) < 1 OR count($st_ids) < 1) {
                return Qs::goWithDanger('marks.tabulation', __('msg.srnf'));
            }

            $d['subjects'] = $this->my_class->getSubjectsByIDs($sub_ids);
            $d['marks'] = $mks = $this->exam->getMark($wh);
            $d['exr'] = $exr = $this->exam->getRecord($wh);
            $d['order'] = $order = $this->tabulationOrder();
            $d['students'] = $this->orderTabulationStudents(
                $this->student->getRecordByUserIDs($st_ids)->get(),
                $exr,
                $order
            );
            $d['sections'] = $this->my_class->getAllSections();

            $d['selected'] = TRUE;
            $d['my_class_id'] = $class_id;
            $d['section_id'] = $section_id;
            $d['exam_id'] = $exam_id;
            $d['year'] = $this->year;

            $d['my_class'] = $mc = $this->my_class->find($class_id);
            $d['section']  = $this->my_class->findSection($section_id);
            $d['ex'] = $exam = $this->exam->find($exam_id);
            $d['tex'] = 'tex'.$exam->term;
            //$d['class_type'] = $this->my_class->findTypeByClass($mc->id);
            //$d['ct'] = $ct = $d['class_type']->code;
        }

        if(Qs::userIsTeacher()){
            // Selector + sheet show only the teacher's own sections.
            $d['sections'] = Section::whereIn('id', $this->teacherSectionIds(Auth::user()))->get();
        }

        return view('pages.support_team.marks.tabulation.index', $d);
    }

    /**
     * Owner grant 2026-10-25: section ids a teacher may view on the
     * tabulation sheet. Null class = every assigned class; a subject
     * teacher gets every section of the classes they teach, a pure
     * class teacher only the sections they lead.
     */
    protected function teacherSectionIds($user, $classId = null): array
    {
        $classIds = $classId ? [(int) $classId] : TeacherScope::classIds($user);
        $ids = [];
        foreach ($classIds as $cid) {
            $teachesSubject = Subject::where('my_class_id', $cid)->where('teacher_id', $user->id)->exists();
            $q = Section::where('my_class_id', $cid);
            if (!$teachesSubject) {
                $q->where('teacher_id', $user->id);
            }
            $ids = array_merge($ids, $q->pluck('id')->map(function ($id) { return (int) $id; })->all());
        }
        return array_values(array_unique($ids));
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
        $d['exr'] = $exr = $this->exam->getRecord($wh);
        $d['order'] = $order = $this->tabulationOrder();
        $d['students'] = $this->orderTabulationStudents(
            $this->student->getRecordByUserIDs($st_ids)->get(),
            $exr,
            $order
        );

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

    /* Tabulation sheet order (owner ask 2026-10-25): the sheet and
       its print document both honour one order, chosen on the
       tabulation page — rank (position, default), roll number
       (admission number) or name. Anything else falls back to rank. */
    protected function tabulationOrder()
    {
        $order = request()->query('order', request()->input('order', 'rank'));
        return in_array($order, ['rank', 'roll', 'name'], true) ? $order : 'rank';
    }

    protected function orderTabulationStudents($students, $exr, $order)
    {
        $posOf = function ($s) use ($exr) {
            $rec = $exr->where('student_id', $s->user_id)->first();
            return $rec ? $rec->pos : null;
        };
        $totalOf = function ($s) use ($exr) {
            $rec = $exr->where('student_id', $s->user_id)->first();
            return $rec ? (float) $rec->total : -1;
        };
        if ($order === 'roll') {
            return $students->sortBy('adm_no')->values();
        }
        if ($order === 'name') {
            return $students->sortBy('user.name')->values();
        }
        // Rank: position ascending; unpositioned students last (by
        // total desc, then name); ties break by total desc, then name.
        return $students->sort(function ($a, $b) use ($posOf, $totalOf) {
            $pa = $posOf($a); $pb = $posOf($b);
            if ($pa === null && $pb === null) {
                return ($totalOf($b) <=> $totalOf($a)) ?: strcmp($a->user->name, $b->user->name);
            }
            if ($pa === null) { return 1; }
            if ($pb === null) { return -1; }
            return ($pa <=> $pb) ?: (($totalOf($b) <=> $totalOf($a)) ?: strcmp($a->user->name, $b->user->name));
        })->values();
    }

    public function tabulation_select(Request $req)
    {
        return redirect()->route('marks.tabulation', [$req->exam_id, $req->my_class_id, $req->section_id, 'order' => $this->tabulationOrder()]);
    }

    /**
     * Batch S: who may open a student's marks surfaces at all — the
     * student themselves, their parent, or teamSA staff. The
     * select-year gateway applies it too, so an unrelated student,
     * another parent, the accountant or the librarian can no longer
     * reach even a student's exam-year metadata. (The Batch S teacher
     * branch was removed by the owner ruling of 2026-10-22:
     * marksheets are student/office documents, not teacher ones.)
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

        /* Owner ruling 2026-10-22: no teacher branch — the select-year
           gateway leads to the marksheet, which teachers no longer
           open (marks entry is their surface, not the report card). */

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


}
