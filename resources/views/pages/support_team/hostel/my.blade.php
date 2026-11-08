@extends('layouts.master')
@section('page_title', 'My Hostel Allocation')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Hostel &nbsp;/&nbsp; {!! $__env->yieldContent('page_title') !!}
@endsection
@section('content')
    <div class="card">
        <div class="card-header">
            <h5 class="card-title">My Hostel Allocation</h5>
        </div>
        <div class="card-body">
            @if($records->count())
                <div class="table-responsive">
                    <table class="table hk-std hk-std-560">
                    {{-- Inside-Matter Job 2 (2026-11-08): hk-std standard — colgroup widths sum to 100%. --}}
                    <colgroup><col style="width:40%"><col style="width:34%"><col style="width:26%"></colgroup>
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Dorm</th>
                                <th>Room No</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($records as $record)
                                <tr>
                                    <td>{{ optional($record->user)->name }}</td>
                                    <td>{{ optional($record->dorm)->name ?: 'Not allocated' }}</td>
                                    <td class="hk-nw">{{ $record->dorm_room_no ?: 'Not allocated' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                @include('partials.patterns.empty_state', ['icon' => 'icon-home9', 'message' => 'No hostel allocation found.'])
            @endif
        </div>
    </div>
@endsection
