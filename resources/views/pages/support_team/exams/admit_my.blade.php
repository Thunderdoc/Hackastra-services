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
                <div class="lav-empty">
                    <p>No student record was found for your account, so there are no admit cards to show.</p>
                </div>
            @elseif($exams->isEmpty())
                <div class="lav-empty">
                    <p>No exam has been scheduled for the current session yet. Please check again later.</p>
                </div>
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
                                <tr>
                                    <td data-label="Exam">{{ $ex->name }}</td>
                                    <td data-label="Term">Term {{ $ex->term }}</td>
                                    <td data-label="Session">{{ $ex->year }}</td>
                                    <td class="text-center" data-label="Admit Card">
                                        <a target="_blank" class="btn btn-sm btn-primary"
                                           href="{{ route('admit_cards.my_card', [$ex->id, $sr->user_id]) }}">
                                            <i class="icon-printer mr-1"></i> View / Print
                                        </a>
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
