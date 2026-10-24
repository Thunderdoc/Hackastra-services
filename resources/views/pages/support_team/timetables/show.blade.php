@extends('layouts.master')
@section('page_title', 'View TimeTable')
{{-- Workstream DB: rebuilt on the common header pattern (Loop 46) —
     breadcrumb + the Print Timetable action in the page header as the
     standard print action. The legacy card header with panel options
     and the floating centred print button are gone; the meta strip
     and the shared aligned grid (modernised in Loop 71) carry the
     page. Routes, data and the grid contract are unchanged. --}}
@section('page_breadcrumb')
    <a href="{{ route('tt.index') }}">Timetables</a> &middot; {{ $my_class->name }}
@endsection
@section('page_actions')
    <a href="{{ route('tt.index') }}" class="btn btn-light btn-sm"><i class="icon-arrow-left7 mr-1"></i> Back</a>
    <a target="_blank" href="{{ route('ttr.print', $ttr->id) }}" class="btn btn-light btn-sm"><i class="icon-printer mr-1"></i> Print Timetable</a>
@endsection
@section('content')

    <div class="card">
        <div class="card-body">
            <div class="ttx-meta">
                <span class="ttx-meta-item"><small>Timetable</small><strong>{{ $ttr->name }}</strong></span>
                <span class="ttx-meta-item"><small>Class</small><strong>{{ $my_class->name }}</strong></span>
                <span class="ttx-meta-item"><small>Type</small><strong>{{ ($ttr->exam_id) ? 'Exam TimeTable' : 'Class TimeTable' }}</strong></span>
                <span class="ttx-meta-item"><small>Year</small><strong>{{ $ttr->year }}</strong></span>
            </div>

            @php
                // Aligned-grid map from the controller's $d_time contract:
                // one lookup per (day, slot) so every row emits exactly one
                // cell per time slot; a missing entry renders as "—".
                $slots = $time_slots;
                $map = [];
                foreach ($d_time as $dt) {
                    $map[$dt['day']][$dt['time']] = ['title' => $dt['subject'], 'sub' => $dt['teacher'] ?? ''];
                }
                $day_labels = [];
                if ($ttr->exam_id) {
                    foreach ($days as $day) {
                        $day_labels[$day] = date('l', strtotime($day)) . ' · ' . date('d/m/Y', strtotime($day));
                    }
                }
            @endphp

            @include('pages.support_team.timetables.partials.grid')
        </div>
    </div>

@endsection
