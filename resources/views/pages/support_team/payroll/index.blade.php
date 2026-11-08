@extends('layouts.master')
@section('page_title', 'Payroll')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Finance &nbsp;/&nbsp; {!! $__env->yieldContent('page_title') !!}
@endsection
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            <span class="text-muted">{{ $months[$month] }} {{ $year }}</span>
            {!! Qs::getPanelOptions() !!}
            <div class="header-elements">
                @if(Qs::userIsTeamSAWrite())
                <form method="POST" action="{{ route('payroll.generate') }}" class="pay-generate-form">
                    @csrf
                    <input type="hidden" name="year" value="{{ $year }}">
                    <input type="hidden" name="month" value="{{ $month }}">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="icon-stack-plus mr-1"></i> Generate Missing</button>
                </form>
                @endif
            </div>
        </div>
        <div class="card-body">
            {{-- Workstream BW: period filter moved onto the shared
                 pat-filter-form bar (was a one-off form-inline row).
                 Same GET, fields and options. The per-staff generate/
                 edit forms keep the module's own pay-form grid
                 pattern (Batch 13 design) — inline row editors, not
                 legacy layout. --}}
            <form method="GET" action="{{ route('payroll.index') }}" class="pat-filter-form mb-3">
                <div class="pat-filter-field">
                    <label for="pay-month">Month</label>
                    <select id="pay-month" name="month" class="form-control">
                        @foreach($months as $num => $name)
                            <option value="{{ $num }}" {{ $num === $month ? 'selected' : '' }}>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-filter-field">
                    <label for="pay-year">Year</label>
                    <select id="pay-year" name="year" class="form-control">
                        @foreach($years as $y)
                            <option value="{{ $y }}" {{ $y === $year ? 'selected' : '' }}>{{ $y }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-filter-action">
                    <button type="submit" class="btn btn-light">View</button>
                </div>
            </form>

            <div class="pay-summary">
                <div class="pay-stat">
                    <span class="pay-stat-label">Staff Members</span>
                    <span class="pay-stat-value">{{ $summary['staff_count'] }}</span>
                    <span class="pay-stat-sub">{{ $summary['generated'] }} generated for {{ $months[$month] }}</span>
                </div>
                <div class="pay-stat">
                    <span class="pay-stat-label">Total Net Payout</span>
                    <span class="pay-stat-value">{{ \App\Helpers\Money::inr($summary['total_net']) }}</span>
                    <span class="pay-stat-sub">{{ $months[$month] }} {{ $year }}</span>
                </div>
                <div class="pay-stat pay-stat-paid">
                    <span class="pay-stat-label">Paid Out</span>
                    <span class="pay-stat-value">{{ \App\Helpers\Money::inr($summary['paid_total']) }}</span>
                    <span class="pay-stat-sub">{{ $summary['paid_count'] }} staff paid</span>
                </div>
                <div class="pay-stat pay-stat-pending">
                    <span class="pay-stat-label">Pending</span>
                    <span class="pay-stat-value">{{ \App\Helpers\Money::inr($summary['pending_total']) }}</span>
                    <span class="pay-stat-sub">awaiting disbursement</span>
                </div>
            </div>

            <p class="text-muted mb-3">
                Payroll here tracks salary records and payment status for the accounts office.
                Marking a salary <strong>Paid</strong> records that it was disbursed — no bank transfer is made from this page.
            </p>

            {{-- Round 5 P11: the payroll table rides the shared
                 data_table pattern with its settled column widths
                 (15/8/7/8/9/9/9/10/25, sum 100) and the existing
                 hk-std-payroll variant, so its rendered colgroup
                 and header classes are unchanged. The per-staff
                 edit rows stay in the body slot, forms intact. --}}
            @component('partials.patterns.data_table', [
                'columns' => [
                    ['key' => 'staff', 'label' => 'Staff Member', 'width' => 14],
                    ['key' => 'code', 'label' => 'Code', 'width' => 10, 'class' => 'hk-nw'],
                    ['key' => 'role', 'label' => 'Role', 'width' => 9],
                    ['key' => 'basic', 'label' => 'Basic', 'width' => 8, 'align' => 'right'],
                    ['key' => 'allowances', 'label' => 'Allowances', 'width' => 9, 'align' => 'right'],
                    ['key' => 'deductions', 'label' => 'Deductions', 'width' => 9, 'align' => 'right'],
                    ['key' => 'net', 'label' => 'Net Pay', 'width' => 9, 'class' => 'hk-nw', 'align' => 'right'],
                    ['key' => 'status', 'label' => 'Status', 'width' => 10, 'class' => 'hk-nw'],
                    ['key' => 'actions', 'label' => 'Actions', 'width' => 22, 'align' => 'right'],
                ],
                'variant' => 'payroll',
                'min_width' => 1020,
                'table_class' => 'table-striped pay-table',
            ])
                    @foreach($staff as $member)
                        @php $rec = $records->get($member->id); @endphp
                        <tr>
                            <td>
                                <strong>{{ $member->name }}</strong>
                                <div class="text-muted font-size-sm">{{ $member->email }}</div>
                            </td>
                            <td class="hk-nw">{{ $member->code ?: '—' }}</td>
                            <td>{{ ucwords(str_replace('_', ' ', $member->user_type)) }}</td>
                            @if($rec)
                                <td class="text-right hk-nw">{{ \App\Helpers\Money::inr($rec->basic_salary) }}</td>
                                <td class="text-right hk-nw">{{ \App\Helpers\Money::inr($rec->allowances) }}</td>
                                <td class="text-right hk-nw">{{ \App\Helpers\Money::inr($rec->deductions) }}</td>
                                <td class="text-right font-weight-bold hk-nw">{{ \App\Helpers\Money::inr($rec->net_pay) }}</td>
                                <td>
                                    @if($rec->isPaid())
                                        <span class="badge pay-badge-paid">Paid</span>
                                        <div class="text-muted font-size-sm">{{ $rec->paid_at ? $rec->paid_at->format('d M Y') : '' }}</div>
                                    @else
                                        <span class="badge pay-badge-pending">Pending</span>
                                    @endif
                                </td>
                                <td class="text-right pay-actions">
                                    <a href="{{ route('payroll.payslip', $rec->id) }}" class="btn btn-light btn-sm"><i class="icon-file-text2 mr-1"></i> Payslip</a>
                                    @if(!$rec->isPaid() && Qs::userIsTeamSAWrite())
                                        <form method="POST" action="{{ route('payroll.pay', $rec->id) }}" class="d-inline" data-confirm="Mark salary as paid for {{ $member->name }}?" data-confirm-title="Mark Salary as Paid" data-confirm-action="Mark Paid">
                                            @csrf
                                            <button type="submit" class="btn btn-success btn-sm"><i class="icon-checkmark3 mr-1"></i> Mark Paid</button>
                                        </form>
                                    @endif
                                    @if(Qs::userIsTeamSAWrite())
                                    <button type="button" class="btn btn-primary btn-sm" data-toggle="collapse" data-target="#pay-form-{{ $member->id }}">
                                        <i class="icon-pencil7 mr-1"></i> Edit
                                    </button>
                                    @endif
                                </td>
                            @else
                                <td class="text-right text-muted" colspan="4">—</td>
                                <td><span class="badge pay-badge-none">Not generated</span></td>
                                <td class="text-right pay-actions">
                                    {{-- Workstream DD: the generate/edit form below is
                                         teamSAWrite-gated, but this toggle button
                                         was not — the view-only Super Admin got a live
                                         "Generate" button opening an empty panel.
                                         Same gate as the Edit button above. --}}
                                    @if(Qs::userIsTeamSAWrite())
                                    <button type="button" class="btn btn-primary btn-sm" data-toggle="collapse" data-target="#pay-form-{{ $member->id }}">
                                        <i class="icon-plus3 mr-1"></i> Generate
                                    </button>
                                    @endif
                                </td>
                            @endif
                        </tr>
                        <tr class="pay-edit-row">
                            <td colspan="9" class="p-0">
                                <div class="collapse" id="pay-form-{{ $member->id }}">
                                    @if(Qs::userIsTeamSAWrite())
                                    <form method="POST" action="{{ $rec ? route('payroll.update', $rec->id) : route('payroll.store') }}" class="pay-form">
                                        @csrf
                                        @if($rec) @method('PUT') @endif
                                        @unless($rec)
                                            <input type="hidden" name="user_id" value="{{ $member->id }}">
                                            <input type="hidden" name="year" value="{{ $year }}">
                                            <input type="hidden" name="month" value="{{ $month }}">
                                        @endunless
                                        <div class="pay-form-grid">
                                            <div class="form-group">
                                                <label>Basic Salary (₹)</label>
                                                <input type="number" step="0.01" min="0" name="basic_salary" class="form-control pay-amt" value="{{ $rec ? $rec->basic_salary : 0 }}" required>
                                            </div>
                                            <div class="form-group">
                                                <label>Allowances (₹)</label>
                                                <input type="number" step="0.01" min="0" name="allowances" class="form-control pay-amt" value="{{ $rec ? $rec->allowances : 0 }}">
                                            </div>
                                            <div class="form-group">
                                                <label>Deductions (₹)</label>
                                                <input type="number" step="0.01" min="0" name="deductions" class="form-control pay-amt" value="{{ $rec ? $rec->deductions : 0 }}">
                                            </div>
                                            <div class="form-group">
                                                <label>Remarks</label>
                                                <input type="text" name="remarks" class="form-control" maxlength="255" value="{{ $rec ? $rec->remarks : '' }}" placeholder="Optional note">
                                            </div>
                                            <div class="form-group pay-form-net">
                                                <label>Net Pay</label>
                                                <div class="pay-net-preview">{{ \App\Helpers\Money::inr($rec ? $rec->net_pay : 0) }}</div>
                                            </div>
                                            <div class="form-group pay-form-save">
                                                <button type="submit" class="btn btn-primary">{{ $rec ? 'Save Changes' : 'Generate Payroll' }}</button>
                                            </div>
                                        </div>
                                    </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
            @endcomponent
        </div>
    </div>

    <script>
        document.querySelectorAll('.pay-form').forEach(function (form) {
            var preview = form.querySelector('.pay-net-preview');
            var update = function () {
                var get = function (name) {
                    var el = form.querySelector('[name="' + name + '"]');
                    return el ? (parseFloat(el.value) || 0) : 0;
                };
                var net = get('basic_salary') + get('allowances') - get('deductions');
                preview.textContent = '₹ ' + net.toLocaleString('en-IN', {minimumFractionDigits: 0, maximumFractionDigits: 2});
            };
            form.querySelectorAll('.pay-amt').forEach(function (el) {
                el.addEventListener('input', update);
            });
            update();
        });
    </script>
@endsection
