@extends('layouts.master')
@section('page_title', 'Result Publication')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Academics &nbsp;/&nbsp; <a href="{{ route('exams.index') }}">Exams</a> &nbsp;/&nbsp; Publication
@endsection
@section('page_subtitle', 'Publish results only when every teacher has submitted their marks.')
@section('content')

    {{-- Result publication workflow (owner order 2026-10-29): the
         Admin prepares and requests; the Principal publishes. This
         page is the one place both sides work from. --}}
    <div class="card">
        <div class="card-header">
            <h5 class="card-title"><i class="icon-eye mr-2"></i>{{ $exam->name }} — Term {{ $exam->term }}, {{ $exam->year }}</h5>
        </div>
        <div class="card-body">
            @if($exam->results_published)
                <div class="alert alert-success border-0">
                    <strong>Results published.</strong> Students and parents can see these results now.
                    @if(\App\Helpers\Qs::userIsPrincipal())
                        <form method="post" action="{{ route('exams.unpublish_results', $exam->id) }}" class="mt-2" data-confirm="Unpublish results for {{ $exam->name }}? Students and parents will no longer be able to see these results." data-confirm-title="Unpublish Results" data-confirm-action="Unpublish" data-confirm-danger>
                            @csrf
                            <button type="submit" class="btn btn-light btn-sm"><i class="icon-eye-blocked mr-1"></i> Unpublish Results</button>
                        </form>
                    @endif
                </div>
            @elseif($total_units === 0)
                <div class="alert alert-warning border-0">
                    <strong>No marks entered yet.</strong> No teacher has entered marks for this exam, so there is nothing to publish.
                </div>
            @else
                <p><strong>{{ $total_units - count($pending_units) }} of {{ $total_units }}</strong> subjects have their marks submitted.</p>

                @if(count($pending_units) > 0)
                    <h6 class="mt-3">Still waiting on these teachers</h6>
                    {{-- List tools (owner ask 2026-10-29): S/N on
                         every row, a search box + class filter for
                         long lists, and checkboxes with select-all
                         so a picked set of teachers can be reminded
                         in one go — nobody should have to click
                         Remind hundreds of times. --}}
                    <div class="d-flex flex-wrap align-items-center mb-2" style="gap:.5rem">
                        <input type="text" id="pub-search" class="form-control" style="max-width:260px" placeholder="Search class, subject or teacher…">
                        <select id="pub-class-filter" class="form-control" style="max-width:220px">
                            <option value="">All classes</option>
                            @foreach(collect($pending_units)->pluck('class_name')->unique() as $cn)
                                <option value="{{ $cn }}">{{ $cn }}</option>
                            @endforeach
                        </select>
                        <form method="post" action="{{ route('exams.notify_pending', $exam->id) }}" id="pub-remind-selected-form" class="m-0">
                            @csrf
                            <span id="pub-selected-units"></span>
                            <button type="submit" class="btn btn-warning" id="pub-remind-selected" disabled><i class="icon-bell2 mr-1"></i> Remind selected (<span id="pub-selected-count">0</span>)</button>
                        </form>
                        <form method="post" action="{{ route('exams.notify_pending', $exam->id) }}" class="m-0">
                            @csrf
                            <input type="hidden" name="all" value="1">
                            <button type="submit" class="btn btn-outline-warning"><i class="icon-bell2 mr-1"></i> Remind all pending teachers</button>
                        </form>
                    </div>
                    <div class="table-responsive">
                    <table class="table table-striped" id="pub-pending-table">
                        <thead>
                        <tr>
                            <th style="width:44px"><input type="checkbox" id="pub-select-all" title="Select all visible"></th>
                            <th style="width:52px">S/N</th>
                            <th>Class</th><th>Subject</th><th>Teacher</th><th></th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($pending_units as $u)
                            <tr data-class="{{ $u['class_name'] }}" data-search="{{ strtolower($u['class_name'] . ' ' . ($u['section_name'] ?? '') . ' ' . $u['subject_name'] . ' ' . $u['teacher_name']) }}">
                                <td>@if($u['teacher_id'])<input type="checkbox" class="pub-unit-check" value="{{ $u['section_id'] }}:{{ $u['subject_id'] }}">@endif</td>
                                <td>{{ $loop->iteration }}</td>
                                <td>{{ $u['class_name'] }}@if($u['section_name']) — {{ $u['section_name'] }}@endif</td>
                                <td>{{ $u['subject_name'] }}</td>
                                <td>{{ $u['teacher_name'] }}</td>
                                <td class="text-right">
                                    @if($u['teacher_id'])
                                        <form method="post" action="{{ route('exams.notify_pending', $exam->id) }}">
                                            @csrf
                                            <input type="hidden" name="section_id" value="{{ $u['section_id'] }}">
                                            <input type="hidden" name="subject_id" value="{{ $u['subject_id'] }}">
                                            <button type="submit" class="btn btn-warning btn-sm"><i class="icon-bell2 mr-1"></i> Remind</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                    </div>
                    <p class="text-muted" id="pub-no-match" style="display:none">No pending teachers match the search.</p>
                @else
                    <div class="alert alert-success border-0">
                        <strong>All marks are submitted.</strong> Every subject is in — the results are ready to publish.
                    </div>
                @endif

                <hr>
                @if($exam->publish_requested_at)
                    <p><strong>Publication requested</strong> by {{ optional($requester)->name ?? 'the school Admin' }} on {{ \Illuminate\Support\Carbon::parse($exam->publish_requested_at)->format('d M Y, g:i A') }} — waiting for the Principal to publish.</p>
                    @if(\App\Helpers\Qs::userIsPrincipal())
                        <form method="post" action="{{ route('exams.publish_results', $exam->id) }}">
                            @csrf
                            <button type="submit" class="btn btn-success"><i class="icon-eye mr-1"></i> Publish Results</button>
                        </form>
                    @endif
                @elseif(count($pending_units) === 0)
                    @if(\App\Helpers\Qs::userIsAdmin())
                        <form method="post" action="{{ route('exams.request_publication', $exam->id) }}">
                            @csrf
                            <button type="submit" class="btn btn-primary"><i class="icon-paperplane mr-1"></i> Send publication request to the Principal</button>
                        </form>
                        <p class="text-muted mt-2">The results go live only when the Principal publishes them.</p>
                    @elseif(\App\Helpers\Qs::userIsPrincipal())
                        <form method="post" action="{{ route('exams.publish_results', $exam->id) }}">
                            @csrf
                            <button type="submit" class="btn btn-success"><i class="icon-eye mr-1"></i> Publish Results</button>
                        </form>
                    @endif
                @else
                    <p class="text-muted">The publication request unlocks when every subject's marks are submitted.</p>
                @endif
            @endif
        </div>
    </div>

