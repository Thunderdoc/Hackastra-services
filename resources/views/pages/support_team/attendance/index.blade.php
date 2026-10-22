@extends('layouts.master')
@section('page_title', 'Attendance')
@section('content')
    {{-- Loop 108: legacy card-header chrome dropped (panel options +
         the repeated "Daily Attendance" title — the common header
         carries the page title) for the shared pat shell. The
         page-specific .attendance-grid styles moved out of this
         inline <style> block into qs.css Loop 108, which also adds
         the hk-dark sticky-column colours (the inline copy hardcoded
         white, so the sticky student column rendered white in dark
         mode). Filter, summary, grid and save flow unchanged. --}}
    <div class="pat-list">
        <div class="card pat-card">
        <div class="card-body">
            @if($selected_class)
                <div class="d-flex flex-wrap align-items-center mb-3 l108-badges">
                    <span class="badge badge-primary mr-2 mb-1">{{ $selected_class->name }}</span>
                    <span class="badge badge-light mr-2 mb-1">{{ $date->format('d M Y') }}</span>
                    <span class="badge badge-info mr-2 mb-1">Students: {{ $summary['total'] }}</span>
                    <span class="badge {{ $summary['marked'] === $summary['total'] && $summary['total'] > 0 ? 'badge-success' : 'badge-warning' }} mr-2 mb-1">Marked: {{ $summary['marked'] }} of {{ $summary['total'] }}</span>
                    <span class="badge badge-success mr-2 mb-1">Present: {{ $summary['present'] }}</span>
                    <span class="badge badge-danger mr-2 mb-1">Absent: {{ $summary['absent'] }}</span>
                    <span class="badge badge-warning mr-2 mb-1">Late: {{ $summary['late'] }}</span>
                    @if($summary['total'] > 0 && $summary['marked'] < $summary['total'])
                        <span class="text-muted mb-1">{{ $summary['total'] - $summary['marked'] }} not yet marked for this date.</span>
                    @endif
                </div>
            @endif

            {{-- Workstream BW: filter bar moved onto the shared
                 pat-filter-form (was form-inline). Same GET fields
                 and auto-submit onchange behaviour. --}}
            <form method="GET" action="{{ route('attendance.index') }}" class="pat-filter-form mb-3">
                <div class="pat-filter-field">
                    <label for="att_class_id">Class</label>
                    <select name="class_id" id="att_class_id" class="form-control" onchange="this.form.submit()">
                        @foreach($classes as $class)
                            <option value="{{ $class->id }}" {{ $selected_class && $selected_class->id === $class->id ? 'selected' : '' }}>{{ $class->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-filter-field">
                    <label for="att_date">Date</label>
                    <input type="date" name="date" id="att_date" class="form-control" value="{{ $date->format('Y-m-d') }}" onchange="this.form.submit()">
                </div>
            </form>

            @if($students->isEmpty())
                <p class="text-muted mb-0">No active students in this class yet.</p>
            @else
                <form method="POST" action="{{ route('attendance.store') }}">
                    @csrf
                    <input type="hidden" name="class_id" value="{{ $selected_class->id }}">
                    <input type="hidden" name="date" value="{{ $date->format('Y-m-d') }}">
                    <div class="mb-2 text-right">
                        <button type="button" id="mark-all-present" class="btn btn-light btn-sm"><i class="icon-checkmark3 mr-1"></i> Mark all present</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table attendance-grid">
                            <thead>
                            <tr>
                                <th class="att-sticky">Student</th>
                                <th>Section</th>
                                <th>Admission No</th>
                                <th class="att-status">Present</th>
                                <th class="att-status">Absent</th>
                                <th class="att-status">Late</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($students as $student)
                                @php($current = $existing[$student->id] ?? 'present')
                                <tr>
                                    <td class="att-sticky"><strong>{{ optional($student->user)->name }}</strong></td>
                                    <td>{{ optional($student->section)->name ?: '—' }}</td>
                                    <td>{{ $student->adm_no ?: '—' }}</td>
                                    @foreach(['present', 'absent', 'late'] as $value)
                                        <td class="att-status">
                                            <input type="radio" class="att-radio" name="statuses[{{ $student->id }}]" value="{{ $value }}" aria-label="{{ ucfirst($value) }} — {{ optional($student->user)->name }}" {{ $current === $value ? 'checked' : '' }}>
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="text-right">
                        <button type="submit" class="btn btn-primary mt-2">Save Attendance</button>
                    </div>
                </form>
            @endif
        </div>
        </div>
    </div>
@endsection

@section('scripts')
    <script>
        // "Mark all present" convenience: ticks the Present radio in
        // every row; the teacher can still flip individual students
        // before saving. Dependency-free on purpose.
        (function () {
            var btn = document.getElementById('mark-all-present');
            if (!btn) { return; }
            btn.addEventListener('click', function () {
                var radios = document.querySelectorAll('input.att-radio[value="present"]');
                for (var i = 0; i < radios.length; i++) { radios[i].checked = true; }
            });
        })();
    </script>
@endsection
