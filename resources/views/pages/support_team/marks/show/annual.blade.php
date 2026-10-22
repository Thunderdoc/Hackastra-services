@extends('layouts.master')
@section('page_title', 'Annual Report Sheet')
@section('content')

    <style>
        .marks-section-header { background: #eef3fb; border-bottom: 2px solid #2867dc; color: #172536; }
        .marks-section-header .card-title { color: #172536; }
        .marks-section-header .list-icons-item { color: #43556c; }
    </style>

    <div class="card">
        <div class="card-header text-center">
            <h4 class="card-title font-weight-bold">Annual / Cumulative Report — {{ $sr->user->name.' ('.$my_class->name.' '.($sr->section ? $sr->section->name : $my_class->section->first()->name).')' }} </h4>
        </div>
        <div class="card-body">
            <div class="row text-center">
                <div class="col-md-3 col-6">
                    <small class="text-muted text-uppercase">Admission No</small>
                    <div class="font-weight-bold">{{ $sr->adm_no ?: '-' }}</div>
                </div>
                <div class="col-md-3 col-6">
                    <small class="text-muted text-uppercase">Class</small>
                    <div class="font-weight-bold">{{ $my_class->name }}</div>
                </div>
                <div class="col-md-3 col-6">
                    <small class="text-muted text-uppercase">Section</small>
                    <div class="font-weight-bold">{{ $sr->section ? $sr->section->name : '-' }}</div>
                </div>
                <div class="col-md-3 col-6">
                    <small class="text-muted text-uppercase">Session</small>
                    <div class="font-weight-bold">{{ $year }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="font-weight-bold">Annual Report Sheet - {{ $year }}</h6>
            {!! Qs::getPanelOptions() !!}
        </div>
        <div class="card-body">

            {{-- Batch M4: the official annual report card — the SAME
                 template the printed document uses
                 (marks/partials/annual_report_card), fed by the
                 App\Support\ReportCard::buildAnnual assembly. --}}
            @include('pages.support_team.marks.partials.annual_report_card')

            {{--Print Button — same visibility rule as the term
                 marksheet's: only users the print gate admits
                 (teamSA, the student, or their parent) see it. --}}
            @if(Qs::userIsTeamSA() || Auth::id() == $student_id || Qs::userIsMyChild($student_id, Auth::id()))
            <div class="text-center mt-3">
                <a target="_blank" href="{{ route('marks.print_annual', [Qs::hash($student_id), $year]) }}" class="btn btn-secondary btn-lg">Print Annual Report <i class="icon-printer ml-2"></i></a>
            </div>
            @endif

        </div>
    </div>

@endsection
