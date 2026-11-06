@extends('layouts.master')
@section('page_title', 'Manage Exam Marks')
@section('page_subtitle', 'Select an exam, class, section and subject to manage and enter student marks.')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Academics &nbsp;/&nbsp; Exams &nbsp;/&nbsp; Manage Exam Marks
@endsection
@section('page_actions')
    {{-- exams.index is teamSA-only (settled exam-access rule): for a teacher the button dead-ended at a login redirect, so it renders only for teamSA. --}}
    @if(Qs::userIsTeamSA())
    <a href="{{ route('exams.index') }}" class="btn btn-outline-primary"><i class="icon-calendar3 mr-2"></i>View Exam Schedule</a>
    @endif
@endsection
@section('content')
    {{-- Marks selector page. Loop 84 built the gated stepper +
         entry-state flow; item 10 (Light Luxe) rebuilt the chrome:
         slim header, ONE card holding the stepper, the four selects,
         the real-data stat tiles and the status line + actions. The
         form (marks/selector partial, full-chrome mode) keeps its
         POST, field names, ids and cascade untouched. --}}

    {{-- Item 10 (Light Luxe): slim header — gradient icon tile,
         title, ONE subtitle line, session chip. The promo banner
         and its checklist are gone by owner choice. --}}
    <div class="l10-head">
        <span class="l10-head-tile"><i class="icon-profile"></i></span>
        <div class="l10-head-text">
            <h5>Manage Exam Marks</h5>
            <p>Pick the exam, class, section and subject — the student list opens from here.</p>
        </div>
        <span class="l10-chip"><i class="icon-calendar3"></i>Session {{ Qs::getCurrentSession() }}</span>
    </div>

    <div class="card pat-form-card l84-card l10-card">
    <ol class="l84-steps l10-steps" id="l84-steps" aria-label="Selection progress">
        <li class="l84-step" data-step="1">
            <span class="l84-step-dot"><span class="l84-step-num">1</span><i class="icon-checkmark3 l84-step-check"></i></span>
            <span class="l84-step-text"><strong>Exam</strong></span>
        </li>
        <li class="l84-step" data-step="2">
            <span class="l84-step-dot"><span class="l84-step-num">2</span><i class="icon-checkmark3 l84-step-check"></i></span>
            <span class="l84-step-text"><strong>Class</strong></span>
        </li>
        <li class="l84-step" data-step="3">
            <span class="l84-step-dot"><span class="l84-step-num">3</span><i class="icon-checkmark3 l84-step-check"></i></span>
            <span class="l84-step-text"><strong>Subject</strong></span>
        </li>
        <li class="l84-step" data-step="4">
            <span class="l84-step-dot"><span class="l84-step-num">4</span><i class="icon-checkmark3 l84-step-check"></i></span>
            <span class="l84-step-text"><strong>Manage Marks</strong></span>
        </li>
    </ol>
        @include('pages.support_team.marks.selector', ['marks_selector_full' => true])
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

        /* Owner order 2026-11-01: once all four are chosen, ask the
           server whether this unit is actually enabled (exam date
           reached + not yet submitted, via MarkLifecycle). If not,
           the button reads "Not Enabled" and the note under it says
           why — nobody clicks through into a locked grid. The
           server-side gates remain the real enforcement; a failed
           check fails OPEN to them rather than stranding the user. */
        var $note = $('#l84-entry-note'), $label = $('#l84-manage-btn-label'),
            $pulse = $('#l10-pulse'),
            entryStateUrl = '{{ route('marks.entry_state') }}',
            entrySeq = 0, entryOk = false, entryPending = false, lastEntryKey = '';

        /* Item 10: ONE flow drives the stepper, the stat tiles, the
           status line and the button together, all from the same
           entry-state response. The note text lives in the card's
           status line now; it is always visible (the prompt text
           below is its resting state). */
        function setStatus(mode, text) {
            $note.text(text);
            $pulse.attr('class', 'l10-pulse' + (mode ? ' l10-pulse-' + mode : ''));
        }

        function resetTiles() {
            $('#l10-t-students').text('\u2014'); $('#l10-t-students-l').text('Students');
            $('#l10-t-subject').text('\u2014');
            $('#l10-t-max').text('\u2014'); $('#l10-t-max-l').text('Max marks');
            $('#l10-t-entry').text('\u2014'); $('#l10-t-entry-l').text('Entry state');
        }

        function fillTiles(data) {
            if (!data || !data.summary) { return; }
            var sm = data.summary;
            $('#l10-t-students').text(sm.students);
            $('#l10-t-students-l').text('Students \u00b7 ' + $cls.find('option:selected').text() + ' (' + $sec.find('option:selected').text() + ')');
            $('#l10-t-subject').text(sm.subject || '\u2014');
            $('#l10-t-max').text(sm.max);
            $('#l10-t-max-l').text(sm.scheme ? ('Max \u00b7 ' + sm.scheme) : 'Max marks');
            $('#l10-t-entry').text(sm.entry_label || '\u2014');
            $('#l10-t-entry-l').text(sm.entry_sub || 'Entry state');
        }

        function checkEntry() {
            var key = [val($exam), val($cls), val($sec), val($sub)].join(':');
            if (key === lastEntryKey) { return; }
            lastEntryKey = key;
            entryOk = false; entryPending = true;
            var seq = ++entrySeq;
            $.ajax({ url: entryStateUrl, dataType: 'json', data: {
                exam_id: val($exam), class_id: val($cls),
                section_id: val($sec), subject_id: val($sub)
            }}).done(function (data) {
                if (seq !== entrySeq) { return; }
                entryPending = false;
                fillTiles(data);
                if (data && data.enabled) {
                    entryOk = true;
                    $label.text('Manage Marks');
                    setStatus('ok', 'Ready \u2014 marks entry is open for this unit.');
                } else {
                    entryOk = false;
                    $label.text('Not Enabled');
                    setStatus('bad', (data && data.reason) ? data.reason : 'This unit is not enabled.');
                }
                evalState();
            }).fail(function () {
                if (seq !== entrySeq) { return; }
                entryPending = false; entryOk = true;
                $label.text('Manage Marks');
                setStatus('', 'Entry state could not be checked \u2014 the server confirms it when you open the marks.');
                evalState();
            });
        }

        function evalState() {
            var done1 = val($exam) !== '',
                done2 = val($cls) !== '' && val($sec) !== '' && !cascadePending,
                done3 = val($sub) !== '' && !cascadePending,
                complete = done1 && done2 && done3,
                done = [done1, done2, done3, complete && entryOk],
                current = done.indexOf(false); // first incomplete step (0-based)

            $steps.each(function (i) {
                var $li = $(this);
                $li.toggleClass('is-done', !!done[i]);
                $li.toggleClass('is-current', i === current);
                if (i === current) { $li.attr('aria-current', 'step'); } else { $li.removeAttr('aria-current'); }
            });

            if (complete) { checkEntry(); }
            else {
                entrySeq++; entryOk = false; entryPending = false; lastEntryKey = '';
                $label.text('Manage Marks');
                setStatus('', 'Select exam, class, section and subject \u2014 the entry state will show here.');
                resetTiles();
            }
            $btn.prop('disabled', !complete || entryPending || !entryOk);
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
            if (settings && settings.url && (settings.url.indexOf('get_class_subjects') !== -1 || settings.url.indexOf('selector-options') !== -1)) {
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
