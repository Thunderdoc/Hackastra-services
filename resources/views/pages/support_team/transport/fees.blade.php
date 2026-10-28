@extends('layouts.master')
@section('page_title', 'Transport Fees')
@section('content')
    @component('partials.patterns.list_page')
        @slot('title') Transport Fees @endslot
        @slot('subtitle') One fee row per student assignment per month. @endslot
        @slot('action')
            <a href="{{ route('transport.index') }}" class="btn btn-primary">Back to Transport</a>
        @endslot
        <form method="get" action="{{ route('transport.fees') }}">
            <div class="form-group"><label for="month_filter">Filter by month</label>
                <input id="month_filter" name="month" type="month" value="{{ $month }}" class="form-control"></div>
            <button type="submit" class="btn btn-primary">Filter</button>
            <a href="{{ route('transport.fees') }}" class="btn btn-default">Clear</a>
        </form>
        <div class="table-responsive">
        <table class="table">
            <thead><tr><th>S/N</th><th>Student</th><th>Vehicle</th><th>Stop</th><th>Month</th><th>Amount</th><th>Status</th><th>Paid At</th><th>Action</th></tr></thead>
            <tbody>
            @forelse($fees as $f)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $f->assignment && $f->assignment->studentRecord && $f->assignment->studentRecord->user ? $f->assignment->studentRecord->user->name : '—' }}</td>
                    <td>{{ $f->assignment && $f->assignment->vehicle ? $f->assignment->vehicle->vehicle_no : '—' }}</td>
                    <td>{{ $f->assignment && $f->assignment->stop ? $f->assignment->stop->name : '—' }}</td>
                    <td>{{ $f->month }}</td>
                    <td>{{ $f->amount }}</td>
                    <td>{{ $f->paid ? 'Paid' : 'Unpaid' }}</td>
                    <td>{{ $f->paid_at ? $f->paid_at->format('Y-m-d H:i') : '—' }}</td>
                    <td>
                        @if(Qs::userIsTeamSAWrite() && !$f->paid)
                        <form method="post" action="{{ route('transport.fees.mark_paid', ['transport_fee' => Qs::hash($f->id)]) }}" style="display:inline">@csrf
                            <button type="submit" class="btn btn-sm btn-success">Mark Paid</button>
                        </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="9">No fees yet.</td></tr>
            @endforelse
            </tbody>
        </table>
        </div>
    @endcomponent

    @if(Qs::userIsTeamSAWrite())
    @component('partials.patterns.form_page', ['form_action' => route('transport.fees.store'), 'submit_label' => 'Add Fee'])
        @slot('title') New Transport Fee @endslot
        <div class="pat-form-section"><div class="pat-fields">
            <div class="pat-field">
                <label for="transport_assignment_id">Assignment *</label>
                <select id="transport_assignment_id" name="transport_assignment_id" required class="form-control">
                    <option value="">Select assignment</option>
                    @foreach($assignments as $a)
                    <option value="{{ $a->id }}">{{ $a->studentRecord && $a->studentRecord->user ? $a->studentRecord->user->name : 'Student' }}{{ $a->vehicle ? ' — '.$a->vehicle->vehicle_no : '' }}{{ $a->stop ? ' — '.$a->stop->name : '' }}</option>
                    @endforeach
                </select>
            </div>
            <div class="pat-field">
                <label for="month">Month *</label>
                <input id="month" name="month" type="month" required class="form-control">
            </div>
            <div class="pat-field">
                <label for="amount">Amount *</label>
                <input id="amount" name="amount" type="number" step="0.01" min="0" required class="form-control">
            </div>
        </div></div>
    @endcomponent
    @endif
@endsection
