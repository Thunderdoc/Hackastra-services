@extends('layouts.master')
@section('page_title', 'Print Admit Cards')
@section('content')

    <style>
        .admit-toolbar { margin-bottom: 16px; }
        .admit-card { position: relative; background: #fff; border: 2px solid #10263b; border-radius: 10px; padding: 18px 22px; margin-bottom: 22px; color: #172536; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .admit-head { width: 100%; border-collapse: collapse; }
        .admit-head-main h3 { margin: 0 0 2px; font-size: 1.35rem; font-weight: 700; letter-spacing: .01em; text-transform: uppercase; }
        .admit-head-main p { margin: 0; font-size: .78rem; color: #44546a; }
        .admit-logo { max-height: 46px; margin-bottom: 6px; }
        .admit-photo-cell { width: 104px; text-align: right; vertical-align: top; }
        .admit-photo { width: 92px; height: 112px; object-fit: cover; border: 1px solid #dce3ec; border-radius: 6px; }
        .admit-photo-tile { display: inline-flex; align-items: center; justify-content: center; background: #eef4fe; color: #2867dc; font-size: 1.6rem; font-weight: 700; }
        .admit-title { text-align: center; border-top: 2px solid #10263b; border-bottom: 1px solid #dce3ec; margin-top: 10px; padding: 8px 0 9px; }
        .admit-title span { display: block; font-size: .72rem; font-weight: 700; letter-spacing: .28em; color: #3478f6; }
        .admit-title strong { display: block; font-size: 1.12rem; margin-top: 2px; }
        .admit-title small { color: #718096; font-size: .76rem; }
        .admit-fields { width: 100%; border-collapse: collapse; margin-top: 12px; }
        .admit-fields th { text-align: left; font-size: .74rem; color: #718096; text-transform: uppercase; letter-spacing: .04em; padding: 5px 8px 5px 0; width: 110px; }
        .admit-fields td { font-weight: 600; font-size: .9rem; padding: 5px 14px 5px 0; }
        .admit-subjects { width: 100%; border-collapse: collapse; margin-top: 12px; font-size: .84rem; }
        .admit-subjects th { background: #f5f7fb; border: 1px solid #dce3ec; padding: 6px 8px; text-align: left; font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; color: #44546a; }
        .admit-subjects td { border: 1px solid #dce3ec; padding: 6px 8px; height: 26px; }
        .admit-note { font-size: .76rem; color: #b7791f; margin: 8px 0 0; }
        .admit-instructions { margin-top: 12px; font-size: .74rem; color: #44546a; }
        .admit-instructions ol { margin: 4px 0 0; padding-left: 16px; }
        .admit-instructions li { margin: 1px 0; }
        .admit-sign { width: 100%; border-collapse: collapse; margin-top: 20px; font-size: .76rem; color: #44546a; }
        .admit-sign td { padding-right: 22px; vertical-align: bottom; }
        .sign-line { display: block; border-bottom: 1px solid #44546a; height: 26px; margin-bottom: 4px; }
        .admit-seal-cell { width: 118px; padding-right: 0 !important; text-align: right; }
        .admit-seal { display: inline-flex; align-items: center; justify-content: center; width: 104px; height: 64px; border: 1.5px dashed #9aa7b5; border-radius: 6px; color: #9aa7b5; font-size: .64rem; font-weight: 700; letter-spacing: .12em; }
        .admit-sign-name { display: block; height: 26px; line-height: 38px; font-size: .78rem; color: #10263b; white-space: nowrap; }
        .admit-sign-img { display: block; height: 26px; max-width: 140px; object-fit: contain; }
        .admit-sign-sub { display: block; font-size: .7rem; color: #44546a; margin-top: 1px; }
        .sign-line-filled { height: 2px !important; }
        .admit-seal-img { width: 104px; height: 64px; object-fit: contain; }
        @media print {
            @page { size: A4; margin: 10mm; }
            /* Document design v2 (Workstream CY): cards size to their
               content and are NEVER clipped — the old fixed 128mm
               height + overflow:hidden sliced the instructions and
               signature row off taller cards. break-inside keeps each
               card whole; two cards share a page when they fit. */
            .admit-card { height: auto; overflow: visible; margin-bottom: 3mm; padding: 11px 20px; border-radius: 0; break-inside: avoid; page-break-inside: avoid; }
            .admit-card:last-child { margin-bottom: 0; }
            /* Documents Premium pass: the layout's .content bottom
               padding trailed the last card onto a blank final page,
               and pairs of cards just missed sharing page 1 — zero
               the trailing padding and tighten the sign row so the
               sheet packs two cards per page with no empty page. */
            .admit-sign { margin-top: 14px; }
            .content { padding-bottom: 0 !important; }
        }
    </style>

    <div class="admit-toolbar no-print">
        <button type="button" class="btn btn-primary" onclick="window.print()">
            <i class="icon-printer mr-2"></i> Print / Save as PDF
        </button>
        <span class="text-muted ml-2">{{ $students->count() }} card(s) — {{ $exam->name }}, {{ $my_class->name }}</span>
        @if($schedule_fallback)
            <div class="alert alert-warning mt-3 mb-0">
                No exam timetable has been built for {{ $my_class->name }} in this exam yet, so the cards list the
                class subjects without dates. Build the exam timetable (Timetables &rarr; Records) to print dated cards.
            </div>
        @endif
    </div>

    @if($students->isEmpty())
        <div class="card">
            <div class="card-body">
                <div class="lav-empty">
                    <p>No students found for this selection, so there are no admit cards to print.</p>
                </div>
            </div>
        </div>
    @else
        <div class="admit-sheet">
            @foreach($students as $sr)
                @include('pages.support_team.exams.admit_card', ['sr' => $sr])
            @endforeach
        </div>
    @endif

@endsection
