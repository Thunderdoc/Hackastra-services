@extends('layouts.master')
@section('page_title', 'Manage Marks')
@section('content')

    {{-- Workstream BW: the picker now sits in a pat-form-card like
         marks/index (its legacy "Fill The Form To Manage Marks"
         header card is dropped). The marks grid card below is
         unchanged. --}}
    <div class="card pat-form-card">
        @include('pages.support_team.marks.selector')
    </div>

    <div class="card">

        <div class="card-header">
            <div class="row">
                <div class="col-md-4"><h6 class="card-title"><strong>Subject: </strong> {{ $m->subject->name }}</h6></div>
                <div class="col-md-4"><h6 class="card-title"><strong>Class: </strong> {{ $m->my_class->name.' '.$m->section->name }}</h6></div>
                <div class="col-md-4"><h6 class="card-title"><strong>Exam: </strong> {{ $m->exam->name.' - '.$m->year }}</h6></div>
            </div>
        </div>

        <div class="card-body">
            @include('pages.support_team.marks.edit')
            {{--@include('pages.support_team.marks.random')--}}
        </div>
    </div>

    {{--Marks Manage End--}}

@endsection
