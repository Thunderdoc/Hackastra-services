@extends('layouts.master')
@section('page_title', 'ID Card - '.$sr->user->name)
@section('content')

    <div class="idc-page">
        <div class="card idc-screen-only">
            <div class="card-header header-elements-inline">
                <h6 class="card-title">Student ID Card</h6>
                {!! Qs::getPanelOptions() !!}
            </div>

            <div class="card-body">
                <p class="idc-lead">{{ $sr->user->name }} &middot; {{ $sr->my_class->name }}@if($sr->section) &middot; {{ $sr->section->name }}@endif &middot; Adm No {{ $sr->adm_no ?: '—' }}</p>
                <a href="{{ route('students.show', Qs::hash($sr->id)) }}" class="btn btn-light"><i class="icon-arrow-left7 mr-2"></i> Back to Profile</a>
                <button type="button" class="btn btn-primary" onclick="window.print()"><i class="icon-printer mr-2"></i> Print ID Card</button>
            </div>
        </div>

        <div class="idc-stage">
            @include('pages.support_team.students.partials.id_card', ['sr' => $sr])
        </div>
    </div>

@endsection
