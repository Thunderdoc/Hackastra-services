@extends('layouts.master')
@section('page_title', 'My Assignments')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Home</a> &nbsp;/&nbsp; My Assignments
@endsection
@section('content')
    {{-- Workstream CS2 (LMS): student "My Assignments" — one card per
         assignment with subject, teacher, a live due state and MY
         status (Pending / Submitted / Late / Graded + marks). The
         student's own submission is resolved defensively:
         $assignment->my_submission when CS1 attaches it, else the
         first item of an eager-loaded $assignment->submissions. --}}
    @if(session('flash_success'))
        <div class="alert alert-success border-0">{{ session('flash_success') }}</div>
    @endif
    @if(session('flash_danger'))
        <div class="alert alert-danger border-0">{{ session('flash_danger') }}</div>
    @endif

    <div class="pat-list">
        <div class="pat-toolbar">
            <div class="pat-toolbar-text">
                <div class="pat-toolbar-sub">Assignments from your teachers — submit online before the deadline, and see your marks here once graded.</div>
            </div>
        </div>

        <div class="lms-card-list">
            @forelse($assignments as $assignment)
                @php
                    $due = $assignment->due_at ? \Carbon\Carbon::parse($assignment->due_at) : null;
                    $isPast = $due ? $due->isPast() : false;
                    $sub = $assignment->my_submission ?? (isset($assignment->submissions) ? $assignment->submissions->first() : null);
                    if ($sub && $sub->marks_awarded !== null) {
                        $statusLabel = 'Graded';
                        $statusChip = 'lms-chip-graded';
                    } elseif ($sub && $sub->is_late) {
                        $statusLabel = 'Submitted (Late)';
                        $statusChip = 'lms-chip-late';
                    } elseif ($sub) {
                        $statusLabel = 'Submitted';
                        $statusChip = 'lms-chip-submitted';
                    } elseif ($isPast) {
                        $statusLabel = 'Missed';
                        $statusChip = 'lms-chip-closed';
                    } else {
                        $statusLabel = 'Pending';
                        $statusChip = 'lms-chip-pending';
                    }
                    if (!$due) {
                        $dueLabel = 'No deadline';
                        $dueClass = '';
                    } elseif ($isPast) {
                        $dueLabel = 'Was due ' . $due->diffForHumans();
                        $dueClass = 'lms-due-overdue';
                    } elseif ($due->isToday() || $due->diffInDays(now()) < 2) {
                        $dueLabel = 'Due ' . $due->diffForHumans();
                        $dueClass = 'lms-due-soon';
                    } else {
                        $dueLabel = 'Due ' . $due->diffForHumans();
                        $dueClass = 'lms-due-ok';
                    }
                @endphp
                <div class="card lms-card">
                    <div class="lms-card-main">
                        <div class="mb-1">
                            <span class="lms-chip lms-chip-subject"><i class="icon-book mr-1"></i>{{ optional($assignment->subject)->name ?? 'Subject —' }}</span>
                            <span class="lms-chip {{ $statusChip }}">{{ $statusLabel }}</span>
                            @if($sub && $sub->marks_awarded !== null)
                                <span class="lms-chip lms-chip-marks">{{ $sub->marks_awarded }} / {{ $assignment->max_marks }}</span>
                            @endif
                        </div>
                        <a href="{{ route('assignments.show', $assignment->id) }}" class="lms-card-title">{{ $assignment->title }}</a>
                        <div class="text-muted mt-1">
                            <i class="icon-user mr-1"></i>{{ optional($assignment->teacher)->name ?? 'Teacher' }}
                            @if($assignment->attachment_path)
                                &nbsp;·&nbsp;<i class="icon-attachment mr-1"></i>File attached
                            @endif
                        </div>
                    </div>
                    <div class="lms-card-side">
                        <div class="lms-due {{ $dueClass }}"><i class="icon-alarm mr-1"></i>{{ $dueLabel }}</div>
                        @if($due)<div class="text-muted">{{ $due->format('d M Y, h:i A') }}</div>@endif
                        <a href="{{ route('assignments.show', $assignment->id) }}" class="btn btn-primary btn-sm mt-2"><i class="icon-eye mr-1"></i> View</a>
                    </div>
                </div>
            @empty
                <div class="card pat-card">
                    <div class="card-body">
                        @include('partials.patterns.empty_state', [
                            'icon' => 'icon-file-text2',
                            'message' => 'No assignments yet. When a teacher publishes one for your class, it will appear here.',
                        ])
                    </div>
                </div>
            @endforelse
        </div>

        @if(method_exists($assignments, 'links'))
            {{ $assignments->links() }}
        @endif
    </div>
@endsection