@endsection

@section('scripts')
<script>
(function () {
    var search = document.getElementById('pub-search');
    var table = document.getElementById('pub-pending-table');
    if (!search || !table) { return; }
    var classFilter = document.getElementById('pub-class-filter');
    var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr'));
    var selectAll = document.getElementById('pub-select-all');
    var noMatch = document.getElementById('pub-no-match');
    var countEl = document.getElementById('pub-selected-count');
    var remindBtn = document.getElementById('pub-remind-selected');
    var unitsHolder = document.getElementById('pub-selected-units');

    function isVisible(row) { return row.style.display !== 'none'; }
    function checkedBoxes() {
        return Array.prototype.slice.call(table.querySelectorAll('.pub-unit-check:checked'));
    }
    function syncSelected() {
        var n = checkedBoxes().length;
        countEl.textContent = n;
        remindBtn.disabled = n === 0;
    }
    function syncSelectAll() {
        var boxes = rows.filter(isVisible).map(function (r) { return r.querySelector('.pub-unit-check'); }).filter(Boolean);
        var checked = boxes.filter(function (b) { return b.checked; }).length;
        selectAll.checked = boxes.length > 0 && checked === boxes.length;
        selectAll.indeterminate = checked > 0 && checked < boxes.length;
    }
    function applyFilter() {
        var q = (search.value || '').toLowerCase().trim();
        var cls = classFilter.value || '';
        var any = false;
        rows.forEach(function (row) {
            var ok = (!q || (row.getAttribute('data-search') || '').indexOf(q) !== -1)
                && (!cls || row.getAttribute('data-class') === cls);
            row.style.display = ok ? '' : 'none';
            if (ok) { any = true; }
        });
        noMatch.style.display = any ? 'none' : '';
        syncSelectAll();
    }
    search.addEventListener('input', applyFilter);
    classFilter.addEventListener('change', applyFilter);
    selectAll.addEventListener('change', function () {
        rows.filter(isVisible).forEach(function (r) {
            var box = r.querySelector('.pub-unit-check');
            if (box) { box.checked = selectAll.checked; }
        });
        syncSelected();
    });
    table.addEventListener('change', function (e) {
        if (e.target.classList && e.target.classList.contains('pub-unit-check')) {
            syncSelected(); syncSelectAll();
        }
    });
    document.getElementById('pub-remind-selected-form').addEventListener('submit', function () {
        unitsHolder.innerHTML = '';
        checkedBoxes().forEach(function (box) {
            var input = document.createElement('input');
            input.type = 'hidden'; input.name = 'units[]'; input.value = box.value;
            unitsHolder.appendChild(input);
        });
    });
})();
</script>
@endsection
