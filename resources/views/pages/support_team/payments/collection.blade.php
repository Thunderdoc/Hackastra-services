@extends('layouts.master')
@section('page_title', 'Fee Collection Report')
@section('content')

    {{-- Workstream CK: rebuilt on the shared list_page pattern
         (Loop 28), the same chrome as Students / Attendance. The
         legacy single card with panel options and a form-inline
         filter is gone: the date range is the pattern filter row,
         Print is the toolbar action, and the receipts-count line
         rides as the toolbar subtitle. Routes, GET params (from/to)
         and every figure are unchanged. --}}

    {{-- Workstream CL: print-only document header/footer — the shared
         print-doc-head/foot pattern (students list, Loop 31; Loop 63),
         hidden on screen, shown by window.print() so the collection
         report prints as a professional school document. --}}
    {{-- Document design v2 (Workstream DB, Loop 71): the head carries
         the uploaded logo, or a navy monogram tile when none is
         uploaded (never the theme's light fallback logo), and the
         page carries the watermark — logo or app-code monogram —
         behind the content. --}}
    @php
        $dbInitials = '';
        foreach (preg_split('/\s+/', trim((string) Qs::getSetting('system_name'))) as $dbWord) {
            if ($dbWord !== '') {
                $dbInitials .= strtoupper(substr($dbWord, 0, 1));
                if (strlen($dbInitials) >= 2) { break; }
            }
        }
        if ($dbInitials === '') { $dbInitials = 'S'; }
    @endphp
    <div class="print-doc-head">
        @if(Qs::getSetting('logo'))
            <img src="{{ Qs::getSetting('logo') }}" alt="">
        @else
            <span class="print-doc-mono" aria-hidden="true">{{ $dbInitials }}</span>
        @endif
        <div>
            <h1>{{ Qs::getSystemName() }}</h1>
            <p>Fee Collection Report &mdash; {{ $from->format('d M Y') }} to {{ $to->format('d M Y') }} &middot; {{ $receipts_count }} receipt(s)</p>
        </div>
    </div>
    @if(Qs::getSetting('logo'))
        <img class="print-doc-watermark" src="{{ Qs::getSetting('logo') }}" alt="">
    @else
        <div class="print-doc-watermark-mono" aria-hidden="true">{{ strtoupper((string) Qs::getAppCode()) }}</div>
    @endif

    @component('partials.patterns.list_page')
        @slot('subtitle')
            {{ $receipts_count }} receipt(s) between {{ $from->format('d M Y') }} and {{ $to->format('d M Y') }}
        @endslot
        @slot('action')
            <button type="button" class="btn btn-light btn-sm" onclick="window.print()"><i class="icon-printer mr-1"></i> Print</button>
        @endslot
        @slot('filters')
            <form method="GET" action="{{ route('payments.collection') }}" class="pat-filter-form">
                <div class="pat-filter-field">
                    <label for="col_from">From</label>
                    <input type="date" id="col_from" name="from" class="form-control" value="{{ $from->format('Y-m-d') }}">
                </div>
                <div class="pat-filter-field">
                    <label for="col_to">To</label>
                    <input type="date" id="col_to" name="to" class="form-control" value="{{ $to->format('Y-m-d') }}">
                </div>
                <div class="pat-filter-action">
                    <button type="submit" class="btn btn-primary"><i class="icon-filter3 mr-1"></i> Filter</button>
                </div>
            </form>
        @endslot

        @if($rows->isEmpty())
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-coins',
                'message' => 'No fee collections were recorded between ' . $from->format('d M Y') . ' and ' . $to->format('d M Y') . '.',
            ])
        @else
            <table class="table">
                <thead>
                <tr>
                    <th style="width:44px">#</th>
                    <th>Date</th>
                    <th>Receipts</th>
                    <th class="text-right">Collected</th>
                </tr>
                </thead>
                <tbody>
                @foreach($rows as $row)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td><strong>{{ \Carbon\Carbon::parse($row->day)->format('d M Y') }}</strong></td>
                        <td>{{ $row->receipts_count }}</td>
                        <td class="text-right font-weight-bold">{{ \App\Helpers\Money::inr($row->total) }}</td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot>
                <tr>
                    <th></th>
                    <th>Grand Total</th>
                    <th>{{ $receipts_count }}</th>
                    <th class="text-right">{{ \App\Helpers\Money::inr($grand_total) }}</th>
                </tr>
                </tfoot>
            </table>
            <p class="text-muted mb-0 mt-2"><small>From the receipts ledger: one receipt is recorded per payment taken, dated the day the money was received.</small></p>
        @endif
    @endcomponent

    <div class="print-doc-foot">
        <span>Printed on {{ now()->format('d M Y, g:i A') }} &middot; {{ Qs::getSystemName() }}</span>
        <span>Fee Collection Report &middot; Generated by Hackstra</span>
    </div>

@endsection
