@extends('layouts.master')
@section('page_title', 'Manage Payments')
@section('content')

    {{-- Workstream CK: rebuilt from scratch on the shared patterns
         (Loop 28), the same chrome as Students / Attendance.
         Section 1 is the Collect Fee counter (Workstream BJ): the
         search card keeps BJ's exact contract — endpoint, results
         dropdown, invoice links, the script below — and BT's compact
         640px left-aligned width; only the legacy card-header shell
         is gone. Section 2 is the list_page pattern: the year picker
         is the filter row (same POST to payments.select_year), and
         the tabbed payment tables share one partial
         (payments/_table) whose row actions are now the labelled
         Actions dropdown (Exams standard) — Edit / Delete items,
         routes and the styled confirm are unchanged. --}}

    {{-- Collect Fee (Workstream BJ) --}}
    <div class="pat-list">
        <div class="pat-toolbar">
            <div class="pat-toolbar-text">
                <div class="pat-toolbar-sub"><i class="icon-cash2 mr-1"></i> <strong>Collect Fee</strong> &mdash; a student comes to pay: find them here and jump straight to their payment records.</div>
            </div>
            <div class="pat-toolbar-actions">
                <a href="{{ route('payments.create') }}" class="btn btn-primary"><i class="icon-plus3 mr-1"></i> Create Payment</a>
            </div>
        </div>

        <div class="card pat-card">
            <div class="card-body">
                <div class="bt-find">
                    <div class="form-group mb-1" style="position: relative;">
                        <label for="fee-student-search" class="col-form-label font-weight-bold">Find student</label>
                        <input type="text" id="fee-student-search" class="form-control" autocomplete="off"
                               placeholder="Type a student name, admission number or class&hellip;">
                        <div id="fee-search-results" class="list-group" style="display: none; position: absolute; left: 0; right: 0; top: 100%; z-index: 1050; max-height: 340px; overflow-y: auto; box-shadow: 0 8px 24px rgba(0,0,0,.15);"></div>
                    </div>
                    <small class="form-text text-muted">Pick a student to open their payment records and collect the fee.</small>
                </div>
            </div>
        </div>
    </div>

    {{-- Session payments (Workstream BT picker, on the pattern) --}}
    @component('partials.patterns.list_page')
        @slot('subtitle')
            @if($selected)
                <strong>{{ $year }} Session</strong> &mdash; fee payments for every class. Use the Class Payments tab to see one class at a time.
            @else
                Select a session year to view and manage its fee payments.
            @endif
        @endslot
        @slot('filters')
            <form method="post" action="{{ route('payments.select_year') }}" class="pat-filter-form">
                @csrf
                <div class="pat-filter-field">
                    <label for="year">Select Year <span class="text-danger">*</span></label>
                    <select data-placeholder="Select Year" required id="year" name="year" class="form-control select">
                        @foreach($years as $yr)
                            <option {{ ($selected && $year == $yr->year) ? 'selected' : '' }} value="{{ $yr->year }}">{{ $yr->year }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-filter-action">
                    <button type="submit" class="btn btn-primary">Submit <i class="icon-paperplane ml-2"></i></button>
                </div>
            </form>
        @endslot

        @if($selected)
            <ul class="nav nav-tabs nav-tabs-highlight">
                <li class="nav-item"><a href="#all-payments" class="nav-link active" data-toggle="tab">All Classes</a></li>
                <li class="nav-item dropdown">
                    <a href="#" class="nav-link dropdown-toggle" data-toggle="dropdown">Class Payments</a>
                    <div class="dropdown-menu dropdown-menu-right">
                        @foreach($my_classes as $mc)
                            <a href="#pc-{{ $mc->id }}" class="dropdown-item" data-toggle="tab">{{ $mc->name }}</a>
                        @endforeach
                    </div>
                </li>
            </ul>

            <div class="tab-content">
                <div class="tab-pane fade show active" id="all-payments">
                    @if($payments->count())
                        @include('pages.support_team.payments._table', ['rows' => $payments, 'total_label' => 'Total (' . $payments->count() . ' payments)'])
                    @else
                        <div class="lav-empty">
                            <p class="mb-0">No payments have been created for the {{ $year }} session yet. Use <strong>Create Payment</strong> to add the first fee.</p>
                        </div>
                    @endif
                </div>

                @foreach($my_classes as $mc)
                    <div class="tab-pane fade" id="pc-{{ $mc->id }}">
                        @php $class_payments = $payments->where('my_class_id', $mc->id); @endphp
                        @if($class_payments->count())
                            @include('pages.support_team.payments._table', ['rows' => $class_payments, 'total_label' => 'Class-specific total — ' . $mc->name])
                        @else
                            <p class="text-muted mb-0" style="padding: 18px 4px;">No class-specific payments for {{ $mc->name }} in {{ $year }}. General payments (All Classes) still apply to this class.</p>
                        @endif
                    </div>
                @endforeach
            </div>
        @else
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-cash2',
                'message' => 'Select a session year above to view its payments.',
            ])
        @endif
    @endcomponent

    {{--Payments List Ends--}}

@endsection

@section('scripts')
<script>
    (function () {
        var $input = $('#fee-student-search');
        var $box = $('#fee-search-results');
        if (!$input.length || !$box.length) { return; }

        function esc(s) {
            return $('<div>').text(s == null ? '' : s).html();
        }
        function hide() { $box.hide().empty(); }

        var timer = null;
        $input.on('input', function () {
            var q = $input.val().trim();
            clearTimeout(timer);
            if (q.length < 2) { hide(); return; }
            timer = setTimeout(function () {
                $.getJSON('{{ route('payments.student_search') }}', {q: q})
                    .done(function (data) {
                        if ($input.val().trim() !== q) { return; }
                        var rows = (data.results || []).map(function (s) {
                            var meta = [s.adm_no ? 'Adm ' + s.adm_no : '', s.class + (s.section ? ' · ' + s.section : '')]
                                .filter(Boolean).join(' — ');
                            var bal = s.has_outstanding
                                ? '<span class="text-danger font-weight-bold">' + esc(s.outstanding) + ' due</span>'
                                : '<span class="text-success">No dues</span>';
                            var avatar = s.photo
                                ? '<img class="rounded-circle mr-2" style="height:36px;width:36px;object-fit:cover" src="' + esc(s.photo) + '" alt="">'
                                : '<span class="mr-2" style="display:inline-flex;align-items:center;justify-content:center;height:36px;width:36px;border-radius:50%;background:#e8f0fe;color:#1a56db;font-weight:700;flex:none">' + esc((s.name || '?').charAt(0).toUpperCase()) + '</span>';
                            return '<a href="' + s.url + '" class="list-group-item list-group-item-action d-flex align-items-center">'
                                + avatar
                                + '<span style="flex:1 1 auto;min-width:0"><strong>' + esc(s.name) + '</strong><br><small class="text-muted">' + esc(meta) + '</small></span>'
                                + bal + '</a>';
                        }).join('');
                        $box.html(rows || '<div class="list-group-item text-muted">No students match &ldquo;' + esc(q) + '&rdquo;.</div>').show();
                    })
                    .fail(function () { hide(); });
            }, 250);
        });

        $(document).on('click', function (e) {
            if (!$box.is(e.target) && $box.has(e.target).length === 0 && !$input.is(e.target)) { hide(); }
        });
        $input.on('keydown', function (e) { if (e.key === 'Escape') { hide(); } });
    })();
</script>
@endsection
