{{--
    Shared student attendance block (LMS build, 2026-11-08):
    summary chips, overall bar, status legend and the By-Subject
    Conducted | Attended | % table whose rows expand into the
    real date-wise register. Used by My Attendance AND the My
    Week page so both surfaces render the same figures the same
    way. Expects: $summary, $subjects (StudentAttendance::build).
--}}
<div class="d-flex flex-wrap align-items-center mb-3 l108-badges">
    <span class="badge badge-success mr-2 mb-1">Present: {{ $summary['present'] }}</span>
    <span class="badge badge-danger mr-2 mb-1">Absent: {{ $summary['absent'] }}</span>
    <span class="badge badge-warning mr-2 mb-1">Late: {{ $summary['late'] }}</span>
    <span class="badge badge-info mr-2 mb-1">Periods marked: {{ $summary['marked'] }}</span>
    @if($summary['percent'] !== null)
        <span class="badge badge-primary mb-1">Attendance: {{ $summary['percent'] }}%</span>
    @endif
</div>

@if($summary['percent'] !== null)
    <div class="mw-overall">
        <div class="progress" style="height:8px">
            <div class="progress-bar {{ $summary['percent'] >= 75 ? 'bg-success' : ($summary['percent'] >= 50 ? 'bg-warning' : 'bg-danger') }}" role="progressbar" style="width: {{ $summary['percent'] }}%" aria-valuenow="{{ $summary['percent'] }}" aria-valuemin="0" aria-valuemax="100"></div>
        </div>
        <span class="mw-overall-text"><strong>{{ $summary['percent'] }}%</strong> &middot; {{ $summary['attended'] }} of {{ $summary['marked'] }} attended @if(!empty($summary['tier'])) &middot; {{ $summary['tier'] }} @endif</span>
    </div>
@endif

<div class="mw-legend">
    <span><span class="badge badge-success">Present</span></span>
    <span><span class="badge badge-warning">Late</span></span>
    <span><span class="badge badge-danger">Absent</span></span>
    <span><span class="badge mw-leave-tag">Absent &middot; Leave approved</span></span>
</div>

<h6 class="mb-2">By Subject</h6>
<div class="table-responsive mb-4">
    <table class="table table-striped hk-std l108-stack mw-subjects">
        <colgroup><col style="width:34%"><col style="width:15%"><col style="width:19%"><col style="width:24%"><col style="width:8%"></colgroup>
        <thead><tr><th>Subject</th><th>Conducted</th><th>Attended</th><th>Attendance %</th><th></th></tr></thead>
        <tbody>
        @foreach($subjects as $row)
            <tr class="mw-subject-row">
                <td data-label="Subject">{{ optional($row['subject'])->name ?: '—' }}</td>
                <td data-label="Conducted">{{ $row['conducted'] }}</td>
                <td data-label="Attended">{{ $row['attended'] }}@if($row['late'] > 0)<small class="d-block text-muted">includes {{ $row['late'] }} late</small>@endif</td>
                <td data-label="Attendance %">
                    @if($row['percent'] === null)
                        —
                    @else
                        <span class="mw-pct"><span class="mw-pct-bar"><span class="{{ $row['percent'] >= 75 ? 'ok' : ($row['percent'] >= 50 ? 'warn' : 'bad') }}" style="width: {{ $row['percent'] }}%"></span></span><strong>{{ $row['percent'] }}%</strong></span>
                    @endif
                </td>
                <td class="hk-nw">
                    <button type="button" class="btn btn-light btn-sm mw-expand" data-target="mw-reg-{{ $mw_uid ?? 'x' }}-{{ $loop->index }}" aria-expanded="false" title="See the date-wise register"><i class="icon-chevron-down"></i></button>
                </td>
            </tr>
            <tr class="mw-register-row" id="mw-reg-{{ $mw_uid ?? 'x' }}-{{ $loop->index }}" hidden>
                <td colspan="5">
                    <table class="table table-sm hk-std l108-stack mw-register">
                        <colgroup><col style="width:30%"><col style="width:34%"><col style="width:36%"></colgroup>
                        <thead><tr><th>Date</th><th>Period</th><th>Status</th></tr></thead>
                        <tbody>
                        @foreach($row['register'] as $reg)
                            <tr>
                                <td class="hk-nw" data-label="Date">{{ $reg['date']->format('d M Y') }} <span class="text-muted">({{ $reg['date']->format('l') }})</span></td>
                                <td data-label="Period">@if($reg['period_no'])Period {{ $reg['period_no'] }} @else — @endif @if($reg['time'])<small class="d-block text-muted">{{ $reg['time'] }}</small> @endif</td>
                                <td class="hk-nw" data-label="Status">
                                    @if($reg['status'] === 'present')
                                        <span class="badge badge-success">Present</span>
                                    @elseif($reg['status'] === 'late')
                                        <span class="badge badge-warning">Late</span>
                                    @elseif($reg['leave_covered'])
                                        <span class="badge mw-leave-tag">Absent &middot; Leave approved</span>
                                    @else
                                        <span class="badge badge-danger">Absent</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
<p class="text-muted mb-0"><small>Attendance % = (present + late) / periods marked. Periods with no submitted register are not counted as absent.</small></p>
<script>
    (function () {
        document.querySelectorAll('.mw-expand').forEach(function (btn) {
            if (btn.dataset.mwBound) { return; }
            btn.dataset.mwBound = '1';
            btn.addEventListener('click', function () {
                var row = document.getElementById(btn.getAttribute('data-target'));
                if (!row) { return; }
                var open = row.hidden;
                row.hidden = !open;
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                btn.innerHTML = '<i class="icon-chevron-' + (open ? 'up' : 'down') + '"></i>';
            });
        });
    })();
</script>
