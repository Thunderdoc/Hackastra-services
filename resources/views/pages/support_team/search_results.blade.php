@extends('layouts.master')
@section('page_title', 'Search')
@section('content')
    <div class="dashboard-hero mb-4">
        <div>
            <p class="dashboard-eyebrow mb-1">Global search</p>
            <h2 class="mb-1">Results for &ldquo;{{ $term }}&rdquo;</h2>
            <p class="mb-0 dashboard-muted">Students, staff, classes and payments you are allowed to see.</p>
        </div>
        <a href="{{ route('dashboard') }}" class="btn dashboard-primary-action">Back to dashboard</a>
    </div>

    @if(strlen($term) < 2)
        <div class="dashboard-panel"><p class="dashboard-empty">Type at least 2 characters to search.</p></div>
    @else
        <section class="dashboard-panel mb-4">
            <div class="dashboard-panel-heading"><div><h5 class="mb-1">Students</h5></div></div>
            <div class="table-responsive"><table class="table dashboard-table mb-0">
                <thead><tr><th>Student</th><th>Class</th><th>Section</th><th></th></tr></thead>
                <tbody>
                @forelse($students as $student)
                    <tr>
                        <td><strong>{{ $student->name }}</strong><small>{{ $student->email }}</small></td>
                        <td>{{ optional(optional($student->student_record)->my_class)->name ?: '—' }}</td>
                        <td>{{ optional(optional($student->student_record)->section)->name ?: '—' }}</td>
                        <td>@if($student->student_record)<a href="{{ route('students.show', \App\Helpers\Qs::hash($student->student_record->id)) }}" class="dashboard-view-all">View</a>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="dashboard-empty">No students match.</td></tr>
                @endforelse
                </tbody></table></div>
        </section>

        <section class="dashboard-panel mb-4">
            <div class="dashboard-panel-heading"><div><h5 class="mb-1">Staff</h5></div></div>
            <div class="table-responsive"><table class="table dashboard-table mb-0">
                <thead><tr><th>Name</th><th>Role</th><th>Email</th></tr></thead>
                <tbody>
                @forelse($staff as $member)
                    <tr><td><strong>{{ $member->name }}</strong></td><td>{{ ucwords(str_replace('_', ' ', $member->user_type)) }}</td><td>{{ $member->email }}</td></tr>
                @empty
                    <tr><td colspan="3" class="dashboard-empty">No staff match.</td></tr>
                @endforelse
                </tbody></table></div>
        </section>

        <div class="row">
            <div class="col-xl-6">
                <section class="dashboard-panel mb-4">
                    <div class="dashboard-panel-heading"><div><h5 class="mb-1">Classes</h5></div></div>
                    <div class="table-responsive"><table class="table dashboard-table mb-0">
                        <tbody>
                        @forelse($classes as $class)
                            <tr><td><strong>{{ $class->name }}</strong></td><td><a href="{{ route('students.list', $class->id) }}" class="dashboard-view-all">Students</a></td></tr>
                        @empty
                            <tr><td class="dashboard-empty">No classes match.</td></tr>
                        @endforelse
                        </tbody></table></div>
                </section>
            </div>
            <div class="col-xl-6">
                <section class="dashboard-panel mb-4">
                    <div class="dashboard-panel-heading"><div><h5 class="mb-1">Payments</h5></div></div>
                    <div class="table-responsive"><table class="table dashboard-table mb-0">
                        <tbody>
                        @forelse($payments as $payment)
                            <tr><td><strong>{{ $payment->title }}</strong><small>{{ $payment->ref_no }}</small></td><td>{{ \App\Helpers\Money::inr($payment->amount) }}</td></tr>
                        @empty
                            <tr><td class="dashboard-empty">No payments match.</td></tr>
                        @endforelse
                        </tbody></table></div>
                </section>
            </div>
        </div>
    @endif
@endsection
