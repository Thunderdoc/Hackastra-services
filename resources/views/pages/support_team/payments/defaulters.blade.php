@extends('layouts.master')
@section('page_title', 'Fee Defaulters')
@section('content')
    {{-- Workstream BT: print-only document header/footer — the same
         print-doc standard as the students list (Loop 31). Hidden on
         screen; window.print() produces a branded school report. --}}
    {{-- Document design v2 (Workstream DB, Loop 71): the head carries
         the uploaded logo, or a navy monogram tile when none is
         uploaded (never the theme's light fallback logo), and the
         page carries the watermark — logo or app-code monogram —
         behind the content. Print metrics are untouched (Loop 62):
         the report still fits exactly one A4 page. --}}
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
            <p>Fee Defaulters &mdash; Session: {{ $year }}{{ $selected_class ? ' &middot; Class: '.$selected_class->name : '' }} &middot; {{ $rows->count() }} defaulter(s) owing {{ \App\Helpers\Money::inr($total_due) }}</p>
        </div>
    </div>
    @if(Qs::getSetting('logo'))
        <img class="print-doc-watermark" src="{{ Qs::getSetting('logo') }}" alt="">
    @else
        <div class="print-doc-watermark-mono" aria-hidden="true">{{ strtoupper((string) Qs::getAppCode()) }}</div>
    @endif

    {{-- Workstream CK: the screen side is rebuilt on the shared
         list_page pattern (Loop 28) — class filter as the pattern
         filter row, Print / Export CSV as the toolbar actions, the
         designed empty state. The print contract is untouched: the
         print-doc header/footer above/below are BT's, and the
         compact one-page print metrics Loop 47 keyed to .def-doc
         are mirrored for this page's pattern card in qs.css
         Loop 62 (.pay-def scope). Rows, order and every figure
         are unchanged. --}}
    <div class="pay-def">
        @component('partials.patterns.list_page')
            @slot('action')
                <button type="button" class="btn btn-light btn-sm" onclick="window.print()"><i class="icon-printer mr-1"></i> Print</button>
                <a href="{{ route('payments.defaulters.export', $selected_class ? ['class_id' => $selected_class->id] : []) }}" class="btn btn-primary btn-sm"><i class="icon-download mr-1"></i> Export CSV</a>
            @endslot
            @slot('filters')
                <form method="GET" action="{{ route('payments.defaulters') }}" class="pat-filter-form print-hide">
                    <div class="pat-filter-field">
                        <label for="def_class_id">Class</label>
                        <select id="def_class_id" name="class_id" class="form-control" onchange="this.form.submit()">
                            <option value="">All Classes</option>
                            @foreach($classes as $class)
                                <option value="{{ $class->id }}" {{ $selected_class && $selected_class->id === $class->id ? 'selected' : '' }}>{{ $class->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="pat-filter-field">
                        <span class="text-muted">{{ $rows->count() }} defaulter(s) owing {{ \App\Helpers\Money::inr($total_due) }} in {{ $year }}{{ $selected_class ? ' in ' . $selected_class->name : '' }}</span>
                    </div>
                </form>
            @endslot

            @if($rows->isEmpty())
                @include('partials.patterns.empty_state', [
                    'icon' => 'icon-checkmark-circle2',
                    'message' => 'No fee defaulters for ' . $year . ($selected_class ? ' in ' . $selected_class->name : '') . '. Every current-session fee record is fully paid.',
                ])
            @else
                <table class="table">
                    <thead>
                    <tr>
                        <th style="width:34px">#</th>
                        <th>Student</th>
                        <th>Adm No</th>
                        <th>Class</th>
                        <th>Section</th>
                        <th>Unpaid Items</th>
                        <th>Oldest Unpaid Fee</th>
                        <th>Since</th>
                        <th class="text-right">Total Due</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($rows as $row)
                        <tr>
                            <td>{{ $loop->iteration }}</td>
                            <td><strong>{{ optional($row['student']->user)->name }}</strong></td>
                            <td>{{ $row['student']->adm_no ?: '—' }}</td>
                            <td>{{ optional($row['student']->my_class)->name ?: '—' }}</td>
                            <td>{{ optional($row['student']->section)->name ?: '—' }}</td>
                            <td>{{ $row['items'] }}</td>
                            <td>{{ $row['oldest_title'] ?: '—' }}</td>
                            <td>{{ $row['oldest_since'] ? $row['oldest_since']->format('d M Y') : '—' }}</td>
                            <td class="text-right text-danger font-weight-bold">{{ \App\Helpers\Money::inr($row['total_due']) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                    <tfoot>
                    <tr>
                        <th colspan="8">Total Outstanding</th>
                        <th class="text-right">{{ \App\Helpers\Money::inr($total_due) }}</th>
                    </tr>
                    </tfoot>
                </table>
                <p class="text-muted mb-0 def-note"><small>Balance per record follows the invoice page: the stored balance, or the full fee amount for untouched records. Partially paid records count only their remaining balance. Worst first.</small></p>
            @endif
        @endcomponent
    </div>

    <div class="print-doc-foot">
        <span>Printed on {{ now()->format('d M Y, g:i A') }} &middot; {{ Qs::getSystemName() }}</span>
        <span>Fee Defaulters &mdash; {{ $year }} &middot; Generated by Hackstra</span>
    </div>
@endsection
