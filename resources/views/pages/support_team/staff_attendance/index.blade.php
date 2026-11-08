@extends('layouts.master')
@section('page_title', 'Staff Attendance')
@section('content')
    {{-- Staff Attendance register (redesign, owner 2026-11-07 late —
         "yha ka ve uiux bekar hai"): was a loose list of tall rows
         with one giant full-width select per person and the save
         button buried under 100+ rows. Now the house standard: an
         hk-std table (initials tile + name | role | compact
         segmented status control), a live summary strip, search +
         role filters, Mark all Present, and a sticky bottom action
         bar. The backend contract is byte-identical: same GET
         date + Show Register flow, same POST to
         staff_attendance.store with status[{user_id}] in
         present/late/absent, a marked day stays locked and renders
         its saved states as badges with counts from the marks. --}}
    <style>
        .sa-avatar { flex: 0 0 32px; width: 32px; height: 32px; border-radius: 50%; background: #e9f1ff; color: #2867dc; font-weight: 700; font-size: 12px; display: inline-flex; align-items: center; justify-content: center; letter-spacing: .02em; }
        html.hk-dark .sa-avatar { background: #35373b; color: #7fb0ff; }
        .sa-staff { display: flex; align-items: center; gap: 9px; min-width: 0; }
        .sa-name { font-weight: 600; }
        .sa-table { min-width: 600px; }
        .sa-table td { padding-top: 8px; padding-bottom: 8px; vertical-align: middle; }
        .sa-summary { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 14px; }
        .sa-stat { display: flex; align-items: center; gap: 10px; border: 1px solid #e4eaf1; border-radius: 10px; padding: 9px 16px 9px 12px; background: #fff; }
        html.hk-dark .sa-stat { background: #212225; border-color: #36383c; }
        .sa-stat-dot { width: 9px; height: 9px; border-radius: 50%; background: #8ea3b8; flex: 0 0 9px; }
        .sa-stat-dot.is-present { background: #2f9e44; }
        .sa-stat-dot.is-late { background: #e8a13a; }
        .sa-stat-dot.is-absent { background: #d64545; }
        .sa-stat-n { font-size: 17px; font-weight: 700; line-height: 1.1; }
        .sa-stat-l { font-size: 12px; color: #5b7a99; }
        html.hk-dark .sa-stat-l { color: #93a7bd; }
        .sa-toolbar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 12px; }
        .sa-toolbar .sa-search { flex: 0 1 240px; }
        .sa-toolbar .sa-role { flex: 0 1 170px; }
        .sa-shown { font-size: 12.5px; color: #5b7a99; margin-left: auto; }
        html.hk-dark .sa-shown { color: #93a7bd; }
        .sa-seg { display: inline-flex; border: 1px solid #d3dde8; border-radius: 999px; overflow: hidden; background: #fff; }
        html.hk-dark .sa-seg { border-color: #414348; background: #26272a; }
        .sa-seg-opt { margin: 0; cursor: pointer; position: relative; }
        .sa-seg-opt input { position: absolute; opacity: 0; pointer-events: none; }
        .sa-seg-opt span { display: inline-block; padding: 5px 14px; font-size: 12.5px; font-weight: 600; color: #5b7a99; }
        html.hk-dark .sa-seg-opt span { color: #a5a8ad; }
        .sa-seg-opt input:checked + span { color: #fff; }
        .sa-seg-present input:checked + span { background: #2f9e44; }
        .sa-seg-late input:checked + span { background: #d97706; }
        .sa-seg-absent input:checked + span { background: #d64545; }
        .sa-seg-opt input:focus-visible + span { box-shadow: inset 0 0 0 2px #3478f6; }
        .sa-actionbar { margin: 16px -22px -20px; }
        .sa-bar-note { margin-right: auto; font-size: 13px; color: #5b7a99; }
        html.hk-dark .sa-bar-note { color: #93a7bd; }
        @media (max-width: 767px) {
            .sa-actionbar { margin: 14px -16px -16px; }
            .sa-shown { margin-left: 0; width: 100%; }
        }
    </style>
    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">Staff Attendance — {{ \Carbon\Carbon::parse($date)->format('d M Y') }}</h6>
            {!! Qs::getPanelOptions() !!}
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('staff_attendance.index') }}" class="form-inline mb-3">
                <label class="mr-2" for="date">Date</label>
                <input type="date" name="date" id="date" value="{{ $date }}" class="form-control mr-2" required>
                <button type="submit" class="btn btn-light btn-sm">Show Register</button>
            </form>

            @if($locked)
                <div class="alert alert-info">This date is already marked and cannot be changed — the same lock rule as the student register.</div>
            @endif

            @php
                $saCounts = ['present' => 0, 'late' => 0, 'absent' => 0];
                if ($locked) {
                    foreach ($marks as $m) {
                        if (isset($saCounts[$m->status])) { $saCounts[$m->status]++; }
                    }
                } else {
                    $saCounts['present'] = $staff->count();
                }
            @endphp
            <div class="sa-summary" id="sa-summary">
                <div class="sa-stat"><span class="sa-stat-dot"></span><span><span class="sa-stat-n" id="sa-total">{{ $staff->count() }}</span><br><span class="sa-stat-l">Total staff</span></span></div>
                <div class="sa-stat"><span class="sa-stat-dot is-present"></span><span><span class="sa-stat-n" id="sa-present">{{ $saCounts['present'] }}</span><br><span class="sa-stat-l">Present</span></span></div>
                <div class="sa-stat"><span class="sa-stat-dot is-late"></span><span><span class="sa-stat-n" id="sa-late">{{ $saCounts['late'] }}</span><br><span class="sa-stat-l">Late</span></span></div>
                <div class="sa-stat"><span class="sa-stat-dot is-absent"></span><span><span class="sa-stat-n" id="sa-absent">{{ $saCounts['absent'] }}</span><br><span class="sa-stat-l">Absent</span></span></div>
            </div>

            @if($staff->isNotEmpty())
            <div class="sa-toolbar">
                <input type="text" id="sa-search" class="form-control form-control-sm sa-search" placeholder="Search by name" aria-label="Search staff by name">
                <select id="sa-role" class="form-control form-control-sm sa-role" aria-label="Filter by role">
                    <option value="">All roles</option>
                    @foreach($staff->pluck('user_type')->unique()->values() as $rt)
                        <option value="{{ $rt }}">{{ ucfirst($rt) }}</option>
                    @endforeach
                </select>
                @if(!$locked)
                    <button type="button" id="sa-mark-all" class="btn btn-sm btn-light">Mark all Present</button>
                @endif
                <span class="sa-shown" id="sa-shown">Showing {{ $staff->count() }} of {{ $staff->count() }}</span>
            </div>
            @endif

            <form method="POST" action="{{ route('staff_attendance.store') }}" id="sa-form">
                @csrf
                <input type="hidden" name="date" value="{{ $date }}">
                <div class="table-responsive">
                    <table class="table hk-std sa-table">
                        <colgroup><col style="width:44%"><col style="width:20%"><col style="width:36%"></colgroup>
                        <thead><tr><th>Staff</th><th>Role</th><th>Status</th></tr></thead>
                        <tbody id="sa-tbody">
                        @forelse($staff as $s)
                            @php
                                $saParts = preg_split('/\s+/', trim((string) $s->name)) ?: [];
                                $saInitials = strtoupper(mb_substr($saParts[0] ?? '', 0, 1) . mb_substr(count($saParts) > 1 ? (string) end($saParts) : '', 0, 1));
                            @endphp
                            <tr data-name="{{ strtolower($s->name) }}" data-role="{{ $s->user_type }}" @if($locked) data-status="{{ $marks[$s->id]->status ?? '' }}" @endif>
                                <td><span class="sa-staff"><span class="sa-avatar">{{ $saInitials !== '' ? $saInitials : '?' }}</span><span class="sa-name">{{ $s->name }}</span></span></td>
                                <td class="hk-nw">{{ ucfirst($s->user_type) }}</td>
                                <td>
                                    @if($locked)
                                        <span class="badge badge-{{ ($marks[$s->id]->status ?? '') === 'present' ? 'success' : ((($marks[$s->id]->status ?? '') === 'late') ? 'warning' : 'danger') }}">{{ ucfirst($marks[$s->id]->status ?? '—') }}</span>
                                    @else
                                        <span class="sa-seg" role="radiogroup" aria-label="Status for {{ $s->name }}">
                                            @foreach(['present' => 'Present', 'late' => 'Late', 'absent' => 'Absent'] as $saVal => $saLbl)
                                                <label class="sa-seg-opt sa-seg-{{ $saVal }}"><input type="radio" name="status[{{ $s->id }}]" value="{{ $saVal }}" class="sa-status-input" @if($saVal === 'present') checked @endif><span>{{ $saLbl }}</span></label>
                                            @endforeach
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3">@include('partials.patterns.empty_state', ['icon' => 'icon-users', 'message' => 'No staff found for this date.'])</td></tr>
                        @endforelse
                            <tr id="sa-nomatch" style="display:none"><td colspan="3" class="text-muted">No staff match the current search or role filter.</td></tr>
                        </tbody>
                    </table>
                </div>
                @if(!$locked && $staff->isNotEmpty())
                    <div class="pat-actionbar sa-actionbar">
                        <span class="sa-bar-note" id="sa-bar-note"></span>
                        <button type="submit" class="btn btn-success">Save Attendance</button>
                    </div>
                @endif
            </form>
        </div>
    </div>
@endsection
@section('scripts')
<script>
    /* Staff register tools (redesign 2026-11-07): the summary strip
       and the action-bar note always recount from the register's
       CURRENT selections (radio checks, or the saved badges' data
       on a locked day); search + role filter only hide rows; Mark
       all Present checks Present on the rows currently shown. */
    (function () {
        var tbody = document.getElementById('sa-tbody');
        if (!tbody) { return; }
        var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr[data-name]'));
        var q = document.getElementById('sa-search');
        var role = document.getElementById('sa-role');
        var shown = document.getElementById('sa-shown');
        var nomatch = document.getElementById('sa-nomatch');
        var markAll = document.getElementById('sa-mark-all');

        function rowStatus(row) {
            var input = row.querySelector('.sa-status-input:checked');
            if (input) { return input.value; }
            return row.getAttribute('data-status') || '';
        }
        function recount() {
            var c = { present: 0, late: 0, absent: 0 };
            rows.forEach(function (row) {
                var s = rowStatus(row);
                if (Object.prototype.hasOwnProperty.call(c, s)) { c[s]++; }
            });
            var set = function (id, v) { var el = document.getElementById(id); if (el) { el.textContent = v; } };
            set('sa-total', rows.length);
            set('sa-present', c.present);
            set('sa-late', c.late);
            set('sa-absent', c.absent);
            var note = document.getElementById('sa-bar-note');
            if (note) {
                note.textContent = rows.length + ' staff · ' + c.present + ' present · ' + c.late + ' late · ' + c.absent + ' absent';
            }
        }
        function applyFilters() {
            var needle = q ? q.value.trim().toLowerCase() : '';
            var rv = role ? role.value : '';
            var visible = 0;
            rows.forEach(function (row) {
                var ok = (needle === '' || row.getAttribute('data-name').indexOf(needle) !== -1)
                    && (rv === '' || row.getAttribute('data-role') === rv);
                row.style.display = ok ? '' : 'none';
                if (ok) { visible++; }
            });
            if (nomatch) { nomatch.style.display = visible === 0 && rows.length > 0 ? '' : 'none'; }
            if (shown) { shown.textContent = 'Showing ' + visible + ' of ' + rows.length; }
        }
        if (q) { q.addEventListener('input', applyFilters); }
        if (role) { role.addEventListener('change', applyFilters); }
        tbody.addEventListener('change', function (e) {
            if (e.target && e.target.classList && e.target.classList.contains('sa-status-input')) { recount(); }
        });
        if (markAll) {
            markAll.addEventListener('click', function () {
                rows.forEach(function (row) {
                    if (row.style.display === 'none') { return; }
                    var p = row.querySelector('.sa-status-input[value="present"]');
                    if (p) { p.checked = true; }
                });
                recount();
            });
        }
        recount();
        applyFilters();
    })();
</script>
@endsection
