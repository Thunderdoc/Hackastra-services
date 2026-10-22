@extends('layouts.master')
@section('page_title', 'Collect Fee / Create Payment')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Payments &nbsp;/&nbsp; Create Payment
@endsection
@section('page_actions')
    <a href="{{ route('payments.manage') }}" class="btn btn-outline-primary"><i class="icon-list mr-2"></i>View Recent Payments</a>
@endsection
@section('content')

    {{-- Loop 93: the invoice page rebuilt as the premium collect-fee
         workspace (owner reference): selected-student panel with real
         totals, the fee-dues card, and a Payment Summary rail whose
         Record Payment button submits the ACTIVE row's real form.
         The money contract is byte-identical to the legacy page:
         tab ids, row ids (amt-/amt_paid-/bal-/val-), the .ajax-pay
         forms posting to payments.pay_now, the reset/receipt actions
         and the BJ success panel below — the pay-now JS and the
         print stylesheet depend on them. Figures: totals follow the
         export rule (fee − amount paid, correct in every state). --}}
    @php
        $l93All = $uncleared->concat($cleared);
        $l93TotalFees = $l93All->sum(function($r){ return $r->payment->amount; });
        $l93TotalPaid = $l93All->sum(function($r){ return $r->amt_paid ?: 0; });
        $l93TotalDue  = $l93TotalFees - $l93TotalPaid;
        $l93Pct = $l93TotalFees > 0 ? (int) round($l93TotalPaid / $l93TotalFees * 100) : 0;
        $l93Words = preg_split('/\s+/', trim((string) $sr->user->name));
        $l93Initials = strtoupper(substr($l93Words[0] ?? '?', 0, 1) . (isset($l93Words[1]) ? substr($l93Words[1], 0, 1) : ''));
    @endphp

    <div class="l93">

        {{-- Visual guide only: step 1 happened on the search page;
             steps 2–4 all live on this page. --}}
        <ol class="l93-steps" aria-label="Payment progress">
            <li class="l93-step is-done"><span class="l93-step-dot"><i class="icon-checkmark3"></i></span><span class="l93-step-text"><strong>Find Student</strong><small>Search &amp; select</small></span></li>
            <li class="l93-step is-active"><span class="l93-step-dot">2</span><span class="l93-step-text"><strong>Fee Details</strong><small>View dues &amp; select</small></span></li>
            <li class="l93-step"><span class="l93-step-dot">3</span><span class="l93-step-text"><strong>Payment Info</strong><small>Enter payment details</small></span></li>
            <li class="l93-step"><span class="l93-step-dot">4</span><span class="l93-step-text"><strong>Confirm &amp; Save</strong><small>Review &amp; record</small></span></li>
        </ol>

        {{-- Workstream BJ: after a successful Pay Now, the receipt for
             THAT payment is one click away. Populated by the script at
             the bottom of this page from the pay_now JSON response
             (receipt_url / pdf_url); hidden until a payment lands. --}}
        <div id="pay-success" class="alert alert-success border-0 alert-dismissible" style="display:none" role="alert">
            <button type="button" class="close" id="pay-success-close"><span>&times;</span></button>
            <h6 class="font-weight-bold mb-1"><i class="icon-checkmark-circle2 mr-1"></i> Payment recorded</h6>
            <p class="mb-2" id="pay-success-text"></p>
            <a href="#" target="_blank" id="pay-success-print" class="btn btn-success btn-sm"><i class="icon-printer mr-1"></i> Print Receipt</a>
            <a href="#" id="pay-success-pdf" class="btn btn-sm ml-1" style="border:1px solid #2f9e6e;color:#20714e;background:#fff"><i class="icon-download mr-1"></i> Download PDF</a>
        </div>

        {{-- Selected student --}}
        <div class="card pat-card">
            <div class="card-body">
                <div class="l93-student">
                    <span class="l93-avatar">
                        @if(Qs::hasRealPhoto($sr->user->photo))
                            <img src="{{ $sr->user->photo }}" alt="">
                        @else
                            {{ $l93Initials }}
                        @endif
                    </span>
                    <div class="l93-student-main">
                        <h5>{{ $sr->user->name }} @if($sr->grad)<span class="l93-status-pill is-grad">Graduated</span>@else<span class="l93-status-pill">Active</span>@endif</h5>
                        <p>Adm {{ $sr->adm_no }} &middot; {{ $sr->my_class->name }}@if($sr->section) &mdash; Section {{ $sr->section->name }}@endif &middot; {{ $year ? 'Session '.$year : 'All sessions' }}</p>
                        <p>Fee records, balances and receipts for this student.</p>
                    </div>
                    <div>
                        <a href="{{ route('payments.export_student', $year ? [Qs::hash($sr->user_id), $year] : [Qs::hash($sr->user_id)]) }}" class="btn btn-primary"><i class="icon-download mr-1"></i> Export</a>
                    </div>
                </div>

                <div class="l93-stats">
                    <div class="l93-stat"><span>Total Fees</span><strong>{{ \App\Helpers\Money::inr($l93TotalFees) }}</strong></div>
                    <div class="l93-stat is-paid"><span>Paid Amount</span><strong class="js-l93-paid">{{ \App\Helpers\Money::inr($l93TotalPaid) }}</strong></div>
                    <div class="l93-stat is-due"><span>Total Due</span><strong class="js-l93-due">{{ \App\Helpers\Money::inr($l93TotalDue) }}</strong></div>
                    <div class="l93-stat"><span>Payment Progress</span><strong><span class="js-l93-pct">{{ $l93Pct }}%</span></strong><div class="l93-progress"><div class="l93-progress-bar js-l93-bar" style="width: {{ $l93Pct }}%"></div></div></div>
                </div>
            </div>
        </div>

        <div class="l93-layout">
            <div>
                {{-- Fee dues + payment details --}}
                <div class="card pat-card">
                    <div class="card-body">
                        <div class="l93-card-head">
                            <span class="l93-search-icon"><i class="icon-file-text2"></i></span>
                            <div>
                                <h5>Fee Dues</h5>
                                <p>Enter the amount received against a fee and record the payment. Settled fees move to Completed.</p>
                            </div>
                        </div>

                        <ul class="nav nav-tabs nav-tabs-highlight">
                            <li class="nav-item"><a href="#all-uc" class="nav-link active" data-toggle="tab">Incomplete Payments</a></li>
                            <li class="nav-item"><a href="#all-cl" class="nav-link" data-toggle="tab">Completed Payments</a></li>
                        </ul>

                        <div class="tab-content">
                            <div class="tab-pane fade show active" id="all-uc">
                                @if($uncleared->count())
                                <div class="table-responsive">
                                <table class="table l93-table">
                                    <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Fee</th>
                                        <th>Receipt_No</th>
                                        <th class="text-right">Amount</th>
                                        <th class="text-right">Paid</th>
                                        <th class="text-right">Balance</th>
                                        <th>Status</th>
                                        <th>Pay Now</th>
                                        <th>Action</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @foreach($uncleared as $uc)
                                        <tr>
                                            <td>{{ $loop->iteration }}</td>
                                            <td>
                                                <span class="l93-fee-title">{{ $uc->payment->title }}</span>
                                                <div class="l93-fee-sub">Ref {{ $uc->payment->ref_no }} &middot; {{ $uc->year }}</div>
                                            </td>
                                            <td>{{ $uc->ref_no }}</td>

                                            {{--Amount (data-amount stays raw: the pay-now JS reads it)--}}
                                            <td class="font-weight-bold text-right l93-fee-amt" id="amt-{{ Qs::hash($uc->id) }}" data-amount="{{ $uc->payment->amount }}">{{ \App\Helpers\Money::inr($uc->payment->amount) }}</td>

                                            {{--Amount Paid--}}
                                            <td id="amt_paid-{{ Qs::hash($uc->id) }}" data-amount="{{ $uc->amt_paid ?: 0 }}" class="text-blue font-weight-bold text-right l93-paid-amt">{{ \App\Helpers\Money::inr($uc->amt_paid ?: 0) }}</td>

                                            {{--Balance--}}
                                            <td id="bal-{{ Qs::hash($uc->id) }}" class="text-danger font-weight-bold text-right">{{ \App\Helpers\Money::inr($uc->balance ?: $uc->payment->amount) }}</td>

                                            {{--Status--}}
                                            <td>
                                                @if(($uc->amt_paid ?: 0) > 0)
                                                    <span class="badge badge-warning">Partially Paid</span>
                                                @else
                                                    <span class="badge badge-danger">Not Paid</span>
                                                @endif
                                            </td>

                                            {{--Pay Now Form--}}
                                            <td>
                                                @if(Qs::userIsTeamAccountWrite())
                                                <form id="{{ Qs::hash($uc->id) }}" method="post" class="ajax-pay" action="{{ route('payments.pay_now', Qs::hash($uc->id)) }}">
                                                    @csrf
                                             <div class="row">
                                                 <div class="col-md-7">
                                                     <input min="1" max="{{ $uc->balance ?: $uc->payment->amount }}" id="val-{{ Qs::hash($uc->id) }}" class="form-control l93-pay-input" required placeholder="Amount" title="Pay Now" name="amt_paid" type="number" data-title="{{ $uc->payment->title }}">
                                                 </div>
                                                 <div class="col-md-5">
                                                     <button data-text="Record Payment" class="btn btn-danger" type="submit">Record Payment <i class="icon-paperplane ml-2"></i></button>
                                                 </div>
                                             </div>
                                                </form>
                                                @endif
                                            </td>

                                            {{--Action--}}
                                            <td class="text-center">
                                                <div class="dropdown">
                                                    <button type="button" class="btn btn-sm btn-primary dropdown-toggle" data-toggle="dropdown">Actions</button>

                                                    <div class="dropdown-menu dropdown-menu-right">

                                                            {{--Reset Payment--}}
                                                            @if(Qs::userIsTeamAccountWrite())
                                                            <a id="{{ Qs::hash($uc->id) }}" onclick="confirmReset(this.id)" href="#" class="dropdown-item"><i class="icon-reset"></i> Reset Payment</a>
                                                            <form method="post" id="item-reset-{{ Qs::hash($uc->id) }}" action="{{ route('payments.reset_record', Qs::hash($uc->id)) }}" class="hidden">@csrf @method('delete')</form>
                                                            @endif

                                                            {{--Receipt--}}
                                                                <a target="_blank" href="{{ route('payments.receipts', Qs::hash($uc->id)) }}" class="dropdown-item"><i class="icon-printer"></i> Print Receipt</a>
                                                            {{--PDF Receipt (plain link on purpose: the .download-receipt JS handler swallows the file with an AJAX GET, so it must not be used here)--}}
                                                                <a href="{{ route('payments.pdf_receipts', Qs::hash($uc->id)) }}" class="dropdown-item"><i class="icon-download"></i> Download Receipt (PDF)</a>

                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                    <tfoot>
                                    <tr>
                                        <th colspan="3" class="text-right">Totals</th>
                                        <th class="text-right">{{ \App\Helpers\Money::inr($uncleared->sum(function($r){ return $r->payment->amount; })) }}</th>
                                        <th class="text-right">{{ \App\Helpers\Money::inr($uncleared->sum('amt_paid')) }}</th>
                                        <th class="text-right">{{ \App\Helpers\Money::inr($uncleared->sum(function($r){ return $r->balance ?: $r->payment->amount; })) }}</th>
                                        <th colspan="3"></th>
                                    </tr>
                                    </tfoot>
                                </table>
                                </div>
                                @else
                                    <div class="lav-empty">
                                        <p class="mb-0">No incomplete payments. Every fee for {{ $sr->user->name }} is fully paid.</p>
                                    </div>
                                @endif
                            </div>

                            <div class="tab-pane fade" id="all-cl">
                                @if($cleared->count())
                                <div class="table-responsive">
                                <table class="table l93-table">
                                    <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Fee</th>
                                        <th>Receipt_No</th>
                                        <th class="text-right">Amount</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @foreach($cleared as $cl)
                                        <tr>
                                            <td>{{ $loop->iteration }}</td>
                                            <td>
                                                <span class="l93-fee-title">{{ $cl->payment->title }}</span>
                                                <div class="l93-fee-sub">Ref {{ $cl->payment->ref_no }} &middot; {{ $cl->year }}</div>
                                            </td>

                                            {{--Receipt No--}}
                                            <td>{{ $cl->ref_no }}</td>

                                            {{--Amount (fully paid: counts as both fee and paid in the Loop 93 totals)--}}
                                            <td class="font-weight-bold text-right l93-fee-amt l93-paid-amt" data-amount="{{ $cl->amt_paid ?: $cl->payment->amount }}">{{ \App\Helpers\Money::inr($cl->payment->amount) }}</td>

                                            {{--Status--}}
                                            <td><span class="badge badge-success">Paid</span></td>

                                            {{--Action--}}
                                            <td class="text-center">
                                                <div class="dropdown">
                                                    <button type="button" class="btn btn-sm btn-primary dropdown-toggle" data-toggle="dropdown">Actions</button>

                                                    <div class="dropdown-menu dropdown-menu-right">

                                                            {{--Reset Payment--}}
                                                            @if(Qs::userIsTeamAccountWrite())
                                                            <a id="{{ Qs::hash($cl->id) }}" onclick="confirmReset(this.id)" href="#" class="dropdown-item"><i class="icon-reset"></i> Reset Payment</a>
                                                            <form method="post" id="item-reset-{{ Qs::hash($cl->id) }}" action="{{ route('payments.reset_record', Qs::hash($cl->id)) }}" class="hidden">@csrf @method('delete')</form>
                                                            @endif

                                                            {{--Receipt--}}
                                                            <a target="_blank" href="{{ route('payments.receipts', Qs::hash($cl->id)) }}" class="dropdown-item"><i class="icon-printer"></i> Print Receipt</a>

                                                            {{--PDF Receipt (plain link; see the note in the Incomplete tab about the .download-receipt handler)--}}
                                                            <a href="{{ route('payments.pdf_receipts', Qs::hash($cl->id)) }}" class="dropdown-item"><i class="icon-download"></i> Download Receipt (PDF)</a>

                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                    <tfoot>
                                    <tr>
                                        <th colspan="3" class="text-right">Total Completed</th>
                                        <th class="text-right">{{ \App\Helpers\Money::inr($cleared->sum(function($r){ return $r->payment->amount; })) }}</th>
                                        <th colspan="2"></th>
                                    </tr>
                                    </tfoot>
                                </table>
                                </div>
                                @else
                                    <div class="lav-empty">
                                        <p class="mb-0">No completed payments yet for {{ $sr->user->name }}.</p>
                                    </div>
                                @endif

                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Payment Summary rail --}}
            <div class="card pat-card l93-rail">
                <div class="card-body">
                    <div class="l93-card-head">
                        <span class="l93-search-icon"><i class="icon-file-text2"></i></span>
                        <div>
                            <h5>Payment Summary</h5>
                            <p>{{ $sr->user->name }} &middot; Adm {{ $sr->adm_no }}</p>
                        </div>
                    </div>

                    <div class="l93-sum-row"><span>Total Fees</span><strong>{{ \App\Helpers\Money::inr($l93TotalFees) }}</strong></div>
                    <div class="l93-sum-row"><span>Paid Amount</span><strong class="js-l93-paid">{{ \App\Helpers\Money::inr($l93TotalPaid) }}</strong></div>
                    <div class="l93-sum-row is-due"><span>Total Due</span><strong class="js-l93-due">{{ \App\Helpers\Money::inr($l93TotalDue) }}</strong></div>
                    <div class="l93-sum-row"><span>Payment Progress</span><strong><span class="js-l93-pct">{{ $l93Pct }}%</span></strong></div>
                    <div class="l93-progress"><div class="l93-progress-bar js-l93-bar" style="width: {{ $l93Pct }}%"></div></div>

                    <div class="l93-cur">
                        <div class="l93-cur-title">This Payment</div>
                        <div class="l93-sum-row"><span>Fee</span><strong id="l93-cur-fee">&mdash;</strong></div>
                        <div class="l93-sum-row"><span>Amount to Pay</span><strong id="l93-cur-amount">&mdash;</strong></div>
                        <div class="l93-sum-row"><span>Balance After Payment</span><strong id="l93-cur-after">&mdash;</strong></div>
                        <div class="l93-cur-warn" id="l93-cur-warn"></div>
                    </div>

                    @if(Qs::userIsTeamAccountWrite())
                    <button type="button" class="btn btn-primary l93-paybtn" id="l93-pay-btn" disabled><i class="icon-checkmark3 mr-1"></i> Record Payment</button>
                    @endif
                    <p class="l93-rail-note">Enter an amount in a fee row on the left, then record it here or with the row's own button. Receipts are dated the day they are recorded &mdash; today, {{ now()->format('d M Y') }} &mdash; and the receipt opens from the confirmation panel after saving.</p>
                </div>
            </div>
        </div>
    </div>

    {{--Payments Invoice List Ends--}}

