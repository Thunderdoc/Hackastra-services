@extends('layouts.master')
@section('page_title', 'Take Attendance')
@section('content')
    <style>
        .period-grid th.period-status, .period-grid td.period-status { text-align: center; }
        .period-grid .period-radio { margin: 0; }
    </style>
    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">
                {{ $period['subject']->name }} — {{ $period['class']->name }}
                <span class="text-muted font-size-sm ml-2">{{ $period['time_label'] }} · {{ $date->format('l, d M Y') }}</span>
            </h6>
            {!! Qs::getPanelOptions() !!}
        </div>
        <div class="card-body">
            @if($errors->any())
                <div class="alert alert-danger border-0">
                    @foreach($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            <div class="alert alert-warning border-0">
                <i class="icon-lock2 mr-1"></i>
                <strong>Submission is final.</strong> Once you submit, this register is locked for
                {{ $period['subject']->name }} on {{ $date->format('d M Y') }} and cannot be edited or taken again.
            </div>

            @if($students->isEmpty())
                <p class="text-muted mb-0">No active students in {{ $period['class']->name }} yet — there is no roster to mark.</p>
            @else
                <form method="POST" action="{{ route('attendance.periods.store', $period['tt']->id) }}">
                    @csrf
                    <input type="hidden" name="date" value="{{ $date->format('Y-m-d') }}">

                    <div class="form-group">
                        <label for="pa_topic"><strong>Topic taught in this period</strong> <span class="text-danger">*</span></label>
                        <input type="text" name="topic" id="pa_topic" class="form-control" maxlength="255" required
                               placeholder="e.g. Quadratic Equations — solving by factorisation"
                               value="{{ old('topic') }}">
                        <span class="text-muted font-size-sm">This topic is saved on the register and appears in the subject-wise report's session log.</span>
                    </div>

                    <div class="mb-2 text-right">
                        <button type="button" id="mark-all-present" class="btn btn-light btn-sm"><i class="icon-checkmark3 mr-1"></i> Mark all present</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table period-grid">
                            <thead>
                            <tr>
                                <th>Student</th>
                                <th>Section</th>
                                <th>Admission No</th>
                                <th class="period-status">Present</th>
                                <th class="period-status">Absent</th>
                                <th class="period-status">Late</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($students as $student)
                                @php($current = old('statuses.' . $student->id, 'present'))
                                <tr>
                                    <td><strong>{{ optional($student->user)->name }}</strong></td>
                                    <td>{{ optional($student->section)->name ?: '—' }}</td>
                                    <td>{{ $student->adm_no ?: '—' }}</td>
                                    @foreach(['present', 'absent', 'late'] as $value)
                                        <td class="period-status">
                                            <input type="radio" class="period-radio" name="statuses[{{ $student->id }}]" value="{{ $value }}" aria-label="{{ ucfirst($value) }} — {{ optional($student->user)->name }}" {{ $current === $value ? 'checked' : '' }}>
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary"><i class="icon-lock2 mr-1"></i> Submit Attendance (final)</button>
                        <a href="{{ route('attendance.periods.index', ['date' => $date->format('Y-m-d')]) }}" class="btn btn-light ml-2">Back to my periods</a>
                    </div>
                </form>
            @endif
        </div>
    </div>
@endsection

@section('scripts')
    <script>
        // Same dependency-free "mark all present" convenience as the
        // day-wise register; individual students can still be flipped.
        (function () {
            var btn = document.getElementById('mark-all-present');
            if (!btn) { return; }
            btn.addEventListener('click', function () {
                var radios = document.querySelectorAll('input.period-radio[value="present"]');
                for (var i = 0; i < radios.length; i++) { radios[i].checked = true; }
            });
        })();
    </script>
@endsection
