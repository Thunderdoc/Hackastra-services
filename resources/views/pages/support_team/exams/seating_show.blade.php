@extends('layouts.master')
@section('page_title', 'Exam Seating Plan')
@section('content')

    {{-- F3 — one slot's plan: hall summary + per-hall seat tables
         (hk-std), lifecycle actions (Admin submits, Principal
         publishes), staleness surfaced loudly (§7.2). --}}
    @component('partials.patterns.list_page')
        @slot('subtitle')
            {{ $plan->exam->name ?? 'Exam' }} · {{ \Carbon\Carbon::parse($plan->exam_date)->format('D, d M Y') }}
            @if($plan->start_time) · {{ \Carbon\Carbon::parse($plan->start_time)->format('h:i A') }} @endif
            · {{ $plan->mode === 'mixed' ? 'Mixed seating' : 'Separate seating' }}
            · {{ $plan->total_students }} students in {{ count($halls) }} hall(s)
        @endslot
        @slot('action')
            <a href="{{ route('exam_seating.index', ['exam_id' => $plan->exam_id]) }}" class="btn btn-light btn-sm"><i class="icon-arrow-left mr-1"></i> All Slots</a>
            @if(Qs::userIsTeamSAWrite() && $plan->status === 'draft')
                <form method="post" action="{{ route('exam_seating.submit', $plan->id) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-success btn-sm">Submit for Principal Approval</button>
                </form>
            @endif
            @if(Qs::userIsPrincipal() && $plan->status === 'submitted')
                <form method="post" action="{{ route('exam_seating.approve') }}" class="d-inline">
                    @csrf
                    <input type="hidden" name="plan_id" value="{{ $plan->id }}">
                    <button type="submit" class="btn btn-success btn-sm">Publish Plan</button>
                </form>
            @endif
        @endslot

        @if($plan->status === 'published')
            <div class="alert alert-success border-0">This plan is <strong>published</strong> — teachers can see the hall sheets for their own duties.</div>
        @elseif($plan->status === 'submitted')
            <div class="alert alert-warning border-0">This plan is <strong>awaiting the Principal</strong>. Only the Principal can publish it.</div>
        @else
            <div class="alert alert-info border-0">This plan is a <strong>draft</strong>. Review the halls below, then submit it for Principal approval.</div>
        @endif

        @if($stale)
            <div class="alert alert-danger border-0">
                <strong>Changed since generation:</strong> the date sheet, a roster or a hall changed after this plan was generated.
                Regenerate the slot from the <a href="{{ route('exam_seating.index', ['exam_id' => $plan->exam_id]) }}">Exam Seating page</a> — the plan cannot be submitted or published until it matches its inputs again.
            </div>
        @endif

        @foreach($halls as $hall)
            <div class="d-flex align-items-center mt-4 mb-2" style="gap:.5rem; flex-wrap:wrap;">
                <h5 class="mb-0">{{ $hall['room_name'] }}</h5>
                <span class="text-muted">{{ $hall['seats']->count() }} of {{ $hall['capacity'] }} seats · Invigilator: {{ $hall['invigilator_label'] }}</span>
                <span class="ml-auto">
                    <a href="{{ route('exam_seating.hall_sheet', ['plan_id' => $plan->id, 'room_id' => $hall['room_id']]) }}" class="btn btn-light btn-sm">Hall Sheet</a>
                    <a href="{{ route('exam_seating.hall_sheet_pdf', ['plan_id' => $plan->id, 'room_id' => $hall['room_id']]) }}" class="btn btn-primary btn-sm">Hall Sheet PDF</a>
                </span>
            </div>
            <div class="table-responsive">
                <table class="table hk-std hk-std-seating">
                    <thead>
                        <tr>
                            <th>Seat</th>
                            <th>Adm No</th>
                            <th>Student</th>
                            <th>Class</th>
                            <th>Subject</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($hall['seats'] as $seat)
                            <tr>
                                <td>{{ $seat->seat_no }}</td>
                                <td>{{ $seat->adm_no ?: '—' }}</td>
                                <td>{{ $seat->student_name }}</td>
                                <td>{{ $seat->class_name }}@if($seat->section_name) — {{ $seat->section_name }}@endif</td>
                                <td>{{ $seat->subject_name }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endforeach
    @endcomponent
@endsection
