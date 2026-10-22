@extends('layouts.master')
@section('page_title', 'Student Payments')
@section('content')

    {{-- Workstream CK: rebuilt on the shared list_page pattern
         (Loop 28), the same chrome as Students / Attendance. The
         class picker is the pattern filter row (same POST to
         payments.select_class, same field name), Print / Export are
         the toolbar actions (same routes), and the per-student
         Payments dropdown keeps its exact menu (All Payments + one
         invoice link per year) on the labelled button standard.
         Workstream BT's compact-picker cure is preserved inside the
         filter row. --}}

    {{-- Workstream CL: print-only document header/footer — the shared
         print-doc-head/foot pattern (students list, Loop 31; Loop 63),
         hidden on screen, shown by window.print() so the student
         payments list prints as a professional school document. The
         Photo and Payments (actions) columns carry the shared
         print-hide utility, exactly as on the students list. --}}
    {{-- Loop 107: print head upgraded to the Fee Defaulters standard
         (logo-or-monogram + watermark). The screen list below is wrapped
         print-hide and never reaches paper; the printed document is the
         .pay-mgr-print-only table after it (Loop 107 print CSS). --}}
    @php
        $pmInitials = '';
        foreach (preg_split('/\s+/', trim((string) Qs::getSetting('system_name'))) as $pmWord) {
            if ($pmWord !== '') {
                $pmInitials .= strtoupper(substr($pmWord, 0, 1));
                if (strlen($pmInitials) >= 2) { break; }
            }
        }
        if ($pmInitials === '') { $pmInitials = 'S'; }
    @endphp
    <div class="print-doc-head">
        @if(Qs::getSetting('logo'))
            <img src="{{ Qs::getSetting('logo') }}" alt="">
        @else
            <span class="print-doc-mono" aria-hidden="true">{{ $pmInitials }}</span>
        @endif
        <div>
            <h1>{{ Qs::getSystemName() }}</h1>
            <p>Student Payments &mdash; {{ $selected ? optional($my_classes->firstWhere('id', $my_class_id))->name : 'No class selected' }} &middot; Session: {{ Qs::getCurrentSession() }}@if($selected && isset($print_rows)) &middot; {{ $print_rows->count() }} student(s)@endif</p>
        </div>
    </div>
    @if(Qs::getSetting('logo'))
        <img class="print-doc-watermark" src="{{ Qs::getSetting('logo') }}" alt="">
    @else
        <div class="print-doc-watermark-mono" aria-hidden="true">{{ strtoupper((string) Qs::getAppCode()) }}</div>
    @endif

    <div class="print-hide">
    @component('partials.patterns.list_page')
        @slot('subtitle')
            Pick a class, then open a student&rsquo;s payment records to take a fee or print a receipt.
        @endslot
        @if($selected)
            @slot('action')
                <button type="button" class="btn btn-light btn-sm" onclick="window.print()"><i class="icon-printer mr-1"></i> Print</button>
                <a href="{{ route('payments.export', $my_class_id) }}" class="btn btn-primary btn-sm"><i class="icon-download mr-1"></i> Export</a>
            @endslot
        @endif
        @slot('filters')
            <form method="post" action="{{ route('payments.select_class') }}" class="pat-filter-form">
                @csrf
                <div class="pat-filter-field">
                    <label for="my_class_id">Class:</label>
                    <select required id="my_class_id" name="my_class_id" class="form-control select">
                        <option value="">Select Class</option>
                        @foreach($my_classes as $c)
                            <option {{ ($selected && $my_class_id == $c->id) ? 'selected' : '' }} value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-filter-action">
                    <button type="submit" class="btn btn-primary">Submit <i class="icon-paperplane ml-2"></i></button>
                </div>
            </form>
        @endslot

        @if($selected)
            @if($students->isEmpty())
                @include('partials.patterns.empty_state', [
                    'icon' => 'icon-users',
                    'message' => 'No active students in this class yet.',
                ])
            @else
                <table class="table l108-stack">
                    <thead>
                    <tr>
                        <th>S/N</th>
                        <th class="print-hide">Photo</th>
                        <th>Name</th>
                        <th>ADM_No</th>
                        <th class="print-hide">Payments</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($students as $s)
                        <tr>
                            <td data-label="S/N">{{ $loop->iteration }}</td>
                            <td class="print-hide" data-label="Photo">@if(Qs::hasRealPhoto($s->user->photo))<img class="rounded-circle" style="height: 40px; width: 40px;" src="{{ $s->user->photo }}" alt="photo">@else<span class="hk-ini" style="width:40px;height:40px;font-size:.8rem" aria-hidden="true">{{ Qs::initials($s->user->name) }}</span>@endif</td>
                            <td data-label="Name">{{ $s->user->name }}</td>
                            <td data-label="ADM No">{{ $s->adm_no }}</td>
                            <td class="print-hide" data-label="Payments">
                                <div class="dropdown">
                                    <button type="button" class="btn btn-sm btn-primary dropdown-toggle" data-toggle="dropdown">Manage Payments</button>
                                    <div class="dropdown-menu dropdown-menu-left">
                                        <a href="{{ route('payments.invoice', [Qs::hash($s->user_id)]) }}" class="dropdown-item">All Payments</a>
                                        @foreach(Pay::getYears($s->user_id) as $py)
                                        @if($py)
                                            <a href="{{ route('payments.invoice', [Qs::hash($s->user_id), $py]) }}" class="dropdown-item">{{ $py }}</a>
                                        @endif
                                        @endforeach
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        @else
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-cash2',
                'message' => 'Pick a class above to see its students and their payments.',
            ])
        @endif
    @endcomponent
    </div>

    @if($selected && isset($print_rows))
        {{-- Loop 107: the printed A4 document — fee position per student
             from PaymentController@manage's print assembly (same
             derivation as the CSV export). Screen-hidden, print-only. --}}
        <div class="pay-mgr-print-only">
            <table class="pay-mgr-print-table">
                <thead>
                <tr>
                    <th>S/N</th>
                    <th>Adm No</th>
                    <th>Student</th>
                    <th>Class / Section</th>
                    <th class="r">Total Fees</th>
                    <th class="r">Paid</th>
                    <th class="r">Balance</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody>
                @foreach($print_rows as $pr)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td>{{ $pr['adm_no'] }}</td>
                        <td>{{ $pr['name'] }}</td>
                        <td>{{ $pr['class_section'] }}</td>
                        <td class="r">{{ \App\Helpers\Money::inr($pr['total']) }}</td>
                        <td class="r">{{ \App\Helpers\Money::inr($pr['paid']) }}</td>
                        <td class="r">{{ \App\Helpers\Money::inr($pr['balance']) }}</td>
                        <td>{{ $pr['status'] }}</td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot>
                <tr>
                    <td colspan="4">Class Totals &mdash; {{ $print_rows->count() }} student(s)</td>
                    <td class="r">{{ \App\Helpers\Money::inr($print_totals['total']) }}</td>
                    <td class="r">{{ \App\Helpers\Money::inr($print_totals['paid']) }}</td>
                    <td class="r">{{ \App\Helpers\Money::inr($print_totals['balance']) }}</td>
                    <td></td>
                </tr>
                </tfoot>
            </table>
        </div>
    @endif

    <div class="print-doc-foot">
        <span>Printed on {{ now()->format('d M Y, g:i A') }} &middot; {{ Qs::getSystemName() }}</span>
        <span>Student Payments</span>
    </div>

@endsection
