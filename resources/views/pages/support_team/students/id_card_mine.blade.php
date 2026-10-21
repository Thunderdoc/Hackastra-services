@extends('layouts.master')
@section('page_title', 'My ID Card')
@section('content')

    <div class="idc-page">
        <div class="card idc-screen-only">
            <div class="card-header header-elements-inline">
                <h6 class="card-title">{{ $mode === 'parent' ? 'Student ID Cards' : 'My ID Card' }}</h6>
                {!! Qs::getPanelOptions() !!}
            </div>

            <div class="card-body">
                @if($mode === 'parent' && $children->count() > 1)
                    <div class="idc-switcher">
                        <span class="idc-switcher-label">Child:</span>
                        @foreach($children as $c)
                            <a href="{{ route('id_card.mine', ['child' => $c->id]) }}" class="btn btn-sm {{ $sr && $sr->id === $c->id ? 'btn-primary' : 'btn-light' }}">{{ $c->user->name }}</a>
                        @endforeach
                    </div>
                @endif

                @if($sr)
                    <p class="idc-lead">
                        @if($mode === 'parent')
                            {{ $sr->user->name }} &middot; {{ $sr->my_class->name }}@if($sr->section) &middot; {{ $sr->section->name }}@endif
                        @else
                            {{ $sr->my_class->name }}@if($sr->section) &middot; Section {{ $sr->section->name }}@endif &middot; Adm No {{ $sr->adm_no ?: '—' }}
                        @endif
                    </p>
                    <button type="button" class="btn btn-primary" onclick="window.print()"><i class="icon-printer mr-2"></i> Print ID Card</button>
                @else
                    <div class="alert alert-info text-center" style="margin:10px 0 4px">
                        @if($mode === 'parent')
                            No children are linked to your account yet.
                        @else
                            Your student record could not be found. Please contact the school office.
                        @endif
                    </div>
                @endif
            </div>
        </div>

        @if($sr)
            <div class="idc-stage">
                @include('pages.support_team.students.partials.id_card', ['sr' => $sr])
            </div>
        @endif
    </div>

@endsection
