@extends('layouts.master')
@section('page_title', 'Timetables')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Timetables
@endsection
@section('content')

    {{-- Parent Timetables (owner live catch, 2026-11-08): a parent
         never sees the Manage index — no class/section chooser, no
         class cards. Only their own children, one direct link per
         child to that child's own class+section record (the same
         record tt.mine resolves). A child whose timetable is not
         in force yet is named honestly instead of linked. --}}

    @include('partials.module_banner', [
        'hkModIcon' => 'icon-table2',
        'hkModTone' => 'blue',
        'hkModSub' => 'Your children\'s class timetables for the current academic session.',
        'hkModStats' => [
            ['value' => $rows->count(), 'label' => 'Children'],
        ],
        'hkModChip' => 'Session ' . $year,
    ])

    <div class="card"><div class="card-body">
        @if($rows->count() === 0)
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-table2',
                'message' => 'No children are linked to your account yet.',
            ])
        @else
            @foreach($rows as $row)
                @php
                    $child = $row['child'];
                    $classSection = optional($child->my_class)->name
                        . ($child->section ? ' · Section ' . $child->section->name : '');
                @endphp
                <div class="d-flex align-items-center justify-content-between {{ !$loop->last ? 'border-bottom pb-3 mb-3' : '' }}">
                    <div>
                        <div class="font-weight-bold">{{ optional($child->user)->name }}</div>
                        <div class="text-muted small">{{ $classSection }}</div>
                    </div>
                    @if($row['ttr'])
                        <a href="{{ route('ttr.show', $row['ttr']->id) }}" class="btn btn-primary btn-sm"><i class="icon-table2 mr-1"></i> Open Timetable</a>
                    @else
                        <span class="text-muted small">No timetable has been published for this class yet.</span>
                    @endif
                </div>
            @endforeach
        @endif
    </div></div>

@endsection