@endsection

@section('scripts')
<script>
    // Workstream BJ: when a Pay Now POST succeeds, its JSON now carries
    // the receipt links for the record that was just paid — surface
    // them in the success panel so the receipt prints straight away.
    $(document).on('ajaxSuccess', function (ev, xhr, settings) {
        if (!settings.url || settings.url.indexOf('/payments/pay_now/') === -1) { return; }
        var r = xhr.responseJSON;
        if (!r || !r.ok || !r.receipt_url) { return; }
        $('#pay-success-text').text('Received ' + r.amt_paid_formatted + ' for ' + r.title + '. Balance now ' + r.balance_formatted + '.');
        $('#pay-success-print').attr('href', r.receipt_url);
        $('#pay-success-pdf').attr('href', r.pdf_url);
        var panel = $('#pay-success').show();
        setTimeout(function () {
            $('html, body').stop();
            var el = document.getElementById('pay-success');
            if (el) { el.scrollIntoView({behavior: 'smooth', block: 'center'}); }
        }, 150);
    });
    $('#pay-success-close').on('click', function () { $('#pay-success').hide(); });
</script>
<script>
    // Loop 93: the Payment Summary rail. It posts NOTHING itself —
    // its Record Payment button submits the active row's real
    // .ajax-pay form (the global handler validates, posts to
    // payments.pay_now and disables the row button in flight), and
    // the live figures are read from the rows' own data-amount
    // cells, the same values the pay-now JS maintains.
    (function () {
        var activeForm = null;

        function inr(n) { return '₹ ' + Number(n || 0).toLocaleString('en-IN'); }

        function recompute() {
            var fees = 0, paid = 0;
            $('.l93-fee-amt').each(function () { fees += parseInt($(this).data('amount')) || 0; });
            $('.l93-paid-amt').each(function () { paid += parseInt($(this).data('amount')) || 0; });
            var due = fees - paid;
            var pct = fees > 0 ? Math.round(paid / fees * 100) : 0;
            $('.js-l93-paid').text(inr(paid));
            $('.js-l93-due').text(inr(due));
            $('.js-l93-bar').css('width', pct + '%');
            $('.js-l93-pct').text(pct + '%');
        }

        function refreshCur() {
            if (!activeForm || !document.body.contains(activeForm[0])) {
                $('#l93-pay-btn').prop('disabled', true);
                return;
            }
            var fid = activeForm.attr('id');
            var fee = parseInt($('#amt-' + fid).data('amount')) || 0;
            var paid = parseInt($('#amt_paid-' + fid).data('amount')) || 0;
            var bal = fee - paid;
            var input = activeForm.find('input[name=amt_paid]');
            var v = parseInt(input.val()) || 0;
            $('#l93-cur-fee').text(input.data('title') || '—');
            $('#l93-cur-amount').text(v > 0 ? inr(v) : '—');
            $('#l93-cur-after').text(v > 0 ? inr(bal - v) : '—');
            var over = v > bal;
            $('#l93-cur-warn').toggle(over).text(over ? 'Amount exceeds the balance for this fee (' + inr(bal) + '). The server will refuse it.' : '');
            $('#l93-pay-btn').prop('disabled', !(v >= 1 && v <= bal));
        }

        $(document).on('focus input', 'form.ajax-pay input[name=amt_paid]', function () {
            activeForm = $(this).closest('form');
            refreshCur();
        });

        $('#l93-pay-btn').on('click', function () {
            if (!activeForm || $(this).prop('disabled')) { return; }
            $(this).prop('disabled', true);
            activeForm.trigger('submit');
        });

        $(document).on('ajaxSuccess', function (ev, xhr, settings) {
            if (!settings.url || settings.url.indexOf('/payments/pay_now/') === -1) { return; }
            var r = xhr.responseJSON;
            setTimeout(function () {
                recompute();
                if (r && r.ok && r.balance < 1) {
                    activeForm = null;
                    $('#l93-cur-fee, #l93-cur-amount, #l93-cur-after').text('—');
                    $('#l93-cur-warn').hide();
                    $('#l93-pay-btn').prop('disabled', true);
                } else {
                    refreshCur();
                }
            }, 60);
        });

        $(document).ajaxComplete(function () { refreshCur(); });
    })();
</script>
@endsection
