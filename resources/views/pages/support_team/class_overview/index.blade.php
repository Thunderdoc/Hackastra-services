@extends('layouts.master')
@section('page_title', 'Class Overview')
@section('page_breadcrumb', 'Class Overview')
@section('page_actions')
    <a href="{{ route('dashboard') }}" class="btn btn-default">Back to Dashboard</a>
@endsection
@section('content')
    <div class="panel panel-flat">
        <div class="panel-heading">
            <h5 class="panel-title">Class Overview</h5>
            <p class="text-muted" style="margin: 6px 0 0;">
                Live snapshot from existing records only — nothing here is stored or estimated.
                Strength counts active student records (grad = 0). Attendance is (present + late) / marked
                across the teacher's own period sessions in the class. Marks use the latest exam that has
                marks for the teacher's subjects in the class: each student's figure is the mean of
                (tca + exm) as a percentage of that subject's exam total, and the class average is the
                mean of the student figures. A student is at-risk when their attendance is below 50%
                or their marks are below 40% of the exam total; students with no marked periods are
                never counted as 0% attendance.
                @if($is_admin)
                    As admin you see every class, with all subjects and sessions in each class.
                @else
                    You see only the classes you teach, and only your own subjects and periods in them.
                @endif
            </p>
        </div>
        <div class="panel-body">
            @if($overviews->isEmpty())
                <p class="text-muted">No classes found for this account yet.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Class</th>
                                <th>Subjects</th>
                                <th>Strength</th>
                                <th>Attendance</th>
                                <th>Marks average</th>
                                <th>Topper</th>
                                <th>At-risk</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($overviews as $row)
                                <tr>
                                    <td data-label="Class">{{ $row['class']->name }}</td>
                                    <td data-label="Subjects">{{ $row['subjects']->pluck('name')->implode(', ') }}</td>
                                    <td data-label="Strength">{{ number_format($row['strength']) }}</td>
                                    <td data-label="Attendance">
                                        @if($row['attendance_pct'] === null)
                                            —
                                        @else
                                            {{ number_format($row['attendance_pct'], 1) }}%
                                        @endif
                                    </td>
                                    <td data-label="Marks average">
                                        @if($row['marks_avg'] === null)
                                            —
                                        @else
                                            {{ number_format($row['marks_avg'], 1) }}%
                                            @if($row['exam'])
                                                <small class="text-muted">({{ $row['exam']->name }})</small>
                                            @endif
                                        @endif
                                    </td>
                                    <td data-label="Topper">
                                        @if($row['topper'])
                                            {{ $row['topper']['name'] }} ({{ number_format($row['topper']['percent'], 1) }}%)
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td data-label="At-risk">{{ count($row['at_risk']) }}</td>
                                    <td>
                                        <a href="{{ route('class_overview.index', ['class_id' => $row['class']->id]) }}" class="btn btn-sm btn-primary">View detail</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    @if($detail)
        <div class="panel panel-flat">
            <div class="panel-heading">
                <h5 class="panel-title">{{ $detail['class']->name }} — Detail</h5>
                <p class="text-muted" style="margin: 6px 0 0;">
                    Strength: {{ number_format($detail['strength']) }} students.
                    Attendance:
                    @if($detail['attendance_pct'] === null)
                        no marked periods yet.
                    @else
                        {{ number_format($detail['attendance_pct'], 1) }}% across {{ number_format($detail['attendance_marked']) }} marked entries.
                    @endif
                    Marks:
                    @if($detail['marks_avg'] === null)
                        no marks yet.
                    @else
                        {{ number_format($detail['marks_avg'], 1) }}% average
                        @if($detail['exam'])
                            ({{ $detail['exam']->name }}, {{ $detail['marks_students'] }} students with marks).
                        @endif
                    @endif
                    Topper:
                    @if($detail['topper'])
                        {{ $detail['topper']['name'] }} ({{ number_format($detail['topper']['percent'], 1) }}%).
                    @else
                        none yet.
                    @endif
                </p>
            </div>
            <div class="panel-body">
                <h6>Students needing attention ({{ count($detail['at_risk']) }})</h6>
                @if(empty($detail['at_risk']))
                    <p class="text-muted">No students at risk in this class under the thresholds above.</p>
                @else
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Attendance</th>
                                    <th>Marks</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($detail['at_risk'] as $student)
                                    <tr>
                                        <td data-label="Student">{{ $student['name'] }}</td>
                                        <td data-label="Attendance">
                                            @if($student['attendance_pct'] === null)
                                                —
                                            @else
                                                {{ number_format($student['attendance_pct'], 1) }}%
                                            @endif
                                        </td>
                                        <td data-label="Marks">
                                            @if($student['marks_pct'] === null)
                                                —
                                            @else
                                                {{ number_format($student['marks_pct'], 1) }}%
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    @endif
@endsection
