@extends('layouts.master')
@section('page_title', 'Payroll')
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">Payroll — {{ $months[$month] }} {{ $year }}</h6>
            {!! Qs::getPanelOptions() !!}
            <div class="header-elements">
                <form method="POST" action="{{ route('payroll.generate') }}" class="pay-generate-form">
                    @csrf
                    <input type="hidden" name="year" value="{{ $year }}">
                    <input type="hidden" name="month" value="{{ $month }}">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="icon-stack-plus mr-1"></i> Generate Missing</button>
                </form>
            </div>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('payroll.index') }}" class="form-inline mb-3">
                <label class="mr-2">Month</label>
                <select name="month" class="form-control mr-3">
                    @foreach($months as $num => $name)
                        <option value="{{ $num }}" {{ $num === $month ? 'selected' : '' }}>{{ $name }}</option>
                    @endforeach
                </select>
                <label class="mr-2">Year</label>
                <select name="year" class="form-control mr-3">
                    @foreach($years as $y)
                        <option value="{{ $y }}" {{ $y === $year ? 'selected' : '' }}>{{ $y }}</option>
                    @endforeach
                </select>
                <button type="submit" class="btn btn-light btn-sm">View</button>
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

            <div class="table-responsive">
                <table class="table table-striped pay-table">
                    <thead>
                    <tr>
                        <th>Staff Member</th>
                        <th>Code</th>
                        <th>Role</th>
                        <th class="text-right">Basic</th>
                        <th class="text-right">Allowances</th>
                        <th class="text-right">Deductions</th>
                        <th class="text-right">Net Pay</th>
                        <th>Status</th>
                        <th class="text-right">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($staff as $member)
                        @php $rec = $records->get($member->id); @endphp
                        <tr>
                            <td>
                                <strong>{{ $member->name }}</strong>
                                <div class="text-muted font-size-sm">{{ $member->email }}</div>
                            </td>
                            <td>{{ $member->code ?: '—' }}</td>
                            <td>{{ ucwords(str_replace('_', ' ', $member->user_type)) }}</td>
                            @if($rec)
                                <td class="text-right">{{ \App\Helpers\Money::inr($rec->basic_salary) }}</td>
                                <td class="text-right">{{ \App\Helpers\Money::inr($rec->allowances) }}</td>
                                <td class="text-right">{{ \App\Helpers\Money::inr($rec->deductions) }}</td>
                                <td class="text-right font-weight-bold">{{ \App\Helpers\Money::inr($rec->net_pay) }}</td>
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
                                    @if(!$rec->isPaid())
                                        <form method="POST" action="{{ route('payroll.pay', $rec->id) }}" class="d-inline" onsubmit="return confirm('Mark salary as paid for {{ $member->name }}?');">
                                            @csrf
                                            <button type="submit" class="btn btn-success btn-sm"><i class="icon-checkmark3 mr-1"></i> Mark Paid</button>
                                        </form>
                                    @endif
                                    <button type="button" class="btn btn-primary btn-sm" data-toggle="collapse" data-target="#pay-form-{{ $member->id }}">
                                        <i class="icon-pencil7 mr-1"></i> Edit
                                    </button>
                                </td>
                            @else
                                <td class="text-right text-muted" colspan="4">—</td>
                                <td><span class="badge pay-badge-none">Not generated</span></td>
                                <td class="text-right pay-actions">
                                    <button type="button" class="btn btn-primary btn-sm" data-toggle="collapse" data-target="#pay-form-{{ $member->id }}">
                                        <i class="icon-plus3 mr-1"></i> Generate
                                    </button>
                                </td>
                            @endif
                        </tr>
                        <tr class="pay-edit-row">
                            <td colspan="9" class="p-0">
                                <div class="collapse" id="pay-form-{{ $member->id }}">
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
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
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
