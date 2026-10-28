@extends('layouts.master')
@section('page_title', 'Elective Choices')
@section('content')
    <div class="card">
        <div class="card-header">
            <h5 class="card-title">Elective Choices</h5>
        </div>
        <div class="card-body">
            @if(session('flash_success'))
                <div class="alert alert-success">{{ session('flash_success') }}</div>
            @endif
            @if(session('flash_danger'))
                <div class="alert alert-danger">{{ session('flash_danger') }}</div>
            @endif

            @if($is_admin)
                <p class="text-muted">All student elective choices. Approve or reject a choice; a rejected choice can be picked again by the student.</p>
                @if($choices->isEmpty())
                    <p class="text-muted">No elective choices yet.</p>
                @else
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Class</th>
                                    <th>Section</th>
                                    <th>Subject</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($choices as $choice)
                                    <tr>
                                        <td>{{ $choice->student_record && $choice->student_record->user ? $choice->student_record->user->name : '-' }}</td>
                                        <td>{{ $choice->student_record && $choice->student_record->my_class ? $choice->student_record->my_class->name : '-' }}</td>
                                        <td>{{ $choice->student_record && $choice->student_record->section ? $choice->student_record->section->name : '-' }}</td>
                                        <td>{{ $choice->subject ? $choice->subject->name : '-' }}</td>
                                        <td>{{ ucfirst($choice->status) }}</td>
                                        <td>
                                            @if($choice->status !== 'approved')
                                                <form method="POST" action="{{ route('electives.approve', $choice->id) }}" style="display:inline;">
                                                    @csrf
                                                    <button type="submit" class="btn btn-success btn-sm">Approve</button>
                                                </form>
                                            @endif
                                            @if($choice->status !== 'rejected')
                                                <form method="POST" action="{{ route('electives.reject', $choice->id) }}" style="display:inline;">
                                                    @csrf
                                                    <button type="submit" class="btn btn-danger btn-sm">Reject</button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @elseif(!$eligible)
                <p>Elective choice is only for Class 11 and Class 12 students. Your class{{ $record && $record->my_class ? ' (' . $record->my_class->name . ')' : '' }} does not pick electives yet — your subjects are fixed for your class.</p>
            @else
                <p class="text-muted">Pick your stream subjects for {{ $record->my_class->name }}{{ $record->section ? ' — Section ' . $record->section->name : '' }}. Only subjects offered for your class and stream are listed. A rejected choice can be picked again.</p>
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Subject</th>
                                <th>Your Choice</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($offered as $subject)
                                @php $latest = $latestBySubject->get($subject->id); @endphp
                                <tr>
                                    <td>{{ $subject->name }}</td>
                                    <td>{{ $latest ? ucfirst($latest->status) : 'Not picked' }}</td>
                                    <td>
                                        @if(!$latest || $latest->status === 'rejected')
                                            <form method="POST" action="{{ route('electives.store') }}" style="display:inline;">
                                                @csrf
                                                <input type="hidden" name="subject_id" value="{{ $subject->id }}">
                                                <button type="submit" class="btn btn-primary btn-sm">Pick</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <h5 class="mt-3">Your Choices</h5>
                @if($choices->isEmpty())
                    <p class="text-muted">You have not picked any electives yet.</p>
                @else
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Subject</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($choices as $choice)
                                    <tr>
                                        <td>{{ $choice->subject ? $choice->subject->name : '-' }}</td>
                                        <td>{{ ucfirst($choice->status) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @endif
        </div>
    </div>
@endsection
