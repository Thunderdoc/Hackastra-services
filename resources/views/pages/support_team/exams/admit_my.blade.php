@extends('layouts.master')
@section('page_title', 'My Admit Cards')
@section('content')

    {{-- Loop 108: legacy plain card shell replaced with the shared
         pat shell. Content unchanged (same designed empty states,
         same per-child exam tables and View / Print links). Tables
         carry l108-stack so every column stays visible on phones. --}}
    <div class="pat-list">
        <div class="card pat-card">
        <div class="card-body">
            @if($records->isEmpty())
                @include('partials.patterns.empty_state', [
                    'icon' => 'icon-users',
                    'message' => 'No student record was found for your account, so there are no admit cards to show.',
                ])
            @elseif($exams->isEmpty())
                @include('partials.patterns.empty_state', [
                    'icon' => 'icon-calendar2',
                    'message' => 'No exam has been scheduled for the current session yet. Please check again later.',
                ])
            @else
                @foreach($records as $sr)
                    <h6 class="mb-2">
                        {{ $sr->user->name }}
                        <small class="text-muted">— {{ $sr->my_class->name }}{{ $sr->section ? ', '.$sr->section->name : '' }} ({{ $sr->adm_no }})</small>
                    </h6>
                    <div class="table-responsive mb-4">
                        <table class="table l108-stack">
                            <thead>
                            <tr>
                                <th>Exam</th>
                                <th>Term</th>
                                <th>Session</th>
                                <th class="text-center">Admit Card</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($exams as $ex)
                                @php
                                    // ONE-TIME GENERATION freeze: a published exam shows
                                    // its identity as frozen at publish time.
                                    $frozenExam = ($ex->admit_cards_published && !empty($frozen_exams[$sr->user_id][$ex->id]))
                                        ? $frozen_exams[$sr->user_id][$ex->id] : null;
                                @endphp
                                <tr>
                                    <td data-label="Exam">{{ $frozenExam['name'] ?? $ex->name }}</td>
                                    <td data-label="Term">Term {{ $frozenExam['term'] ?? $ex->term }}</td>
                                    <td data-label="Session">{{ $frozenExam['year'] ?? $ex->year }}</td>
                                    <td class="text-center" data-label="Admit Card">
                                        @php $due = $due_by_student[$sr->user_id] ?? 0; @endphp
                                        @if(!$ex->admit_cards_published)
                                            {{-- Publication gate (owner order 2026-11-08): checked BEFORE
                                                 the fee state — an unpublished card is not a fee matter,
                                                 and no card link is offered until the Principal publishes. --}}
                                            <div class="alert alert-secondary py-2 px-2 mb-1" style="font-size:13px;">
                                                <i class="icon-eye-blocked mr-1"></i> Admit card not published yet.
                                                <div class="text-muted" style="font-size:12px;">Your school hasn't published this yet — it will appear here once published.</div>
                                            </div>
                                        @elseif($due > 0)
                                            <div class="alert alert-warning py-2 px-2 mb-1" style="font-size:13px;">
                                                <i class="icon-lock mr-1"></i> Fees pending — {{ \App\Helpers\Money::inr($due) }} due for {{ $current_session ?? \App\Helpers\Qs::getCurrentSession() }}. The admit card unlocks as soon as the fees are paid.
                                            </div>
                                            @if(!empty($viewer_is_parent))
                                                <a class="btn btn-sm btn-warning" href="{{ route('my_fees.show', $sr->user_id) }}">
                                                    <i class="icon-coins mr-1"></i> Pay the Fee
                                                </a>
                                            @endif
                                        @else
                                            <a target="_blank" class="btn btn-sm btn-primary"
                                               href="{{ route('admit_cards.my_card', [$ex->id, $sr->user_id]) }}">
                                                <i class="icon-printer mr-1"></i> View / Print
                                            </a>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endforeach
            @endif
        </div>
        </div>
    </div>

@endsection
