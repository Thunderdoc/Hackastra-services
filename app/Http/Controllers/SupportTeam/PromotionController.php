<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Models\Mark;
use App\Models\Section;
use App\Repositories\MyClassRepo;
use App\Repositories\StudentRepo;
use App\Support\Streams;
use Illuminate\Http\Request;

class PromotionController extends Controller
{
    protected $my_class, $student;

    public function __construct(MyClassRepo $my_class, StudentRepo $student)
    {
        $this->middleware('teamSA');
        // Workstream CM: promote/reset are school writes — Admin only;
        // SA keeps the promotion views (selector() only redirects).
        $this->middleware('teamSAWrite', ['only' => ['promote', 'reset', 'reset_all']]);

        $this->my_class = $my_class;
        $this->student = $student;
    }

    public function promotion($fc = NULL, $fs = NULL, $tc = NULL, $ts = NULL)
    {
        $d['old_year'] = $old_yr = Qs::getSetting('current_session');
        $old_yr = explode('-', $old_yr);
        $d['new_year'] = ++$old_yr[0].'-'.++$old_yr[1];
        $d['my_classes'] = $this->my_class->all();
        $d['sections'] = $this->my_class->getAllSections();
        $d['selected'] = false;

        if($fc && $fs && ($tc === 'G' || ($tc && $ts))){
            $d['selected'] = true;
            $d['fc'] = $fc;
            $d['fs'] = $fs;
            // Graduating mode (batch 2026-10-25): Class 12 has no
            // next class, so no To Class/Section is needed — the
            // graduates keep their own section in every URL/form.
            $d['graduating'] = ($tc === 'G');
            $d['tc'] = $tc;
            $d['ts'] = $d['graduating'] ? $fs : $ts;
            // Batch 2026-10-25 (streams): when the target class has
            // streamed sections, each student picks a stream and the
            // stream — not the URL's section — decides their section.
            $d['streamed'] = false;
            $d['target_streams'] = [];
            if (!$d['graduating'] && $tc) {
                $targetSections = Section::where('my_class_id', $tc)->orderBy('id')->get();
                $d['target_streams'] = Streams::streamsOfClass($targetSections);
                $d['streamed'] = !empty($d['target_streams']);
            }
            $d['students'] = $sts = $this->student->getRecord(['my_class_id' => $fc, 'section_id' => $fs, 'session' => $d['old_year']])->get();

            if($sts->count() < 1){
                return redirect()->route('students.promotion')->with('flash_success', __('msg.nstp'));
            }
        }

        return view('pages.support_team.students.promotion.index', $d);
    }

    public function selector(Request $req)
    {
        return redirect()->route('students.promotion', [$req->fc, $req->fs, $req->tc, $req->ts]);
    }

    public function promote(Request $req, $fc, $fs, $tc, $ts)
    {
        $oy = Qs::getSetting('current_session'); $d = [];
        $old_yr = explode('-', $oy);
        $ny = ++$old_yr[0].'-'.++$old_yr[1];
        $students = $this->student->getRecord(['my_class_id' => $fc, 'section_id' => $fs, 'session' => $oy ])->get()->sortBy('user.name');

        if($students->count() < 1){
            return redirect()->route('students.promotion')->with('flash_danger', __('msg.srnf'));
        }

        // Batch 2026-10-25 (streams): when the target class has
        // streamed sections, a Promote choice needs a per-student
        // stream — the stream decides the section, not the URL's.
        $streamed = false;
        if ($tc !== 'G') {
            $streamed = !empty(Streams::streamsOfClass(Section::where('my_class_id', $tc)->orderBy('id')->get()));
        }
        $skipped = [];

        foreach($students as $st){
            $d = [];
            $p = 'p-'.$st->id;
            $p = $req->$p;
            // Graduating mode has no destination class: a Promote
            // choice cannot come from the UI here — skip it rather
            // than writing a nonsense class id.
            if($tc === 'G' && $p === 'P'){ continue; }
            $destSection = $ts;
            if($p === 'P' && $streamed){
                $stream = $req->{'s-'.$st->id};
                $section = $stream ? Section::where('my_class_id', $tc)->where('stream', $stream)->orderBy('id')->first() : null;
                if(!$section){
                    $skipped[] = optional($st->user)->name ?: ('#' . $st->id);
                    continue;
                }
                $destSection = $section->id;
            }
            if($p === 'P'){ // Promote
                $d['my_class_id'] = $tc;
                $d['section_id'] = $destSection;
                $d['session'] = $ny;
            }
            if($p === 'D'){ // Don't Promote
                $d['my_class_id'] = $fc;
                $d['section_id'] = $fs;
                $d['session'] = $ny;
            }
            if($p === 'G'){ // Graduated
                $d['my_class_id'] = $fc;
                $d['section_id'] = $fs;
                $d['grad'] = 1;
                $d['grad_date'] = $oy;
            }

            $this->student->updateRecord($st->id, $d);

//            Insert New Promotion Data
            $promote['from_class'] = $fc;
            $promote['from_section'] = $fs;
            $promote['grad'] = ($p === 'G') ? 1 : 0;
            $promote['to_class'] = in_array($p, ['D', 'G']) ? $fc : $tc;
            $promote['to_section'] = in_array($p, ['D', 'G']) ? $fs : $destSection;
            $promote['student_id'] = $st->user_id;
            $promote['from_session'] = $oy;
            $promote['to_session'] = $ny;
            $promote['status'] = $p;

            $this->student->createPromotion($promote);
        }
        $msg = __('msg.update_ok');
        if(!empty($skipped)){
            $msg .= ' Not promoted (no stream chosen): ' . implode(', ', $skipped) . '.';
        }
        return redirect()->route('students.promotion')->with('flash_success', $msg);
    }

    public function manage()
    {
        $data['promotions'] = $this->student->getAllPromotions();
        $data['old_year'] = Qs::getCurrentSession();
        $data['new_year'] = Qs::getNextSession();

        return view('pages.support_team.students.promotion.reset', $data);
    }

    public function reset($promotion_id)
    {
        $this->reset_single($promotion_id);

        return redirect()->route('students.promotion_manage')->with('flash_success', __('msg.update_ok'));
    }

    public function reset_all()
    {
        $next_session = Qs::getNextSession();
        $where = ['from_session' => Qs::getCurrentSession(), 'to_session' => $next_session];
        $proms = $this->student->getPromotions($where);

        if ($proms->count()){
          foreach ($proms as $prom){
              $this->reset_single($prom->id);

              // Delete Marks if Already Inserted for New Session
              $this->delete_old_marks($prom->student_id, $next_session);
          }
        }

        return Qs::jsonUpdateOk();
    }

    protected function delete_old_marks($student_id, $year)
    {
        Mark::where(['student_id' => $student_id, 'year' => $year])->delete();
    }

    protected function reset_single($promotion_id)
    {
        $prom = $this->student->findPromotion($promotion_id);

        $data['my_class_id'] = $prom->from_class;
        $data['section_id'] = $prom->from_section;
        $data['session'] = $prom->from_session;
        $data['grad'] = 0;
        $data['grad_date'] = null;

        $this->student->update(['user_id' => $prom->student_id], $data);

        return $this->student->deletePromotion($promotion_id);
    }
}
