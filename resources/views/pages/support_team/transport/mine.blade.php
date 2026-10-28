@extends('layouts.master')
@section('page_title', 'My Children — Transport')
@section('content')
    @component('partials.patterns.list_page')
        @slot('title') My Children's Transport @endslot
        @slot('subtitle') Only your own children's transport assignments are shown here. @endslot
        <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Child</th><th>Class</th><th>Vehicle</th><th>Route</th><th>Stop</th><th>Fees</th></tr></thead>
            <tbody>
            @forelse($assignments as $a)
                <tr>
                    <td>{{ $a->studentRecord && $a->studentRecord->user ? $a->studentRecord->user->name : '—' }}</td>
                    <td>{{ $a->studentRecord && $a->studentRecord->my_class ? $a->studentRecord->my_class->name : '—' }}</td>
                    <td>{{ $a->vehicle ? $a->vehicle->vehicle_no : '—' }}</td>
                    <td>{{ $a->vehicle && $a->vehicle->route ? $a->vehicle->route->name : '—' }}</td>
                    <td>{{ $a->stop ? $a->stop->name : '—' }}</td>
                    <td>
                        @forelse($a->fees as $f)
                            {{ $f->month }}: {{ $f->amount }} ({{ $f->paid ? 'Paid' : 'Unpaid' }})<br>
                        @empty
                            No fees yet.
                        @endforelse
                    </td>
                </tr>
            @empty
                <tr><td colspan="6">None of your children has a transport assignment yet.</td></tr>
            @endforelse
            </tbody>
        </table>
        </div>
    @endcomponent
@endsection
