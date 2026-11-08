@extends('layouts.master')
@section('page_title', 'Health Records')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Health &nbsp;/&nbsp; {!! $__env->yieldContent('page_title') !!}
@endsection
@if($can_write)
@section('page_actions')
    {{-- Beauty Batch 2 (audit A10): the create action takes the
         standard index-page placement — standard size, header
         right — instead of a small button under the card title. --}}
    <a href="{{ route('health_records.create') }}" class="btn btn-primary"><i class="icon-plus2 mr-1"></i> New Health Record</a>
@endsection
@endif
@section('content')
    {{-- Beauty Wave 3: the slim module banner (partials/module_banner)
         opens the page; its stats count the records THIS viewer is
         scoped to see (the list below), never the whole clinic.
         The intro's first sentence moves into the banner; the
         privacy line stays with the list. --}}
    @include('partials.module_banner', [
        'hkModIcon' => 'icon-heart6',
        'hkModTone' => 'red',
        'hkModSub' => 'Clinic visit log — only the records you are allowed to see are listed here.',
        'hkModStats' => [
            ['value' => number_format($records->count()), 'label' => 'Records'],
            ['value' => number_format($records->where('is_referred', true)->count()), 'label' => 'Referred'],
        ],
    ])
    <div class="card">
        {{-- Beauty Batch 3 (audit B1): no card heading — the page
             header owns the "Health Records" title and the banner
             above carries the context line; a third repetition of
             the title stood here. --}}
        <div class="card-body">
            <p class="text-muted">Complaint and treatment details are shown on the individual record page only.</p>
            @if($records->count())
                <div class="table-responsive">
                    <table class="table hk-std hk-std-640">
                    {{-- Inside-Matter Job 2 (2026-11-08): hk-std standard — colgroup widths sum to 100%. --}}
                    <colgroup><col style="width:14%"><col style="width:32%"><col style="width:13%"><col style="width:23%"><col style="width:18%"></colgroup>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Student</th>
                                <th>Referred</th>
                                <th>Recorded By</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($records as $record)
                                <tr>
                                    <td class="hk-nw">{{ optional($record->visit_date)->format('d M Y') }}</td>
                                    <td>{{ optional($record->student)->name }}</td>
                                    <td>
                                        @if($record->is_referred)<span class="badge badge-warning">Referred</span>
                                        @else<span class="badge badge-secondary">No</span>@endif
                                    </td>
                                    <td>{{ optional($record->recorder)->name }}</td>
                                    <td><a href="{{ route('health_records.show', $record->id) }}" class="btn btn-light btn-sm">View</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                @include('partials.patterns.empty_state', [
                    'icon' => 'icon-heart6',
                    'message' => 'No health records yet.',
                ])
            @endif
        </div>
    </div>
@endsection
