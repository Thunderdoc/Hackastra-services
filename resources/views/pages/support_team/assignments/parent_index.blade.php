@extends('layouts.master')
@section('page_title', 'Assignments')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Home</a> &nbsp;/&nbsp; Assignments
@endsection
@section('content')
    {{-- Workstream CS2 (LMS): parent read-only assignment view,
         structured exactly like the parent Fees page (Workstream CK):
         $children entries of ['sr' => StudentRecord, 'rows' =>
         assignments for that child]. Status + marks only — no forms,
         nothing editable; each row's own submission is resolved from
         $assignment->my_submission (or the first eager-loaded
         submission), same convention as the student list. --}}
    <div class="pat-list">
        <div class="pat-toolbar">
            <div class="pat-toolbar-text">
                <div class="pat-toolbar-sub">Assignments given to your children, their submission status and marks — as recorded by their teachers.</div>
            </div>
        </div>

        <div class="card pat-card">
            <div class="card-body">
                @forelse($children as $child)
                    @php $sr = $child['sr']; @endphp
                    <div class="d-flex justify-content-between align-items-center flex-wrap mb-2">
                        <h6 class="mb-1">
                            <i class="icon-user text-blue mr-1"></i> {{ optional($sr->user)->name }}
                            <small class="text-muted">
                                {{ optional($sr->my_class)->name ?: 'Class —' }} &middot; {{ optional($sr->section)->name ?: 'Section —' }}
                                @if($sr->adm_no) &middot; Adm No: {{ $sr->adm_no }} @endif
                            </small>
                        </h6>
                    </div>

                    @if($child['rows']->isEmpty())
                        @include('partials.patterns.empty_state', [
                            'icon' => 'icon-file-text2',
                            'message' => 'No assignments for ' . optional($sr->user)->name . ' yet.',
                        ])
                    @else
                        <div class="table-responsive mb-4">
                            <table class="table lms-table">
                                <thead>
                                <tr>
                                    <th>Assignment</th>
                                    <th>Subject</th>
                                    <th>Due</th>
                                    <th>Status</th>
                                    <th>Marks</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($child['rows'] as $assignment)
                                    @php
                                        $due = $assignment->due_at ? \Carbon\Carbon::parse($assignment->due_at) : null;
                                        $sub = $assignment->my_submission ?? (isset($assignment->submissions) ? $assignment->submissions->first() : null);
                                        if ($sub && $sub->marks_awarded !== null) {
                                            $statusLabel = 'Graded'; $statusChip = 'lms-chip-graded';
                                        } elseif ($sub && $sub->is_late) {
                                            $statusLabel = 'Submitted (Late)'; $statusChip = 'lms-chip-late';
                                        } elseif ($sub) {
                                            $statusLabel = 'Submitted'; $statusChip = 'lms-chip-submitted';
                                        } elseif ($due && $due->isPast()) {
                                            $statusLabel = 'Missed'; $statusChip = 'lms-chip-closed';
                                        } else {
                                            $statusLabel = 'Pending'; $statusChip = 'lms-chip-pending';
                                        }
                                    @endphp
                                    <tr>
                                        <td>
                                            <strong>{{ $assignment->title }}</strong>
                                            <div class="text-muted">{{ optional($assignment->teacher)->name ?? '' }}</div>
                                        </td>
                                        <td><span class="lms-chip lms-chip-subject">{{ optional($assignment->subject)->name ?? '—' }}</span></td>
                                        <td>
                                            @if($due)
                                                {{ $due->format('d M Y') }}
                                                <div class="text-muted">{{ $due->diffForHumans() }}</div>
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td><span class="lms-chip {{ $statusChip }}">{{ $statusLabel }}</span></td>
                                        <td>
                                            @if($sub && $sub->marks_awarded !== null)
                                                <strong>{{ $sub->marks_awarded }} / {{ $assignment->max_marks }}</strong>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                @empty
                    @include('partials.patterns.empty_state', [
                        'icon' => 'icon-users',
                        'message' => 'No children are linked to your account yet. Once the school links your child’s record to your account, their assignments will appear here.',
                    ])
                @endforelse
            </div>
        </div>
    </div>
@endsection
