@extends('layouts.master')
@section('page_title', 'Assignments')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Home</a> &nbsp;/&nbsp; Assignments
@endsection
@section('content')
    {{-- Workstream CS2 (LMS): teacher/admin assignment list on the
         shared list_page pattern (Loop 28), same shell as Library
         Books. $assignments carries relations + submissions_count +
         graded_count (CS1 contract). Status is derived from due_at:
         future = Open, past = Closed. --}}
    @if(session('flash_success'))
        <div class="alert alert-success border-0">{{ session('flash_success') }}</div>
    @endif
    @if(session('flash_danger'))
        <div class="alert alert-danger border-0">{{ session('flash_danger') }}</div>
    @endif

    @component('partials.patterns.list_page')
        @slot('title')
            <i class="icon-file-text2 mr-2"></i> Assignments
        @endslot
        @slot('subtitle')
            Create assignments, collect submissions online and grade them — the averages can flow straight into internal marks.
        @endslot
        @slot('action')
            <a href="{{ route('assignments.create') }}" class="btn btn-primary"><i class="icon-plus2 mr-1"></i> New Assignment</a>
        @endslot

        <table class="table lms-table">
            <thead>
            <tr>
                <th>Assignment</th>
                <th>Class</th>
                <th>Due</th>
                <th>Status</th>
                <th>Submissions</th>
                <th>Graded</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            @forelse($assignments as $assignment)
                @php
                    $due = $assignment->due_at ? \Carbon\Carbon::parse($assignment->due_at) : null;
                    $isOpen = $due ? $due->isFuture() : true;
                @endphp
                <tr>
                    <td>
                        <a href="{{ route('assignments.show', $assignment->id) }}"><strong>{{ $assignment->title }}</strong></a>
                        <div class="mt-1">
                            <span class="lms-chip lms-chip-subject"><i class="icon-book mr-1"></i>{{ optional($assignment->subject)->name ?? 'Subject —' }}</span>
                            @if($assignment->attachment_path)
                                <span class="lms-chip"><i class="icon-attachment mr-1"></i>File attached</span>
                            @endif
                        </div>
                    </td>
                    <td>
                        {{ optional($assignment->myClass)->name ?? '—' }}
                        <div class="text-muted">{{ $assignment->section ? optional($assignment->section)->name : 'Whole class' }}</div>
                    </td>
                    <td>
                        @if($due)
                            {{ $due->format('d M Y, h:i A') }}
                            <div class="text-muted">{{ $due->diffForHumans() }}</div>
                        @else
                            —
                        @endif
                    </td>
                    <td>
                        @if($isOpen)
                            <span class="lms-chip lms-chip-open">Open</span>
                        @else
                            <span class="lms-chip lms-chip-closed">Closed</span>
                        @endif
                        @if(!$isOpen && $assignment->allow_late)
                            <div class="text-muted mt-1">Late submissions allowed</div>
                        @endif
                    </td>
                    <td>
                        <strong>{{ $assignment->submissions_count ?? 0 }}</strong>@if(isset($assignment->students_count)) / {{ $assignment->students_count }}@endif submitted
                    </td>
                    <td>
                        <strong>{{ $assignment->graded_count ?? 0 }}</strong> graded
                        @if(($assignment->submissions_count ?? 0) > ($assignment->graded_count ?? 0))
                            <div class="text-muted">{{ ($assignment->submissions_count ?? 0) - ($assignment->graded_count ?? 0) }} waiting</div>
                        @endif
                    </td>
                    <td style="white-space:nowrap">
                        <a href="{{ route('assignments.show', $assignment->id) }}" class="btn btn-light btn-sm"><i class="icon-eye mr-1"></i> View</a>
                        <a href="{{ route('assignments.edit', $assignment->id) }}" class="btn btn-light btn-sm"><i class="icon-pencil7 mr-1"></i> Edit</a>
                        <form method="post" action="{{ route('assignments.destroy', $assignment->id) }}" style="display:inline">@csrf @method('delete')<button type="submit" class="btn btn-light btn-sm text-danger" data-confirm="Delete this assignment and all its submissions?" data-confirm-title="Delete Assignment" data-confirm-action="Delete" data-confirm-danger><i class="icon-trash mr-1"></i> Delete</button></form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        @include('partials.patterns.empty_state', [
                            'icon' => 'icon-file-text2',
                            'message' => 'No assignments yet. Create the first one — students will see it in their portal instantly.',
                            'action_url' => route('assignments.create'),
                            'action_label' => 'New Assignment',
                        ])
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
        @if(method_exists($assignments, 'links'))
            {{ $assignments->links() }}
        @endif
    @endcomponent
@endsection
