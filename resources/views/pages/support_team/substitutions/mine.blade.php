@extends('layouts.master')
@section('page_title', 'My Extra Classes')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; My Extra Classes
@endsection
@section('content')
    {{-- Owner order 2026-10-30: the substitute's one place — every
         extra class they cover, grouped by date. This is where the
         substitution bell lands. Confirmed cover is live (it
         carries attendance + class access for that period only);
         planned rows go live when the leave is approved. --}}
    <div class="card">
        <div class="card-header">
            <h5 class="card-title">My Extra Classes</h5>
            <p class="text-muted mb-0">Periods you cover for a colleague on leave. For a confirmed period you can take its attendance and open its class materials — for that period and date only.</p>
        </div>
        <div class="card-body">
            @if($upcoming->isEmpty() && $planned->isEmpty() && $past->isEmpty())
                <p class="text-muted mb-0">No extra classes right now. When a colleague's leave makes you the substitute, the period will appear here and your bell will ring.</p>
            @endif

            @foreach($upcoming as $dateStr => $rows)
                <h6 class="mb-2 mt-3">
                    @if($dateStr === $today) Today — @endif{{ \Illuminate\Support\Carbon::parse($dateStr)->format('l, d M Y') }}
                    <span class="badge badge-success">{{ $rows->count() }} period(s)</span>
                </h6>
                <div class="table-responsive mb-3">
                    <table class="table table-sm">
                        <thead>
                            <tr><th>Time</th><th>Class</th><th>Subject</th><th>Covering For</th><th></th></tr>
                        </thead>
                        <tbody>
                            @foreach($rows as $row)
                                <tr>
                                    <td data-label="Time">{{ optional(optional($row->time_table)->time_slot)->full ?: '—' }}</td>
                                    <td data-label="Class">
                                        {{ optional(optional(optional($row->time_table)->tt_record)->my_class)->name ?: '—' }}
                                        @if(optional(optional($row->time_table)->tt_record)->section)
                                            — {{ $row->time_table->tt_record->section->name }}
                                        @endif
                                    </td>
                                    <td data-label="Subject">{{ optional(optional($row->time_table)->subject)->name ?: '—' }}</td>
                                    <td data-label="Covering For">{{ optional($row->absent_teacher)->name ?: '—' }}</td>
                                    <td data-label="">
                                        @if($dateStr === $today)
                                            <a href="{{ route('attendance.periods.index', ['date' => $dateStr]) }}" class="btn btn-sm btn-primary">Take Attendance</a>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endforeach

            @if($planned->isNotEmpty())
                <h6 class="mb-2 mt-4">Planned for you <span class="badge badge-warning">Goes live when the leave is approved</span></h6>
                <div class="table-responsive mb-3">
                    <table class="table table-sm">
                        <thead>
                            <tr><th>Date</th><th>Time</th><th>Class</th><th>Subject</th><th>Covering For</th></tr>
                        </thead>
                        <tbody>
                            @foreach($planned as $dateStr => $rows)
                                @foreach($rows as $row)
                                    <tr>
                                        <td data-label="Date">{{ \Illuminate\Support\Carbon::parse($dateStr)->format('D, d M Y') }}</td>
                                        <td data-label="Time">{{ optional(optional($row->time_table)->time_slot)->full ?: '—' }}</td>
                                        <td data-label="Class">
                                            {{ optional(optional(optional($row->time_table)->tt_record)->my_class)->name ?: '—' }}
                                            @if(optional(optional($row->time_table)->tt_record)->section)
                                                — {{ $row->time_table->tt_record->section->name }}
                                            @endif
                                        </td>
                                        <td data-label="Subject">{{ optional(optional($row->time_table)->subject)->name ?: '—' }}</td>
                                        <td data-label="Covering For">{{ optional($row->absent_teacher)->name ?: '—' }}</td>
                                    </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if($past->isNotEmpty())
                <h6 class="mb-2 mt-4">Recent cover</h6>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr><th>Date</th><th>Time</th><th>Class</th><th>Subject</th><th>Covering For</th></tr>
                        </thead>
                        <tbody>
                            @foreach($past as $row)
                                <tr>
                                    <td data-label="Date">{{ $row->date ? $row->date->format('D, d M Y') : '—' }}</td>
                                    <td data-label="Time">{{ optional(optional($row->time_table)->time_slot)->full ?: '—' }}</td>
                                    <td data-label="Class">
                                        {{ optional(optional(optional($row->time_table)->tt_record)->my_class)->name ?: '—' }}
                                        @if(optional(optional($row->time_table)->tt_record)->section)
                                            — {{ $row->time_table->tt_record->section->name }}
                                        @endif
                                    </td>
                                    <td data-label="Subject">{{ optional(optional($row->time_table)->subject)->name ?: '—' }}</td>
                                    <td data-label="Covering For">{{ optional($row->absent_teacher)->name ?: '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection
