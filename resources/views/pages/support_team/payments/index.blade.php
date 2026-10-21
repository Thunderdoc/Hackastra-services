@extends('layouts.master')
@section('page_title', 'Manage Payments')
@section('content')

    {{-- Collect Fee (Workstream BJ): the counter workflow — a student
         comes to pay, so find them by name, admission number or class
         and jump straight to their payment records to take the fee.
         Workstream BT: the card is compact now — the search sits at a
         sensible width, left-aligned, helper attached (it used to float
         centred in a near-empty card). The search contract (endpoint,
         dropdown, invoice links) is BJ's and unchanged. --}}
    <div class="card">
        <div class="card-header header-elements-inline">
            <h5 class="card-title"><i class="icon-cash2 mr-2"></i> Collect Fee</h5>
        </div>

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

    {{-- Select Year (Workstream BT): a compact inline picker row —
         label, select and Submit together — replacing the legacy
         centred column that floated in a near-empty card. --}}
    <div class="card">
        <div class="card-header header-elements-inline">
            <h5 class="card-title"><i class="icon-cash2 mr-2"></i> Select year</h5>
        </div>

        <div class="card-body">
            <form method="post" action="{{ route('payments.select_year') }}">
                @csrf
                <div class="bt-picker">
                    <div class="bt-picker-field">
                        <label for="year" class="col-form-label font-weight-bold">Select Year <span class="text-danger">*</span></label>
                        <select data-placeholder="Select Year" required id="year" name="year" class="form-control select">
                            @foreach($years as $yr)
                                <option {{ ($selected && $year == $yr->year) ? 'selected' : '' }} value="{{ $yr->year }}">{{ $yr->year }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="bt-picker-btn">
                        <button type="submit" class="btn btn-primary">Submit <i class="icon-paperplane ml-2"></i></button>
                    </div>
                </div>
            </form>
        </div>
    </div>

@if($selected)
    <div class="card">
        <div class="card-header header-elements-inline">
            <span class="text-muted">{{ $year }} Session</span>
            {!! Qs::getPanelOptions() !!}
        </div>

        <div class="card-body">
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
                        <div class="table-responsive">
                        <table class="table">
                            <thead>
                            <tr>
                                <th>#</th>
                                <th>Title</th>
                                <th class="text-right">Amount</th>
                                <th>Ref_No</th>
                                <th>Class</th>
                                <th>Method</th>
                                <th>Info</th>
                                <th>Action</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($payments as $p)
                                <tr>
                                    <td>{{ $loop->iteration }}</td>
                                    <td>{{ $p->title }}</td>
                                    <td class="text-right font-weight-bold">{{ \App\Helpers\Money::inr($p->amount) }}</td>
                                    <td>{{ $p->ref_no }}</td>
                                    <td>{{ $p->my_class_id ? $p->my_class->name : '' }}</td>
                                    <td>{{ ucwords($p->method) }}</td>
                                    <td>{{ $p->description }}</td>
                                    <td class="text-center">
                                        <div class="list-icons">
                                            <div class="dropdown">
                                                <a href="#" class="list-icons-item" data-toggle="dropdown">
                                                    <i class="icon-menu9"></i>
                                                </a>

                                                <div class="dropdown-menu dropdown-menu-left">
                                                    {{--Edit--}}
                                                <a href="{{ route('payments.edit', $p->id) }}" class="dropdown-item"><i class="icon-pencil"></i> Edit</a>
                                                    {{--Delete--}}
                                                    <a id="{{ $p->id }}" onclick="confirmDelete(this.id)" href="#" class="dropdown-item"><i class="icon-trash"></i> Delete</a>
                                                    <form method="post" id="item-delete-{{ $p->id }}" action="{{ route('payments.destroy', $p->id) }}" class="hidden">@csrf @method('delete')</form>

                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                            <tfoot>
                            <tr>
                                <th colspan="2" class="text-right">Total ({{ $payments->count() }} payments)</th>
                                <th class="text-right">{{ \App\Helpers\Money::inr($payments->sum('amount')) }}</th>
                                <th colspan="5"></th>
                            </tr>
                            </tfoot>
                        </table>
                        </div>
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
                        <div class="table-responsive">
                        <table class="table">
                            <thead>
                            <tr>
                                <th>#</th>
                                <th>Title</th>
                                <th class="text-right">Amount</th>
                                <th>Ref_No</th>
                                <th>Class</th>
                                <th>Method</th>
                                <th>Info</th>
                                <th>Action</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($class_payments as $p)
                                <tr>
                                    <td>{{ $loop->iteration }}</td>
                                    <td>{{ $p->title }}</td>
                                    <td class="text-right font-weight-bold">{{ \App\Helpers\Money::inr($p->amount) }}</td>
                                    <td>{{ $p->ref_no }}</td>
                                    <td>{{ $p->my_class_id ? $p->my_class->name : '' }}</td>
                                    <td>{{ ucwords($p->method) }}</td>
                                    <td>{{ $p->description }}</td>
                                    <td class="text-center">
                                        <div class="list-icons">
                                            <div class="dropdown">
                                                <a href="#" class="list-icons-item" data-toggle="dropdown">
                                                    <i class="icon-menu9"></i>
                                                </a>

                                                <div class="dropdown-menu dropdown-menu-left">
                                                    {{--Edit--}}
                                                    <a href="{{ route('payments.edit', $p->id) }}" class="dropdown-item"><i class="icon-pencil"></i> Edit</a>
                                                    {{--Delete--}}
                                                    <a id="{{ $p->id }}" onclick="confirmDelete(this.id)" href="#" class="dropdown-item"><i class="icon-trash"></i> Delete</a>
                                                    <form method="post" id="item-delete-{{ $p->id }}" action="{{ route('payments.destroy', $p->id) }}" class="hidden">@csrf @method('delete')</form>

                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                            <tfoot>
                            <tr>
                                <th colspan="2" class="text-right">Class-specific total — {{ $mc->name }}</th>
                                <th class="text-right">{{ \App\Helpers\Money::inr($class_payments->sum('amount')) }}</th>
                                <th colspan="5"></th>
                            </tr>
                            </tfoot>
                        </table>
                        </div>
                        @else
                            <p class="text-muted mb-0" style="padding: 18px 4px;">No class-specific payments for {{ $mc->name }} in {{ $year }}. General payments (All Classes) still apply to this class.</p>
                        @endif

                    </div>
                    @endforeach
            </div>
        </div>
    </div>
    @endif

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
