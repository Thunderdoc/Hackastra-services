@extends('layouts.master')
@section('page_title', 'Take Attendance')
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">
                {{ $period['subject']->name }} — {{ $period['class']->name }}
                <span class="text-muted font-size-sm ml-2">{{ $period['time_label'] }} · {{ $date->format('l, d M Y') }} · {{ $students->count() }} students</span>
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

            <div class="reg-toolbar">
                <span class="reg-toolbar-note"><i class="icon-info22 mr-1"></i> Roll numbers are allocated by the system in admission order — the same numbers appear in the roster exports.</span>
                <span class="reg-toolbar-btns">
                    <a href="{{ route('attendance.periods.roster_export', $period['tt']->id) }}" class="btn btn-light btn-sm"><i class="icon-file-spreadsheet mr-1"></i> Export roster (Excel)</a>
                    <a href="{{ route('attendance.periods.roster_pdf', [$period['tt']->id, 'date' => $date->format('Y-m-d')]) }}" target="_blank" class="btn btn-light btn-sm"><i class="icon-file-pdf mr-1"></i> Print roster (PDF)</a>
                </span>
            </div>

            @if($students->isEmpty())
                <p class="text-muted mb-0">No active students in {{ $period['class']->name }} yet — there is no roster to mark.</p>
            @else
                <form method="POST" action="{{ route('attendance.periods.store', $period['tt']->id) }}" id="period-register-form">
                    @csrf
                    <input type="hidden" name="date" value="{{ $date->format('Y-m-d') }}">

                    <div class="pat-fields reg-topic">
                        <div class="pat-field pat-field-wide">
                            <label for="pa_topic">Topic taught in this period <span class="text-danger">*</span></label>
                            <input type="text" name="topic" id="pa_topic" class="form-control" maxlength="255" required
                                   placeholder="e.g. Quadratic Equations — solving by factorisation"
                                   value="{{ old('topic') }}">
                            <span class="form-text">This topic is saved on the register and appears in the subject-wise report's session log.</span>
                        </div>
                    </div>

                    <div class="reg-list">
                        @foreach($students as $student)
                            @php($current = old('statuses.' . $student->id, 'present'))
                            <div class="reg-row" data-reg-row data-status="{{ $current }}">
                                <div class="reg-who">
                                    <span class="reg-roll">{{ $student->roll_no ?? $loop->iteration }}</span>
                                    <div class="reg-id">
                                        <span class="reg-name">{{ optional($student->user)->name }}</span>
                                        <span class="reg-meta">{{ optional($student->section)->name ?: '—' }} · {{ $student->adm_no ?: '—' }}</span>
                                    </div>
                                </div>
                                <div class="reg-seg" role="radiogroup" aria-label="Attendance — {{ optional($student->user)->name }}">
                                    @foreach(['present' => 'Present', 'absent' => 'Absent', 'late' => 'Late'] as $value => $label)
                                        <span class="reg-seg-item">
                                            <input type="radio" class="reg-radio" id="st-{{ $student->id }}-{{ $value }}" name="statuses[{{ $student->id }}]" value="{{ $value }}" aria-label="{{ $label }} — {{ optional($student->user)->name }}" {{ $current === $value ? 'checked' : '' }}>
                                            <label class="reg-seg-btn reg-seg-{{ $value }}" for="st-{{ $student->id }}-{{ $value }}">{{ $label }}</label>
                                        </span>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="reg-bar">
                        <div class="reg-bar-summary" aria-live="polite">
                            <span class="reg-count reg-count-present"><i></i>Present <strong data-reg-count="present">0</strong></span>
                            <span class="reg-count reg-count-absent"><i></i>Absent <strong data-reg-count="absent">0</strong></span>
                            <span class="reg-count reg-count-late"><i></i>Late <strong data-reg-count="late">0</strong></span>
                            <span class="reg-count reg-count-unmarked"><i></i>Unmarked <strong data-reg-count="unmarked">0</strong></span>
                            <span class="reg-bar-lock"><i class="icon-lock2"></i> Locks for {{ $period['subject']->name }} · {{ $date->format('d M Y') }} on submit</span>
                        </div>
                        <div class="reg-bar-actions">
                            <button type="button" id="mark-all-present" class="btn btn-light"><i class="icon-checkmark3 mr-1"></i> Mark all present</button>
                            <a href="{{ route('attendance.periods.index', ['date' => $date->format('Y-m-d')]) }}" class="btn btn-light">Back to my periods</a>
                            <button type="submit" class="btn btn-primary"><i class="icon-lock2 mr-1"></i> Submit Attendance (final)</button>
                        </div>
                    </div>
                </form>
            @endif
        </div>
    </div>
@endsection

@section('scripts')
    <script>
        // Register UX (Loop 61): the segmented buttons are labels for the
        // real radios below them — this script only mirrors state: it
        // counts the checked radios into the sticky bar's live summary
        // and stamps each row's data-status so unmarked rows stand out.
        // It never changes what the form submits.
        (function () {
            var form = document.getElementById('period-register-form');
            if (!form) { return; }

            var counts = {
                present: form.querySelector('[data-reg-count="present"]'),
                absent: form.querySelector('[data-reg-count="absent"]'),
                late: form.querySelector('[data-reg-count="late"]'),
                unmarked: form.querySelector('[data-reg-count="unmarked"]')
            };

            function sync() {
                var tally = { present: 0, absent: 0, late: 0, unmarked: 0 };
                var rows = form.querySelectorAll('[data-reg-row]');
                for (var i = 0; i < rows.length; i++) {
                    var checked = rows[i].querySelector('input.reg-radio:checked');
                    var status = checked ? checked.value : 'unmarked';
                    rows[i].setAttribute('data-status', status);
                    tally[status] = (tally[status] || 0) + 1;
                }
                for (var key in counts) {
                    if (counts[key]) { counts[key].textContent = tally[key]; }
                }
            }

            form.addEventListener('change', function (e) {
                if (e.target && e.target.classList && e.target.classList.contains('reg-radio')) { sync(); }
            });

            var btn = document.getElementById('mark-all-present');
            if (btn) {
                btn.addEventListener('click', function () {
                    var radios = form.querySelectorAll('input.reg-radio[value="present"]');
                    for (var i = 0; i < radios.length; i++) { radios[i].checked = true; }
                    sync();
                });
            }

            sync();
        })();
    </script>
@endsection
