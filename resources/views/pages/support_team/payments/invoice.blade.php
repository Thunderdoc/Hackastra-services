@extends('layouts.master')
@section('page_title', 'Manage Payments')
@section('content')

    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title font-weight-bold">Manage Payment Records for {{ $sr->user->name}} </h6>
            {!! Qs::getPanelOptions() !!}
            <div class="header-elements">
                <a href="{{ route('payments.export_student', $year ? [Qs::hash($sr->user_id), $year] : [Qs::hash($sr->user_id)]) }}" class="btn btn-primary btn-sm"><i class="icon-download mr-1"></i> Export</a>
            </div>
        </div>

        <div class="card-body">
                <ul class="nav nav-tabs nav-tabs-highlight">
                    <li class="nav-item"><a href="#all-uc" class="nav-link active" data-toggle="tab">Incomplete Payments</a></li>
                    <li class="nav-item"><a href="#all-cl" class="nav-link" data-toggle="tab">Completed Payments</a></li>
                </ul>

        <div class="tab-content">
            <div class="tab-pane fade show active" id="all-uc">
                @if($uncleared->count())
                <table class="table datatable-button-html5-columns table-responsive">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Title</th>
                        <th>Pay_Ref</th>
                        <th class="text-right">Amount</th>
                        <th class="text-right">Paid</th>
                        <th class="text-right">Balance</th>
                        <th>Status</th>
                        <th>Pay Now</th>
                        <th>Receipt_No</th>
                        <th>Year</th>
                        <th>Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($uncleared as $uc)
                        <tr>
                            <td>{{ $loop->iteration }}</td>
                            <td>{{ $uc->payment->title }}</td>
                            <td>{{ $uc->payment->ref_no }}</td>

                            {{--Amount (data-amount stays raw: the pay-now JS reads it)--}}
                            <td class="font-weight-bold text-right" id="amt-{{ Qs::hash($uc->id) }}" data-amount="{{ $uc->payment->amount }}">{{ \App\Helpers\Money::inr($uc->payment->amount) }}</td>

                            {{--Amount Paid--}}
                            <td id="amt_paid-{{ Qs::hash($uc->id) }}" data-amount="{{ $uc->amt_paid ?: 0 }}" class="text-blue font-weight-bold text-right">{{ \App\Helpers\Money::inr($uc->amt_paid ?: 0) }}</td>

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
                                <form id="{{ Qs::hash($uc->id) }}" method="post" class="ajax-pay" action="{{ route('payments.pay_now', Qs::hash($uc->id)) }}">
                                    @csrf
                             <div class="row">
                                 <div class="col-md-7">
                                     <input min="1" max="{{ $uc->balance ?: $uc->payment->amount }}" id="val-{{ Qs::hash($uc->id) }}" class="form-control" required placeholder="Pay Now" title="Pay Now" name="amt_paid" type="number">
                                 </div>
                                 <div class="col-md-5">
                                     <button data-text="Pay" class="btn btn-danger" type="submit">Pay <i class="icon-paperplane ml-2"></i></button>
                                 </div>
                             </div>
                                </form>
                            </td>
                            {{--Receipt No--}}
                            <td>{{ $uc->ref_no }}</td>

                            <td>{{ $uc->year }}</td>

                            {{--Action--}}
                            <td class="text-center">
                                <div class="list-icons">
                                    <div class="dropdown">
                                        <a href="#" class="list-icons-item" data-toggle="dropdown"><i class="icon-menu9"></i>
                                        </a>

                                        <div class="dropdown-menu dropdown-menu-left">

                                            {{--Reset Payment--}}
                                            <a id="{{ Qs::hash($uc->id) }}" onclick="confirmReset(this.id)" href="#" class="dropdown-item"><i class="icon-reset"></i> Reset Payment</a>
                                            <form method="post" id="item-reset-{{ Qs::hash($uc->id) }}" action="{{ route('payments.reset_record', Qs::hash($uc->id)) }}" class="hidden">@csrf @method('delete')</form>

                                            {{--Receipt--}}
                                                <a target="_blank" href="{{ route('payments.receipts', Qs::hash($uc->id)) }}" class="dropdown-item"><i class="icon-printer"></i> Print Receipt</a>
                                            {{--PDF Receipt (plain link on purpose: the .download-receipt JS handler swallows the file with an AJAX GET, so it must not be used here)--}}
                                                <a href="{{ route('payments.pdf_receipts', Qs::hash($uc->id)) }}" class="dropdown-item"><i class="icon-download"></i> Download Receipt (PDF)</a>

                                        </div>
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
                        <th colspan="5"></th>
                    </tr>
                    </tfoot>
                </table>
                @else
                    <div class="lav-empty">
                        <p class="mb-0">No incomplete payments. Every fee for {{ $sr->user->name }} is fully paid.</p>
                    </div>
                @endif
            </div>

            <div class="tab-pane fade" id="all-cl">
                @if($cleared->count())
                <table class="table datatable-button-html5-columns table-responsive">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Title</th>
                        <th>Pay_Ref</th>
                        <th class="text-right">Amount</th>
                        <th>Status</th>
                        <th>Receipt_No</th>
                        <th>Year</th>
                        <th>Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($cleared as $cl)
                        <tr>
                            <td>{{ $loop->iteration }}</td>
                            <td>{{ $cl->payment->title }}</td>
                            <td>{{ $cl->payment->ref_no }}</td>

                            {{--Amount--}}
                            <td class="font-weight-bold text-right">{{ \App\Helpers\Money::inr($cl->payment->amount) }}</td>

                            {{--Status--}}
                            <td><span class="badge badge-success">Paid</span></td>

                            {{--Receipt No--}}
                            <td>{{ $cl->ref_no }}</td>

                            <td>{{ $cl->year }}</td>

                            {{--Action--}}
                            <td class="text-center">
                                <div class="list-icons">
                                    <div class="dropdown">
                                        <a href="#" class="list-icons-item" data-toggle="dropdown"><i class="icon-menu9"></i>
                                        </a>

                                        <div class="dropdown-menu dropdown-menu-left">

                                            {{--Reset Payment--}}
                                            <a id="{{ Qs::hash($cl->id) }}" onclick="confirmReset(this.id)" href="#" class="dropdown-item"><i class="icon-reset"></i> Reset Payment</a>
                                            <form method="post" id="item-reset-{{ Qs::hash($cl->id) }}" action="{{ route('payments.reset_record', Qs::hash($cl->id)) }}" class="hidden">@csrf @method('delete')</form>

                                            {{--Receipt--}}
                                            <a target="_blank" href="{{ route('payments.receipts', Qs::hash($cl->id)) }}" class="dropdown-item"><i class="icon-printer"></i> Print Receipt</a>

                                            {{--PDF Receipt (plain link; see the note in the Incomplete tab about the .download-receipt handler)--}}
                                            <a href="{{ route('payments.pdf_receipts', Qs::hash($cl->id)) }}" class="dropdown-item"><i class="icon-download"></i> Download Receipt (PDF)</a>

                                        </div>
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
                        <th colspan="4"></th>
                    </tr>
                    </tfoot>
                </table>
                @else
                    <div class="lav-empty">
                        <p class="mb-0">No completed payments yet for {{ $sr->user->name }}.</p>
                    </div>
                @endif

            </div>
        </div>
        </div>
    </div>

    {{--Payments Invoice List Ends--}}

@endsection
