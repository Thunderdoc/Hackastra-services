<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Support\Audit;
use App\Support\InternalMarksImporter;
use App\Support\MarkComputation;
use App\Support\MarkLifecycle;
use App\Support\MarkScheme;
use App\Models\Assignment;
use App\Models\MarkSubmission;
use App\Models\Quiz;
use App\Support\ReportCard;
use App\Support\SectionTeacher;
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
use Illuminate\Support\Facades\DB;

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
        /* Staff are never the payer: the block pressures students and
           parents, so teamSA and scoped teachers (already gated by
           the caller) download without it. */
        if (Qs::userIsTeamSA() || Qs::userIsTeacher()) {
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
        /* Shared gate (see canAccessStudentMarks): owner decision
           2026-10-25 reverses the 2026-10-22 ban — scoped teachers
           open the marksheet like the print. */
        if(!$this->canAccessStudentMarks($student_id)){
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
        /* Shared gate (see canAccessStudentMarks). */
        if(!$this->canAccessStudentMarks($student_id)){
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

        $d = $this->marksheetPrintData($student_id, $exam_id, $year);
        if (!$d) {
            return $this->noStudentRecord();
        }

        return view('pages.support_team.marks.print.index', $d);
    }

    /**
     * Wave 5 — shared assembly for ONE student's printed marksheet
     * (the same data print_view feeds marks.print.index). bulkDownload
     * reuses it per student so a single marksheet and a section bundle
     * can never disagree on a figure. Returns null when the student
     * has no record for this exam (print_view used to fatal on that).
     */
    protected function marksheetPrintData($student_id, $exam_id, $year)
    {
        $wh = ['student_id' => $student_id, 'exam_id' => $exam_id, 'year' => $year ];
        $d['marks'] = $mks = $this->exam->getMark($wh);
        $d['exr'] = $exr = $this->exam->getRecord($wh)->first();
        if (!$exr) {
            return null;
        }
        $d['my_class'] = $mc = $this->my_class->find($exr->my_class_id);
        $d['section_id'] = $exr->section_id;
        $d['ex'] = $exam = $this->exam->find($exam_id);
        if (!$exam) {
            return null;
        }
        $d['tex'] = 'tex'.$exam->term;
        $d['sr'] = $sr = $this->student->getRecord(['user_id' => $student_id])->first();
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

        return $d;
    }

    /**
     * Wave 5 — owner order 2026-10-25: the Admin downloads a whole
     * SECTION's marksheets as one PDF (one card per student, in
     * roll/name order). teamSA-only route; students without a record
     * for this exam are skipped, never rendered blank. Images are
     * inlined as data URIs exactly like pdf_annual (dompdf fetches
     * no remote URLs). Admin office use: no publish gate, no fee
     * block — teamSA is exempt from both already.
     */
    public function bulkDownload(Request $req)
    {
        /* A 40-student bundle is a big dompdf layout tree: the stock
           128M limit dies mid-render (proven by test). Raise it for
           this request only — a bounded, one-shot generation. The
           17s a 40-card bundle takes also sits too close to the
           stock 30s execution cap for bigger sections. */
        ini_set('memory_limit', '512M');
        set_time_limit(300);

        $exam = $this->exam->find($req->exam_id);
        $mc = $this->my_class->find($req->my_class_id);
        $section = $req->section_id ? Section::find($req->section_id) : null;
        if (!$exam || !$mc || !$section || (int) $section->my_class_id !== (int) $mc->id) {
            abort(404);
        }

        $students = $this->student->getRecord(['my_class_id' => $mc->id, 'section_id' => $section->id])->get()->sortBy('user.name');
        $cards = [];
        foreach ($students as $st) {
            $d = $this->marksheetPrintData($st->user_id, $exam->id, $exam->year);
            if (!$d) {
                continue;
            }
            foreach (['stamp', 'principal_signature'] as $imgKey) {
                if (!empty($d['card'][$imgKey])) {
                    $d['card'][$imgKey] = $this->pdfImageDataUri($d['card'][$imgKey]);
                }
            }
            if (!empty($d['s']['logo'])) {
                $d['s']['logo'] = $this->pdfImageDataUri($d['s']['logo']);
            }
            $cards[] = $d;
        }

        if (empty($cards)) {
            return back()->with('flash_info', 'No marksheets found for this section and exam.');
        }

        $pdf_name = 'Marksheets_'.\Illuminate\Support\Str::slug($mc->name).'_'. \Illuminate\Support\Str::slug($section->name).'_'. \Illuminate\Support\Str::slug($exam->name).'.pdf';

        return PDF::loadView('pages.support_team.marks.print.bulk_pdf', ['cards' => $cards])->setPaper('a4')->download($pdf_name);
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
        /* Shared gate (see canAccessStudentMarks): self, teamSA,
           my-child, and — owner decision 2026-10-25 — teachers for
           classes they teach. */
        if(!$this->canAccessStudentMarks($student_id)){
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
        /* Shared gate (see canAccessStudentMarks). */
        if(!$this->canAccessStudentMarks($student_id)){
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
        /* Shared gate (see canAccessStudentMarks). */
        if(!$this->canAccessStudentMarks($student_id)){
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

    /**
     * Agent C round 24 — marks entry is EFFECTIVE-TEACHER scoped, on
     * top of the Batch S class scope: a teacher may enter marks only
     * for a subject that belongs to the class AND whose effective
     * teacher for this section (override wins, else the subject's
     * direct teacher) is herself. Class scope alone used to let a
     * teacher enter marks for ANY subject of an in-scope class —
     * including a colleague's subject and a class she only leads.
     * Admins are unaffected (non-teachers always pass).
     */
    protected function denyUnlessEffectiveTeacher($classId, $sectionId, $subjectId)
    {
        if (!Qs::userIsTeacher()) {
            return;
        }
        $subject = Subject::find($subjectId);
        if (!$subject || (int) $subject->my_class_id !== (int) $classId
            || (int) SectionTeacher::teacherIdFor($sectionId, $subject) !== (int) Auth::user()->id) {
            abort(403, 'You can only manage marks for subjects you teach in this section.');
        }
    }

    public function selector(MarkSelector $req)
    {
        // Batch S: marks entry is scoped to a teacher's assigned classes.
        if(Qs::userIsTeacher() && !TeacherScope::canTeachClass(Auth::user(), $req->my_class_id)){
            abort(403, 'You can only manage marks for your assigned classes.');
        }
        $this->denyUnlessEffectiveTeacher($req->my_class_id, $req->section_id, $req->subject_id);

        /* Wave 8 — class-scoped exams (wave 2) belong to exactly
           one class, but the picker lists every session exam. A
           mis-pick used to firstOrCreate mark rows for the wrong
           exam/class pair (silent wrong data on report cards).
           The picker now filters by class in JS; this guard is the
           real enforcement — it also covers direct POSTs. */
        $exam = $this->exam->find($req->exam_id);
        if (!$exam || ((int) $exam->my_class_id !== 0 && (int) $exam->my_class_id !== (int) $req->my_class_id)) {
            return back()->with('pop_error', 'The selected exam does not belong to the selected class.');
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
        $this->denyUnlessEffectiveTeacher($class_id, $section_id, $subject_id);

        // Wave 8: same exam-scope guard as selector() — a direct
        // URL with another class's scoped exam must not render an
        // entry grid for a nonsense combination.
        $exam = $this->exam->find($exam_id);
        if (!$exam || ((int) $exam->my_class_id !== 0 && (int) $exam->my_class_id !== (int) $class_id)) {
            abort(404);
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

        // Marks lifecycle (owner order 2026-10-29): the grid renders
        // read-only before the subject's exam date and after the
        // teacher submits; the blade reads the full state from here.
        $d['lifecycle'] = MarkLifecycle::state($exam_id, $class_id, $section_id, $subject_id);

        // Internal-marks importer (owner order 2026-10-29): when the
        // scheme has an Internal component, the page offers to fill
        // it from the effective teacher's assignments or quizzes.
        $d['internal_component'] = $d['scheme']['configured']
            ? InternalMarksImporter::internalComponent($exam_id, $subject_id)
            : null;
        $d['import_quizzes'] = collect();
        $d['import_assignment_count'] = 0;
        if ($d['internal_component']) {
            $subject = Subject::find($subject_id);
            $effectiveTeacherId = $subject ? SectionTeacher::teacherIdFor($section_id, $subject) : null;
            if ($effectiveTeacherId) {
                $d['import_quizzes'] = Quiz::where('teacher_id', $effectiveTeacherId)
                    ->where('my_class_id', $class_id)->where('subject_id', $subject_id)
                    ->orderBy('title')->get();
                $d['import_assignment_count'] = Assignment::where('teacher_id', $effectiveTeacherId)
                    ->where('my_class_id', $class_id)->where('subject_id', $subject_id)->count();
            }
        }

        return view('pages.support_team.marks.manage', $d);
    }

    public function update(MarkComponentUpdate $req, $exam_id, $class_id, $section_id, $subject_id)
    {
        // Batch S: marks entry is scoped to a teacher's assigned classes.
        if(Qs::userIsTeacher() && !TeacherScope::canTeachClass(Auth::user(), $class_id)){
            abort(403, 'You can only manage marks for your assigned classes.');
        }
        $this->denyUnlessEffectiveTeacher($class_id, $section_id, $subject_id);

        $p = ['exam_id' => $exam_id, 'my_class_id' => $class_id, 'section_id' => $section_id, 'subject_id' => $subject_id, 'year' => $this->year];

        $exam = $this->exam->find($exam_id);

        // Wave 8: same exam-scope guard as selector()/manage() —
        // never write marks under another class's scoped exam.
        if (!$exam || ((int) $exam->my_class_id !== 0 && (int) $exam->my_class_id !== (int) $class_id)) {
            abort(404);
        }

        // Marks lifecycle (owner order 2026-10-29): no saves before
        // the subject's exam date, and no saves at all once the
        // teacher has submitted this unit.
        MarkLifecycle::guard($exam_id, $class_id, $section_id, $subject_id);

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
           never silently wiped (the legacy >100 wipe is deleted).
           Wave 32: the save and both recomputes run in ONE
           transaction, so a recompute failure can no longer leave
           component scores saved with stale totals behind it. */
        $changes = [];
        $fmtVal = function($v){ return $v === NULL ? 'blank' : (string) $v; };
        DB::transaction(function () use ($marks, $req, $exam_id, $subject_id, $exam, $class_id, $section_id, $oldScores, $fmtVal, &$changes) {
        if(MarkScheme::isConfigured($exam_id, $subject_id)){
            $components = MarkScheme::configuredComponents($exam_id, $subject_id);

            foreach($marks as $mk){
                $scores = [];
                foreach($components as $comp){
                    $v = $req->input('comp_'.$mk->id.'_'.$comp->id);
                    $scores[$comp->id] = ($v === NULL || $v === '') ? NULL : (int) $v;
                    $old = $oldScores[$mk->id][$comp->id] ?? NULL;
                    if($old !== $scores[$comp->id]){
                        $changes[] = (optional($mk->user)->name ?? 'Student').' — '.$comp->name.': '.$fmtVal($old).' -> '.$fmtVal($scores[$comp->id]);
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
                        $changes[] = (optional($mk->user)->name ?? 'Student').' — '.$legacyNames[$field].': '.$fmtVal($old).' -> '.$fmtVal($d[$field]);
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
        });

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

    /**
     * Fill the Internal component from assignments or quizzes
     * (owner order 2026-10-29). The teacher picks the source and
     * the aggregation (average of all / best N / latest N — the
     * InternalMarksImporter owns the maths); the write goes through
     * the same component path, recompute and lifecycle gates as a
     * typed save, so a submitted unit or a not-yet-open exam date
     * refuses an import exactly like a save.
     */
    public function importInternal(Request $req, $exam_id, $class_id, $section_id, $subject_id)
    {
        if(Qs::userIsTeacher() && !TeacherScope::canTeachClass(Auth::user(), $class_id)){
            abort(403, 'You can only manage marks for your assigned classes.');
        }
        $this->denyUnlessEffectiveTeacher($class_id, $section_id, $subject_id);

        $exam = $this->exam->find($exam_id);
        if (!$exam || ((int) $exam->my_class_id !== 0 && (int) $exam->my_class_id !== (int) $class_id)) {
            abort(404);
        }

        MarkLifecycle::guard($exam_id, $class_id, $section_id, $subject_id);

        $component = InternalMarksImporter::internalComponent($exam_id, $subject_id);
        if (!$component) {
            return back()->with('pop_error', 'This subject has no Internal component in its marking scheme — there is nothing to fill.');
        }

        $subject = Subject::find($subject_id);
        $teacherId = $subject ? SectionTeacher::teacherIdFor($section_id, $subject) : null;
        if (!$teacherId) {
            return back()->with('pop_error', 'No teacher is assigned to this subject for this section.');
        }

        $kind = $req->input('source_kind') === 'quizzes' ? 'quizzes' : 'assignments';
        if ($kind === 'assignments') {
            $percents = InternalMarksImporter::assignmentPercents($teacherId, $class_id, $subject_id);
            $sourceLabel = 'assignments';
        } else {
            $quizIds = collect((array) $req->input('quiz_ids', []))->map(function ($id) { return (int) $id; })->all();
            $validIds = Quiz::where('teacher_id', $teacherId)
                ->where('my_class_id', $class_id)->where('subject_id', $subject_id)
                ->whereIn('id', $quizIds)->pluck('id')->all();
            if (!$validIds) {
                return back()->with('pop_error', 'Choose at least one of your quizzes for this class and subject.');
            }
            $percents = InternalMarksImporter::quizPercents($validIds);
            $sourceLabel = 'quizzes';
        }

        $choice = InternalMarksImporter::modeFromRequest($req);
        $marks = $this->exam->getMark(['exam_id' => $exam_id, 'my_class_id' => $class_id, 'section_id' => $section_id, 'subject_id' => $subject_id, 'year' => $this->year]);

        $written = 0;
        DB::transaction(function () use ($marks, $percents, $choice, $component, $exam, $class_id, $section_id, $subject_id, &$written) {
            foreach ($marks as $mk) {
                $entries = $percents[$mk->student_id] ?? null;
                if (!$entries) {
                    continue; // no graded work — the cell stays blank
                }
                $pct = InternalMarksImporter::aggregate($entries, $choice['mode'], $choice['n']);
                if ($pct === null) {
                    continue;
                }
                MarkScheme::saveComponentScores($mk, [$component['id'] => InternalMarksImporter::scaled($pct, $component)]);
                $written++;
            }
            if ($written > 0) {
                MarkComputation::recomputeSubject($exam, $class_id, $section_id, $subject_id, $this->year);
                MarkComputation::recomputeRecords($exam, $class_id, $section_id, $this->year);
            }
        });

        if ($written === 0) {
            return back()->with('pop_error', 'No graded submissions or attempts were found in the chosen ' . $sourceLabel . ' — nothing was filled.');
        }
        $modeLabel = $choice['mode'] === 'all' ? 'average of all' : $choice['mode'] . ' ' . $choice['n'];
        Audit::log('marks.internal_imported', null, 'Internal marks imported from ' . $sourceLabel . ' (' . $modeLabel . '): exam ' . $exam_id . ', class ' . $class_id . ', section ' . $section_id . ', subject ' . $subject_id . ', students written: ' . $written . ' by ' . Auth::user()->name);

        return back()->with('flash_success', 'Internal marks filled for ' . $written . ' student' . ($written === 1 ? '' : 's') . ' from ' . $sourceLabel . ' (' . $modeLabel . '). Check the grid, save any other components, then submit when ready.');
    }

    /**
     * Submit one (exam, class, section, subject) unit's marks
     * (owner order 2026-10-29). Saving stays free-form draft work
     * while entry is open; SUBMITTING is the point of no return for
     * the teacher — the unit locks (see MarkLifecycle) until the
     * school Admin reopens it. The effective-teacher scope is the
     * same as the save endpoint's.
     */
    public function submit(Request $req, $exam_id, $class_id, $section_id, $subject_id)
    {
        if(Qs::userIsTeacher() && !TeacherScope::canTeachClass(Auth::user(), $class_id)){
            abort(403, 'You can only manage marks for your assigned classes.');
        }
        $this->denyUnlessEffectiveTeacher($class_id, $section_id, $subject_id);

        $exam = $this->exam->find($exam_id);
        if (!$exam || ((int) $exam->my_class_id !== 0 && (int) $exam->my_class_id !== (int) $class_id)) {
            abort(404);
        }

        $state = MarkLifecycle::state($exam_id, $class_id, $section_id, $subject_id);
        if ($state['submitted']) {
            return back()->with('pop_error', 'These marks have already been submitted.');
        }
        if (!$state['open']) {
            abort(403, 'Marks entry for this subject opens on ' . $state['opens_on'] . ' — the day after the exam (' . $state['exam_date'] . ').');
        }

        MarkSubmission::create([
            'exam_id' => $exam_id,
            'my_class_id' => $class_id,
            'section_id' => $section_id,
            'subject_id' => $subject_id,
            'submitted_by' => Auth::user()->id,
        ]);
        Audit::log('marks.submitted', null, 'Marks submitted: exam '.$exam_id.', class '.$class_id.', section '.$section_id.', subject '.$subject_id.' by '.Auth::user()->name);

        return back()->with('flash_success', 'Marks submitted — this subject is now locked for editing.');
    }

    /**
     * Reopen a submitted unit (school Admin only). The correction
     * escape hatch for the submit lock: deleting the submission row
     * returns the unit to draft, and the reopening is audited with
     * the original submitter named.
     */
    public function reopen(Request $req, $exam_id, $class_id, $section_id, $subject_id)
    {
        if (!Qs::userIsAdmin()) {
            abort(403, 'Only the school Admin can reopen submitted marks.');
        }

        $exam = $this->exam->find($exam_id);
        if (!$exam || ((int) $exam->my_class_id !== 0 && (int) $exam->my_class_id !== (int) $class_id)) {
            abort(404);
        }

        $submission = MarkSubmission::forUnit($exam_id, $section_id, $subject_id);
        if ($submission) {
            $submitter = optional($submission->submitter)->name ?? ('user '.$submission->submitted_by);
            $submission->delete();
            Audit::log('marks.reopened', null, 'Marks reopened: exam '.$exam_id.', class '.$class_id.', section '.$section_id.', subject '.$subject_id.' (submitted by '.$submitter.'), reopened by '.Auth::user()->name);
        }

        return back()->with('flash_success', 'Marks reopened — the teacher can edit and submit them again.');
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
        /* Wave 5: the section bulk-download form needs an exam picker. */
        $d['exams'] = $this->exam->getExam(['year' => Qs::getCurrentSession()]);

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
            // Round-8 allotment fix: a teacher counts as a subject
            // teacher for the class when they hold a direct subject
            // OR any section override in that class. The old raw-only
            // check gave an override-only teacher ZERO sections, so
            // tabulation() 403'd her out of the sheet for her own
            // override section — even though marks entry (manage(),
            // class-level gate) lets her enter those very marks.
            $teachesSubject = Subject::where('my_class_id', $cid)->where('teacher_id', $user->id)->exists()
                || DB::table('section_subject_teachers')
                    ->join('sections', 'sections.id', '=', 'section_subject_teachers.section_id')
                    ->where('section_subject_teachers.teacher_id', $user->id)
                    ->where('sections.my_class_id', $cid)
                    ->exists();
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

        /* Owner decision 2026-10-25 (reverses the 2026-10-22 ban):
           teachers CAN download marksheets — scoped to the classes
           they teach (TeacherScope), never the whole school. A
           teacher opening a foreign class's student still gets 403. */
        if (Qs::userIsTeacher()) {
            $sr = Qs::findStudentRecord($student_id);

            return $sr && TeacherScope::canTeachClass($user, $sr->my_class_id);
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


}
