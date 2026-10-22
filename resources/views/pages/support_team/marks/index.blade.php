@extends('layouts.master')
@section('page_title', 'Manage Exam Marks')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Academics &nbsp;/&nbsp; Exams &nbsp;/&nbsp; Manage Exam Marks
@endsection
@section('page_actions')
    <a href="{{ route('exams.index') }}" class="btn btn-outline-primary"><i class="icon-calendar3 mr-2"></i>View Exam Schedule</a>
@endsection
@section('content')
    {{-- Loop 84 (marks selector redesign): the page is the exam /
         class / section / subject picker. New chrome around the
         shared selector form — a slim info banner, a four-step
         progress indicator driven by the form's real selections,
         the "Select Exam Details" card frame and three info cards.
         The form itself (marks/selector partial, full-chrome mode)
         keeps its POST, field names, ids and cascade untouched. --}}

    <div class="l84-banner">
        <span class="l84-banner-icon"><i class="icon-profile"></i></span>
        <div class="l84-banner-text">
            <h5>Record and manage student marks</h5>
            <p>Select an exam, class, section and subject to view the list of students and enter or edit their marks securely.</p>
        </div>
        <ul class="l84-banner-points">
            <li><i class="icon-checkmark3"></i> Supports all exam types</li>
            <li><i class="icon-checkmark3"></i> Class &amp; section wise</li>
            <li><i class="icon-checkmark3"></i> Easy mark entry and updates</li>
        </ul>
    </div>

    <ol class="l84-steps" id="l84-steps" aria-label="Selection progress">
        <li class="l84-step" data-step="1">
            <span class="l84-step-dot"><span class="l84-step-num">1</span><i class="icon-checkmark3 l84-step-check"></i></span>
            <span class="l84-step-text"><strong>Select Exam</strong><small>Choose exam</small></span>
        </li>
        <li class="l84-step" data-step="2">
            <span class="l84-step-dot"><span class="l84-step-num">2</span><i class="icon-checkmark3 l84-step-check"></i></span>
            <span class="l84-step-text"><strong>Select Class</strong><small>Choose class &amp; section</small></span>
        </li>
        <li class="l84-step" data-step="3">
            <span class="l84-step-dot"><span class="l84-step-num">3</span><i class="icon-checkmark3 l84-step-check"></i></span>
            <span class="l84-step-text"><strong>Select Subject</strong><small>Choose subject</small></span>
        </li>
        <li class="l84-step" data-step="4">
            <span class="l84-step-dot"><span class="l84-step-num">4</span><i class="icon-checkmark3 l84-step-check"></i></span>
            <span class="l84-step-text"><strong>Manage Marks</strong><small>View &amp; enter marks</small></span>
        </li>
    </ol>

    <div class="card pat-form-card l84-card">
        <div class="pat-form-head l84-card-head">
            <h5 class="pat-form-title"><i class="icon-list mr-2"></i>Select Exam Details</h5>
            <span class="l84-req-note">Fields marked with <span class="l84-req" aria-hidden="true">*</span> are required</span>
        </div>
        @include('pages.support_team.marks.selector', ['marks_selector_full' => true])
    </div>

    <div class="l84-info-cards">
        <div class="l84-info-card">
            <span class="l84-info-icon l84-tone-blue"><i class="icon-books"></i></span>
            <div>
                <h6>Supported Exam Types</h6>
                @php $l84ExamTypes = $exams->pluck('name')->unique()->values(); @endphp
                <p>@if($l84ExamTypes->count()){{ $l84ExamTypes->implode(', ') }} — every exam created for the current session.@else No exams have been created for the current session yet. @endif</p>
            </div>
        </div>
        <div class="l84-info-card">
            <span class="l84-info-icon l84-tone-violet"><i class="icon-checkmark-circle2"></i></span>
            <div>
                <h6>Automatic Calculations</h6>
                <p>Totals, percentages and grades are calculated automatically.</p>
            </div>
        </div>
        <div class="l84-info-card">
            <span class="l84-info-icon l84-tone-amber"><i class="icon-shield-check"></i></span>
            <div>
                <h6>Secure &amp; Reliable</h6>
                <p>Your data is safe and accessible only to authorized users.</p>
            </div>
        </div>
    </div>
@endsection
@section('scripts')
<script>
    /* Loop 84: presentation-state only. Reads the selector form's
       real values to drive the stepper + submit gate; the cascade
       (getClassSubjects) and the POST contract are untouched. If
       this script fails to load, the form still submits natively
       (the button is rendered enabled and the selects keep their
       HTML `required` + server-side validation). */
    $(function () {
        var $exam = $('#exam_id'), $cls = $('#my_class_id'),
            $sec = $('#section_id'), $sub = $('#subject_id'),
            $btn = $('#l84-manage-btn'), $steps = $('#l84-steps .l84-step'),
            cascadePending = false, lastClass = $cls.val();

        function val($el) { var v = $el.val(); return (v === null || v === '') ? '' : String(v); }

        function evalState() {
            var done1 = val($exam) !== '',
                done2 = val($cls) !== '' && val($sec) !== '' && !cascadePending,
                done3 = val($sub) !== '' && !cascadePending,
                done = [done1, done2, done3, false],
                current = done.indexOf(false); // first incomplete step (0-based)

            $steps.each(function (i) {
                var $li = $(this);
                $li.toggleClass('is-done', !!done[i]);
                $li.toggleClass('is-current', i === current);
                if (i === current) { $li.attr('aria-current', 'step'); } else { $li.removeAttr('aria-current'); }
            });

            $btn.prop('disabled', !(done1 && done2 && done3));
        }

        $('#exam_id, #my_class_id, #section_id, #subject_id').on('change', function () {
            if (this.id === 'my_class_id' && val($cls) !== lastClass) {
                lastClass = val($cls);
                cascadePending = val($cls) !== '';
            }
            evalState();
        });

        /* The cascade fills section/subject without firing change on
           them; re-evaluate once its request settles (success or not). */
        $(document).on('ajaxComplete', function (e, xhr, settings) {
            if (settings && settings.url && settings.url.indexOf('get_class_subjects') !== -1) {
                cascadePending = false;
                evalState();
            }
        });

        /* Reset: restore the initial state — exam back to its first
           option, class cleared, section/subject emptied exactly as
           the cascade leaves them before a class is chosen. The
           inline onchange is detached while the class value is set
           programmatically so no empty cascade request fires. */
        $('#l84-reset-btn').on('click', function () {
            var clsEl = $cls.get(0), oc = clsEl.getAttribute('onchange');
            clsEl.removeAttribute('onchange');
            $cls.val('').trigger('change');
            if (oc) { clsEl.setAttribute('onchange', oc); }
            $sec.empty().trigger('change');
            $sub.empty().trigger('change');
            $exam.prop('selectedIndex', 0).trigger('change');
            lastClass = ''; cascadePending = false;
            evalState();
        });

        /* Loading state + duplicate-submit guard (valid submits only;
           an incomplete form can never pass the disabled gate, and a
           click on a disabled button does not submit at all). */
        $btn.closest('form').on('submit', function () {
            if ($btn.prop('disabled')) { return false; }
            $btn.prop('disabled', true).addClass('l84-loading');
        });

        evalState();
    });
</script>
@endsection
