@extends('layouts.master')
@section('page_title', 'View TimeTable')
@section('content')

    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">{{ $ttr->name }}</h6>
            {!! Qs::getPanelOptions() !!}
        </div>
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
                    $map[$dt['day']][$dt['time']] = ['title' => $dt['subject'], 'sub' => ''];
                }
                $day_labels = [];
                if ($ttr->exam_id) {
                    foreach ($days as $day) {
                        $day_labels[$day] = date('l', strtotime($day)) . ' · ' . date('d/m/Y', strtotime($day));
                    }
                }
            @endphp

            @include('pages.support_team.timetables.partials.grid')

            {{--Print Button--}}
            <div class="text-center mt-4">
                <a target="_blank" href="{{ route('ttr.print', $ttr->id) }}" class="btn btn-primary"><i class="icon-printer mr-2"></i> Print Timetable</a>
            </div>
        </div>
    </div>

@endsection
