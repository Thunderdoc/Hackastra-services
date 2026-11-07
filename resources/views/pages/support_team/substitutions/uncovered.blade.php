@extends('layouts.master')
@section('page_title', 'Uncovered Periods')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; <a href="{{ route('substitutions.index') }}">Substitutions</a> &nbsp;/&nbsp; Uncovered Periods
@endsection
@section('content')
    <style>
        .unc-act { display: flex; flex-direction: column; gap: 6px; }
        .unc-act form { display: flex; flex-direction: column; gap: 6px; margin: 0; }
        .unc-act form select { width: 100%; min-width: 0; margin: 0 !important; }
        .unc-act form button { width: 100%; margin: 0 !important; }
        .unc-free-none { color: #8a97a8; }
        html.hk-dark .unc-free-none { color: #93a7bd; }
    </style>
    @if(session('flash_success'))
        <div class="alert alert-success">{{ session('flash_success') }}</div>
    @endif
    @if(session('flash_danger'))
        <div class="alert alert-danger">{{ session('flash_danger') }}</div>
    @endif

    @component('partials.patterns.list_page')
        @slot('subtitle')
            Periods with no cover for the chosen date — whose class it is, and to whom it can go. Allot a free teacher, or cancel the class for the day.
        @endslot
        @slot('action')
            <a href="{{ route('substitutions.index') }}" class="btn btn-light"><i class="icon-list mr-1"></i> Substitution Sheet</a>
        @endslot
        @slot('filters')
            <form method="GET" action="{{ route('substitutions.uncovered') }}" class="pat-filter-form">
                <div class="pat-filter-field">
                    <label for="unc_date">Date</label>
                    <input type="date" id="unc_date" name="date" class="form-control" value="{{ $date_str }}">
                </div>
                <div class="pat-filter-action">
                    <button type="submit" class="btn btn-primary"><i class="icon-filter3 mr-1"></i> Show</button>
                    <a href="{{ route('substitutions.uncovered') }}" class="btn btn-light ml-1">Today</a>
                </div>
            </form>
        @endslot

        @if(empty($rows))
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-checkmark-circle2',
                'message' => 'No uncovered periods for ' . $date->format('d M Y') . ' — every period has cover or is cancelled.',
            ])
        @else
            <table class="table hk-std hk-std-uncovered">
                <colgroup><col style="width:12%"><col style="width:13%"><col style="width:17%"><col style="width:15%"><col style="width:15%"><col style="width:16%"><col style="width:12%"></colgroup>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Period</th>
                        <th>Class – Section</th>
                        <th>Subject</th>
                        <th>Absent Teacher</th>
                        <th>Free Teachers</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $row)
                        @php
                            $cell = $row['cell'];
                            $freeTeachers = $row['free_teachers'] ?? collect();
                        @endphp
                        <tr>
                            <td class="hk-nw">{{ $date->format('d M Y') }}</td>
                            <td class="hk-nw">{{ optional($cell->time_slot)->full ?: '—' }}</td>
                            <td>
                                {{ optional(optional($cell->tt_record)->my_class)->name ?: '—' }}
                                @if(optional(optional($cell->tt_record)->section)->name)
                                    – {{ $cell->tt_record->section->name }}
                                @else
                                    – All sections
                                @endif
                            </td>
                            <td>{{ optional($cell->subject)->name ?: '—' }}</td>
                            <td>{{ optional($row['absent_teacher'])->name ?: '—' }}</td>
                            <td>
                                @if($freeTeachers->isNotEmpty())
                                    {{ $freeTeachers->pluck('name')->implode(', ') }}
                                @else
                                    <span class="unc-free-none">None free</span>
                                @endif
                            </td>
                            <td>
                                <div class="unc-act">
                                    @if($freeTeachers->isNotEmpty())
                                        <form method="POST" action="{{ route('substitutions.allot') }}">
                                            @csrf
                                            <input type="hidden" name="date" value="{{ $date_str }}">
                                            <input type="hidden" name="time_table_id" value="{{ $row['time_table_id'] }}">
                                            <select name="substitute_teacher_id" class="form-control form-control-sm @error('substitute_teacher_id') is-invalid @enderror" aria-label="Free teacher">
                                                @foreach($freeTeachers as $teacher)
                                                    <option value="{{ $teacher->id }}">{{ $teacher->name }}</option>
                                                @endforeach
                                            </select>@include('partials.errors.field', ['field' => 'substitute_teacher_id'])
                                            <button type="submit" class="btn btn-sm btn-primary">Allot</button>
                                        </form>
                                    @endif
                                    <form method="POST" action="{{ route('substitutions.cancel_class') }}">
                                        @csrf
                                        <input type="hidden" name="date" value="{{ $date_str }}">
                                        <input type="hidden" name="time_table_id" value="{{ $row['time_table_id'] }}">
                                        <button type="submit" class="btn btn-sm btn-danger" data-confirm="Cancel this class for the day? Students will be notified that there is no class for this period." data-confirm-title="Cancel Class" data-confirm-action="Cancel Class" data-confirm-danger>Cancel class</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endcomponent
@endsection
