@extends('layouts.master')
@section('page_title', 'My Library')
@section('content')

    {{-- Student library portal (2026-10-23): the student's own book
         loans and library fines, read-only, on the shared list_page
         pattern. Until now students could not see even their own
         loans (the staff requests page redirects them home); scope
         is server-side — the controller loads only the signed-in
         student's rows. Returning books and paying fines stay at
         the library desk / school office. --}}
    @component('partials.patterns.list_page')
        @slot('subtitle')
            Books you have borrowed and any library fines in your name.
        @endslot

        <h6 class="font-weight-bold">My Books</h6>
        @if($loans->count())
            <div class="table-responsive">
                <table class="table">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Book</th>
                        <th>Author</th>
                        <th>Issued On</th>
                        <th>Due Date</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($loans as $loan)
                        <tr>
                            <td>{{ $loop->iteration }}</td>
                            <td class="font-weight-bold">{{ optional($loan->book)->name ?: '—' }}</td>
                            <td>{{ optional($loan->book)->author ?: '—' }}</td>
                            <td>{{ $loan->start_date ? \Carbon\Carbon::parse($loan->start_date)->format('d M Y') : '—' }}</td>
                            <td>{{ $loan->end_date ? \Carbon\Carbon::parse($loan->end_date)->format('d M Y') : '—' }}</td>
                            <td>
                                @if($loan->status === \App\Models\BookRequest::STATUS_RETURNED || $loan->returned)
                                    <span class="badge badge-success">Returned</span>
                                @elseif($loan->end_date && \Carbon\Carbon::parse($loan->end_date)->isPast())
                                    <span class="badge badge-danger">Overdue</span>
                                @else
                                    <span class="badge badge-info">Issued</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @else
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-book',
                'message' => 'You have not borrowed any books yet.',
            ])
        @endif

        <h6 class="font-weight-bold mt-4">My Fines</h6>
        @if($fines->count())
            <div class="table-responsive">
                <table class="table">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Book</th>
                        <th>Reason</th>
                        <th>Days Overdue</th>
                        <th class="text-right">Amount</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($fines as $fine)
                        <tr>
                            <td>{{ $loop->iteration }}</td>
                            <td class="font-weight-bold">{{ optional(optional($fine->book_request)->book)->name ?: '—' }}</td>
                            <td>{{ $fine->reason ?: 'Late return' }}</td>
                            <td>{{ $fine->days_overdue }}</td>
                            <td class="text-right">{{ \App\Helpers\Money::inr($fine->amount) }}</td>
                            <td>
                                @if($fine->status === 'paid')
                                    <span class="badge badge-success">Paid</span>
                                @elseif($fine->status === 'waived')
                                    <span class="badge badge-secondary">Waived</span>
                                @else
                                    <span class="badge badge-danger">Pending</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <p class="text-muted">Pending fines are added to your fee records — pay them with your fees at the school office.</p>
        @else
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-checkmark-circle2',
                'message' => 'No library fines. Keep returning books on time!',
            ])
        @endif
    @endcomponent
@endsection
