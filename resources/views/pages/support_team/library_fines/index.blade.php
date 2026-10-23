@extends('layouts.master')
@section('page_title', 'Library Fines')
@section('content')

    {{-- Library Fines (owner, 2026-10-23): a fine imposed on a
         student is mirrored into the student's main fee account —
         it appears in their fees, statement, balance and the
         defaulters list until it is paid there or waived here.
         Late returns impose the fine automatically at the Settings
         rate (₹{{ $rate }}/day); staff never calculate by hand. --}}
    @component('partials.patterns.list_page')
        @slot('subtitle')
            Fines imposed by the library. A student's fine is added to their main fee account automatically — it shows in their fees and dues until paid or waived. Late-return rate: ₹{{ $rate }} per day.
        @endslot
        @slot('action')
            <a href="{{ route('book_requests.index') }}" class="btn btn-light btn-sm"><i class="icon-arrow-left7 mr-1"></i> Book Loans</a>
        @endslot

        <div class="marx-stats">
            <div class="marx-stat marx-stat-absent">
                <div class="marx-stat-num">{{ $counts['pending'] }}</div>
                <div class="marx-stat-label">Pending Fines</div>
                <div class="marx-stat-cap">₹{{ number_format($counts['pending_amount']) }} outstanding</div>
            </div>
            <div class="marx-stat marx-stat-present">
                <div class="marx-stat-num">{{ $counts['paid'] }}</div>
                <div class="marx-stat-label">Paid</div>
                <div class="marx-stat-cap">Collected through fees</div>
            </div>
            <div class="marx-stat marx-stat-periods">
                <div class="marx-stat-num">{{ $counts['waived'] }}</div>
                <div class="marx-stat-label">Waived</div>
                <div class="marx-stat-cap">Removed by staff decision</div>
            </div>
        </div>

        <div class="pat-filters">
            <form method="get" action="{{ route('library_fines.index') }}" class="pat-filter-form">
                <div class="pat-filter-field">
                    <label for="lf-status">Status</label>
                    <select id="lf-status" name="status" class="form-control" onchange="this.form.submit()">
                        <option value="">All statuses</option>
                        @foreach(['pending' => 'Pending', 'paid' => 'Paid', 'waived' => 'Waived'] as $val => $label)
                            <option value="{{ $val }}" {{ $status === $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </form>
        </div>

        @if($fines->count() === 0)
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-coin-dollar',
                'message' => 'No library fines yet. When a book comes back late, the fine is imposed automatically and added to the student\'s fees.',
            ])
        @else
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>#</th><th>Student / Borrower</th><th>Book</th><th>Reason</th><th>Days Late</th><th>Amount</th><th>Imposed On</th><th>Status</th><th>In Fees</th>@if(in_array(Auth::user()->user_type, ['admin', 'librarian'], true))<th>Action</th>@endif</tr></thead>
                    <tbody>
                    @foreach($fines as $i => $fine)
                        <tr>
                            <td>{{ $fines->firstItem() + $i }}</td>
                            <td>{{ optional($fine->user)->name ?: '—' }}</td>
                            <td>{{ optional(optional($fine->book_request)->book)->name ?: '—' }}</td>
                            <td>{{ $fine->reason }}</td>
                            <td>{{ $fine->days_overdue ?: '—' }}</td>
                            <td>₹{{ number_format($fine->amount) }}</td>
                            <td>{{ $fine->created_at->format('d M Y') }}</td>
                            <td>
                                @if($fine->status === 'pending')<span class="badge badge-warning">Pending</span>
                                @elseif($fine->status === 'paid')<span class="badge badge-success">Paid</span>
                                @else<span class="badge badge-secondary">Waived</span>@endif
                            </td>
                            <td>{{ $fine->payment_record_id ? 'Yes — fee account' : '—' }}</td>
                            @if(in_array(Auth::user()->user_type, ['admin', 'librarian'], true))
                            <td>
                                @if($fine->status === 'pending')
                                    <form method="post" action="{{ route('library_fines.waive', Qs::hash($fine->id)) }}" style="display:inline" data-confirm="Waive this fine? The unpaid fee entry will be removed from the student's account." data-confirm-title="Waive Fine" data-confirm-action="Waive">@csrf<button class="btn btn-light btn-sm">Waive</button></form>
                                @endif
                            </td>
                            @endif
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            {{ $fines->links() }}
        @endif
    @endcomponent
@endsection
