@extends('layouts.master')
@section('page_title', 'Dashboard')
@section('content')
@if(Qs::userIsTeamSAT())
    <div class="dashboard-hero mb-4">
        <div>
            <p class="dashboard-eyebrow mb-1">School overview</p>
            <h2 class="mb-1">Good morning, {{ Auth::user()->name }} <span aria-hidden="true">👋</span></h2>
            <p class="mb-0 dashboard-muted">Here is what is happening across {{ Qs::getSystemName() }} today.</p>
        </div>
        <a href="{{ route('students.create') }}" class="btn dashboard-primary-action">
            <i class="icon-plus2 mr-2"></i> Add student
        </a>
    </div>

    <div class="row dashboard-stats">
        @foreach([
            ['label' => 'Total students', 'value' => $student_count, 'icon' => 'icon-users4', 'tone' => 'blue', 'link' => route('students.list', optional(App\Models\MyClass::first())->id)],
            ['label' => 'Teaching staff', 'value' => $teacher_count, 'icon' => 'icon-user-tie', 'tone' => 'green', 'link' => route('users.index')],
            ['label' => 'Administrators', 'value' => $administrator_count, 'icon' => 'icon-shield-check', 'tone' => 'orange', 'link' => route('users.index')],
            ['label' => 'Parents', 'value' => $parent_count, 'icon' => 'icon-user', 'tone' => 'purple', 'link' => route('users.index')],
        ] as $stat)
            <div class="col-sm-6 col-xl-3">
                <a href="{{ $stat['link'] }}" class="dashboard-stat-card dashboard-stat-{{ $stat['tone'] }}">
                    <span class="dashboard-stat-icon"><i class="{{ $stat['icon'] }}"></i></span>
                    <span class="dashboard-stat-copy">
                        <span class="dashboard-stat-label">{{ $stat['label'] }}</span>
                        <strong>{{ number_format($stat['value']) }}</strong>
                        <small>View details <i class="icon-arrow-right7"></i></small>
                    </span>
                </a>
            </div>
        @endforeach
    </div>

    <section class="dashboard-panel dashboard-quick-actions mb-4">
        <div class="dashboard-panel-heading">
            <div><h5 class="mb-1">Quick actions</h5><p class="dashboard-muted mb-0">Perform common administrative tasks quickly.</p></div>
            <span class="dashboard-heading-icon"><i class="icon-flash2"></i></span>
        </div>
        <div class="dashboard-action-grid">
            <a href="{{ route('students.create') }}"><i class="icon-user-plus"></i><span>Add student</span></a>
            <a href="{{ route('users.create') }}"><i class="icon-user-tie"></i><span>Add teacher</span></a>
            <a href="{{ route('classes.index') }}"><i class="icon-windows2"></i><span>Manage classes</span></a>
            <a href="{{ route('exams.create') }}"><i class="icon-book"></i><span>Create exam</span></a>
            <a href="{{ route('payments.create') }}"><i class="icon-wallet"></i><span>Record payment</span></a>
            <a href="{{ route('marks.index') }}"><i class="icon-stats-bars"></i><span>View marks</span></a>
            <a href="{{ route('settings') }}"><i class="icon-cog5"></i><span>School settings</span></a>
        </div>
    </section>

    <div class="row">
        <div class="col-xl-5">
            <section class="dashboard-panel mb-4">
                <div class="dashboard-panel-heading">
                    <div>
                        <h5 class="mb-1">Student enrollment</h5>
                        <p class="dashboard-muted mb-0">Active students over the last six months</p>
                    </div>
                    <span class="dashboard-period">{{ now()->format('Y') }}</span>
                </div>
                <div class="dashboard-chart" aria-label="Student enrollment chart">
                    @php($maxEnrollment = max(1, $enrollment_trend->max('value')))
                    @foreach($enrollment_trend as $point)
                        <div class="dashboard-chart-column">
                            <span class="dashboard-chart-value">{{ $point['value'] }}</span>
                            <div class="dashboard-chart-bar" style="height: {{ max(8, ($point['value'] / $maxEnrollment) * 100) }}%"></div>
                            <small>{{ $point['label'] }}</small>
                        </div>
                    @endforeach
                </div>
            </section>
        </div>
        <div class="col-xl-4">
            <section class="dashboard-panel mb-4">
                <div class="dashboard-panel-heading">
                    <div>
                        <h5 class="mb-1">Fee collection overview</h5>
                        <p class="dashboard-muted mb-0">Current recorded payment activity</p>
                    </div>
                    <i class="icon-wallet dashboard-heading-icon"></i>
                </div>
                <div class="dashboard-fee-summary">
                    <div class="dashboard-fee-ring" style="--fee-progress: {{ $payment_total > 0 ? min(100, ($payment_collected / $payment_total) * 100) : 0 }}%">
                        <strong>{{ $payment_total > 0 ? round(($payment_collected / $payment_total) * 100) : 0 }}%</strong>
                        <small>Collected</small>
                    </div>
                    <dl class="dashboard-fee-list mb-0">
                        <div><dt><span class="dashboard-dot dot-blue"></span>Total fees</dt><dd>{{ number_format($payment_total) }}</dd></div>
                        <div><dt><span class="dashboard-dot dot-green"></span>Collected</dt><dd>{{ number_format($payment_collected) }}</dd></div>
                        <div><dt><span class="dashboard-dot dot-orange"></span>Pending</dt><dd>{{ number_format($payment_pending) }}</dd></div>
                    </dl>
                </div>
            </section>
        </div>
        <div class="col-xl-3">
            <section class="dashboard-panel dashboard-attendance mb-4">
                <div class="dashboard-panel-heading"><div><h5 class="mb-1">Today's attendance</h5><p class="dashboard-muted mb-0">No attendance module configured</p></div><i class="icon-calendar52 dashboard-heading-icon"></i></div>
                <div class="attendance-empty"><div class="attendance-ring"><strong>—</strong><small>Not set</small></div><p>Connect attendance records to see daily presence here.</p></div>
            </section>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-5">
            <section class="dashboard-panel mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1">Recent students</h5><p class="dashboard-muted mb-0">Latest admissions and active enrollments</p></div>
                    <a href="{{ route('students.list', optional(App\Models\MyClass::first())->id) }}" class="dashboard-view-all">View all <i class="icon-arrow-right7"></i></a>
                </div>
                <div class="table-responsive">
                    <table class="table dashboard-table mb-0">
                        <thead><tr><th>Student</th><th>Class</th><th>Section</th><th>Status</th></tr></thead>
                        <tbody>
                        @forelse($recent_students as $student)
                            <tr>
                                <td><strong>{{ optional($student->user)->name }}</strong><small>{{ $student->adm_no ?: 'No admission number' }}</small></td>
                                <td>{{ optional($student->my_class)->name ?: '—' }}</td>
                                <td>{{ optional($student->section)->name ?: '—' }}</td>
                                <td><span class="dashboard-status status-success">Active</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="dashboard-empty">No students have been enrolled yet.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
        <div class="col-xl-4">
            <section class="dashboard-panel mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1">Recent payments</h5><p class="dashboard-muted mb-0">Latest payment records</p></div>
                    @if(Route::has('payments.manage'))<a href="{{ route('payments.manage') }}" class="dashboard-view-all">View all <i class="icon-arrow-right7"></i></a>@endif
                </div>
                <div class="table-responsive">
                    <table class="table dashboard-table mb-0">
                        <thead><tr><th>Student</th><th>Amount</th><th>Status</th></tr></thead>
                        <tbody>
                        @forelse($recent_payments as $record)
                            <tr>
                                <td><strong>{{ optional($record->student)->name }}</strong><small>{{ optional($record->payment)->title }}</small></td>
                                <td>{{ number_format($record->amt_paid ?: 0) }}</td>
                                <td><span class="dashboard-status {{ $record->paid ? 'status-success' : 'status-warning' }}">{{ $record->paid ? 'Paid' : 'Pending' }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="dashboard-empty">No payment records yet.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
        <div class="col-xl-3">
            <section class="dashboard-panel dashboard-upcoming mb-4">
                <div class="dashboard-panel-heading"><div><h5 class="mb-1">Upcoming events</h5><p class="dashboard-muted mb-0">No events configured</p></div><i class="icon-calendar3 dashboard-heading-icon"></i></div>
                <div class="dashboard-empty dashboard-event-empty">Add timetable or notice events to display them here.</div>
            </section>
        </div>
    </div>

    <section class="dashboard-panel dashboard-insights mb-4">
        <div class="dashboard-panel-heading"><div><h5 class="mb-1">Smart insights</h5><p class="dashboard-muted mb-0">Data-driven signals from your school data</p></div><i class="icon-bulb2 dashboard-heading-icon"></i></div>
        <div class="dashboard-insight-grid">
            <div><i class="icon-stats-growth text-blue"></i><span><strong>{{ $student_count }} active students</strong><small>Current enrollment count</small></span></div>
            <div><i class="icon-wallet text-green"></i><span><strong>{{ $payment_pending > 0 ? number_format($payment_pending) . ' pending' : 'All fees collected' }}</strong><small>Fee collection status</small></span></div>
            <div><i class="icon-calendar52 text-orange"></i><span><strong>Attendance unavailable</strong><small>No attendance records configured</small></span></div>
            <div><i class="icon-shield-check text-green"></i><span><strong>System is running smoothly</strong><small>Application and database are operational</small></span></div>
        </div>
    </section>
@else
    <div class="dashboard-panel"><h5>Welcome back, {{ Auth::user()->name }}</h5><p class="dashboard-muted mb-0">Use the navigation to access your school workspace.</p></div>
@endif
@endsection
