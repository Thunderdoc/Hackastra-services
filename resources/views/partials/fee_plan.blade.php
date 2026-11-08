{{-- F5 (design §5): the Instalment Plan section, shared verbatim
     by the parent fees_show page and the student fees page.
     Renders ONLY when $plans is non-empty (a fee-head match with
     instalments resolved); otherwise nothing at all — the page
     is byte-for-byte what it was before F5.
     Expects: $plans (FeePlanPresenter arrays), $receipt_route. --}}
@if(!empty($plans) && count($plans))
    <h6 class="font-weight-bold mt-4">Instalment Plan</h6>
    @foreach($plans as $plan)
        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center flex-wrap mb-2">
                    <strong>{{ $plan['title'] }}</strong>
                    <span class="text-muted">
                        Total {{ \App\Helpers\Money::inr($plan['total']) }}
                        @if($plan['concession'] > 0)
                            &middot; Concession &minus;{{ \App\Helpers\Money::inr($plan['concession']) }}
                            &middot; Net payable {{ \App\Helpers\Money::inr($plan['net']) }}
                        @endif
                        &middot; Paid so far {{ \App\Helpers\Money::inr($plan['paid']) }}
                        &middot; Balance {{ \App\Helpers\Money::inr($plan['balance']) }}
                    </span>
                </div>
                <div class="table-responsive">
                    <table class="table hk-std hk-std-fee-plan">
                        {{-- Inside-Matter Job 2 (2026-11-08): hk-std standard — colgroup widths sum to 100%. --}}
                        <colgroup><col style="width:6%"><col style="width:22%"><col style="width:18%"><col style="width:22%"><col style="width:32%"></colgroup>
                        <thead>
                        <tr>
                            <th>#</th>
                            <th>Due date</th>
                            <th class="text-right">Amount</th>
                            <th class="text-right">Covered so far</th>
                            <th>Status</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($plan['installments'] as $inst)
                            <tr>
                                <td>{{ $inst['no'] }}</td>
                                <td class="hk-nw">{{ $inst['due_date'] ? $inst['due_date']->format('d M Y') : '—' }}</td>
                                <td class="text-right hk-nw">{{ \App\Helpers\Money::inr($inst['amount']) }}</td>
                                <td class="text-right hk-nw">{{ \App\Helpers\Money::inr($inst['covered']) }}</td>
                                <td>
                                    @if($inst['status'] === 'Paid')<span class="badge badge-success">● Paid</span>
                                    @elseif($inst['status'] === 'Partially paid')<span class="badge badge-warning">● Partially paid</span>
                                    @elseif($inst['status'] === 'Overdue')<span class="badge badge-danger">● Overdue</span>
                                    @elseif($inst['status'] === 'Due today')<span class="badge badge-warning">● Due today</span>
                                    @elseif($inst['status'] === 'No date set')<span class="badge badge-secondary">● No date set</span>
                                    @else<span class="badge badge-info">● Upcoming</span>@endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>

                @if(!empty($plan['history']))
                    <h6 class="font-weight-bold mt-3">Payment History</h6>
                    <div class="table-responsive">
                        <table class="table hk-std hk-std-fee-plan">
                            {{-- Inside-Matter Job 2 (2026-11-08): hk-std standard — colgroup widths sum to 100%. --}}
                            <colgroup><col style="width:20%"><col style="width:16%"><col style="width:20%"><col style="width:20%"><col style="width:24%"></colgroup>
                            <thead>
                            <tr>
                                <th>Paid On</th>
                                <th class="text-right">Amount</th>
                                <th class="text-right">Balance after</th>
                                <th>Method</th>
                                <th>Receipt</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($plan['history'] as $h)
                                <tr>
                                    <td class="hk-nw">{{ $h['date'] ? \Carbon\Carbon::parse($h['date'])->format('d M Y') : '—' }}</td>
                                    <td class="text-right hk-nw">{{ \App\Helpers\Money::inr($h['amount']) }}</td>
                                    <td class="text-right hk-nw">{{ \App\Helpers\Money::inr($h['balance_after']) }}</td>
                                    <td>{{ !empty($h['method']) ? ucwords(str_replace('_', ' ', $h['method'])) : '—' }}</td>
                                    <td><a target="_blank" href="{{ route($receipt_route, $plan['record']->id) }}"><i class="icon-printer"></i> Receipt</a></td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                <p class="text-muted mb-0"><small>Payments are applied to the earliest instalment first. For any other arrangement, contact the school office.@if($plan['concession'] > 0) Concessions are applied against the final instalment(s).@endif</small></p>
            </div>
        </div>
    @endforeach
@endif
