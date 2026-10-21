@extends('layouts.master')
@section('page_title', 'Student Marksheet')
@section('content')

    <div class="card">
        <div class="card-header text-center">
            <h4 class="card-title font-weight-bold">Student Marksheet — {{ $sr->user->name.' ('.$my_class->name.' '.($sr->section ? $sr->section->name : $my_class->section->first()->name).')' }} </h4>
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

    @foreach($exams as $ex)
        @foreach($exam_records->where('exam_id', $ex->id) as $exr)

                <div class="card">
                    <div class="card-header header-elements-inline">
                        <h6 class="font-weight-bold">{{ $ex->name.' - '.$ex->year }}</h6>
                        {!! Qs::getPanelOptions() !!}
                    </div>

                    <div class="card-body collapse">

                        {{--Sheet Table--}}
                        @include('pages.support_team.marks.show.sheet')

                        {{--Print Button--}}
                        <div class="text-center mt-3">
                            <a target="_blank" href="{{ route('marks.print', [Qs::hash($student_id), $ex->id, $year]) }}" class="btn btn-secondary btn-lg">Print Marksheet <i class="icon-printer ml-2"></i></a>
                        </div>

                    </div>

                </div>

            {{--    EXAM COMMENTS   --}}
            @include('pages.support_team.marks.show.comments')

            {{-- SKILL RATING (staff entry forms; hidden from students/parents) --}}
            @if($skills && Qs::userIsTeamSAT())
                @include('pages.support_team.marks.show.skills')
            @endif

        @endforeach
    @endforeach

    {{-- GRADE SCALE LEGEND (from the grades table for this class type) --}}
    @if($class_type && Mk::getGradeList($class_type->id)->count())
        <div class="card">
            <div class="card-header">
                <h6 class="card-title font-weight-bold">Grade Scale</h6>
            </div>
            <div class="card-body">
                <div class="d-flex flex-wrap">
                    @foreach(Mk::getGradeList($class_type->id) as $gr)
                        <span class="badge badge-light border mr-2 mb-2" style="font-size: 0.85rem;">
                            <strong>{{ $gr->name }}</strong>: {{ $gr->mark_from }}–{{ $gr->mark_to }}{{ $gr->remark ? ' ('.$gr->remark.')' : '' }}
                        </span>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

@endsection
