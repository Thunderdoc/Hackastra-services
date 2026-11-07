@extends('layouts.master')
@section('page_title', 'My Hall Sheets')
@section('content')

    {{-- F3 — teacher surface (§8): my own PUBLISHED hall sheets
         only, scoped server-side to the sittings I invigilate
         (myDuties precedent). Read-only. --}}
    @component('partials.patterns.list_page')
        @slot('subtitle')
            The published hall sheets for the sittings you invigilate. Read-only — seating is set by the office.
        @endslot
        @slot('action')
            <a href="{{ route('exam_duties.my') }}" class="btn btn-light btn-sm"><i class="icon-eye mr-1"></i> My Duties</a>
        @endslot

        @if(empty($sheets))
            <div class="alert alert-info border-0">No published hall sheets for your duties yet. Hall sheets appear here once the Principal publishes the seating plan for a sitting you invigilate.</div>
        @else
            <div class="table-responsive">
                <table class="table hk-std hk-std-seating">
                    <thead>
                        <tr>
                            <th>Exam</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Hall</th>
                            <th>Students</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($sheets as $sheet)
                            <tr>
                                <td>{{ $sheet['plan']->exam->name ?? '' }}</td>
                                <td>{{ \Carbon\Carbon::parse($sheet['plan']->exam_date)->format('D, d M Y') }}</td>
                                <td>{{ $sheet['plan']->start_time ? \Carbon\Carbon::parse($sheet['plan']->start_time)->format('h:i A') : '—' }}</td>
                                <td>{{ $sheet['hall']['room_name'] }}</td>
                                <td>{{ $sheet['hall']['seats']->count() }}</td>
                                <td>
                                    <a href="{{ route('exam_seating.my_hall_sheet', ['plan_id' => $sheet['plan']->id, 'room_id' => $sheet['hall']['room_id']]) }}" class="btn btn-light btn-sm">Hall Sheet</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endcomponent
@endsection
