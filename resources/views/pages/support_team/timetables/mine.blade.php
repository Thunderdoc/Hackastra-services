@extends('layouts.master')
@section('page_title', 'My Timetable')
@section('content')

    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">My Timetable</h6>
            {!! Qs::getPanelOptions() !!}
        </div>

        <div class="card-body">
            @if($mode === 'teacher')
                <p class="ttx-lead">Your weekly teaching periods &middot; <strong>{{ $period_count }}</strong> period{{ $period_count === 1 ? '' : 's' }} a week</p>
            @elseif($mode === 'parent')
                @if($children->count() > 1)
                    <div class="ttx-switcher">
                        <span class="ttx-switcher-label">Child:</span>
                        @foreach($children as $c)
                            <a href="{{ route('tt.mine', ['child' => $c->id]) }}" class="btn btn-sm {{ $child && $child->id === $c->id ? 'btn-primary' : 'btn-light' }}">{{ $c->user->name }}</a>
                        @endforeach
                    </div>
                @endif
                @if($child)
                    <p class="ttx-lead">{{ $child->user->name }} &middot; {{ $child->my_class->name }}@if($child->section) &middot; {{ $child->section->name }}@endif</p>
                @endif
            @elseif($mode === 'student' && $child)
                <p class="ttx-lead">{{ $child->my_class->name }}@if($child->section) &middot; Section {{ $child->section->name }}@endif</p>
            @endif

            @if($slots->isEmpty())
                <div class="alert alert-info text-center" style="margin:10px 0 4px">
                    @if($mode === 'teacher')
                        You have no teaching periods scheduled yet.
                    @elseif($mode === 'parent' && !$child)
                        No children are linked to your account yet.
                    @else
                        No timetable has been published for this class yet.
                    @endif
                </div>
            @else
                @include('pages.support_team.timetables.partials.grid')
            @endif
        </div>
    </div>

@endsection
