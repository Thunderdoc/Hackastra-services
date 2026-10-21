@extends('layouts.master')
@section('page_title', 'Dashboard')
@section('content')
    {{-- Loop 12 (Workstream V): student home — rebuilt to the premium
         dashboard standard. Every number and row is the signed-in
         student's own record; no marks/scores are shown here (results
         stay behind the marksheet flow). --}}
    <div class="dashboard-hero mb-4">
        <div>
            <h2 class="mb-1">{{ $greeting }}, {{ Auth::user()->name }} <span aria-hidden="true">👋</span></h2>
            <p class="mb-0 dashboard-muted">
                @if($student_record)
                    {{ optional($student_record->my_class)->name ?: 'Class —' }} &middot; {{ optional($student_record->section)->name ?: 'Section —' }} &middot; Here&rsquo;s your school day at a glance.
                @else
                    Here&rsquo;s your school day at a glance.
                @endif
            </p>
        </div>
        <div class="dashboard-hero-side">
            <div class="dashboard-hero-date">
                <i class="icon-calendar3"></i>
                <span>
                    <strong>{{ $today->format('l, d F Y') }}</strong>
                    <small>Current Session: {{ $current_session ?: '—' }}</small>
                </span>
            </div>
            <a href="{{ route('tt.index') }}" class="btn dashboard-primary-action">
                <i class="icon-calendar3 mr-2"></i> View Timetable
            </a>
        </div>
    </div>

    {{-- Own summary cards --}}
    <div class="stu-stats-row mb-4">
        <div class="dashboard-stat-card dashboard-stat-blue">
            <span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-book"></i></span></span>
            <span class="dashboard-stat-copy">
                <span class="dashboard-stat-label">Subjects in My Class</span>
                <strong>{{ $subjects->count() }}</strong>
                <small>{{ $student_record ? 'Subjects taught in ' . (optional($student_record->my_class)->name ?: 'your class') : 'No class assigned yet' }}</small>
            </span>
        </div>
        <div class="dashboard-stat-card dashboard-stat-green">
            <span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-calendar52"></i></span></span>
            <span class="dashboard-stat-copy">
                <span class="dashboard-stat-label">My Attendance</span>
                <strong>{{ $attendance['percent'] !== null ? $attendance['percent'] . '%' : '—' }}</strong>
                <small>{{ $attendance['total'] > 0 ? 'Present or late, of ' . $attendance['total'] . ' marked days' : 'No attendance recorded yet' }}</small>
            </span>
        </div>
        <div class="dashboard-stat-card dashboard-stat-orange">
            <span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-clock3"></i></span></span>
            <span class="dashboard-stat-copy">
                <span class="dashboard-stat-label">Today&rsquo;s Periods</span>
                <strong>{{ $today_periods->count() }}</strong>
                <small>{{ $today_name }} &middot; from your class timetable</small>
            </span>
        </div>
        <div class="dashboard-stat-card dashboard-stat-purple">
            <span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-books"></i></span></span>
            <span class="dashboard-stat-copy">
                <span class="dashboard-stat-label">Books Issued to Me</span>
                <strong>{{ $books_issued }}</strong>
                <small>{{ $books_issued > 0 ? 'Currently out from the library' : 'No books out right now' }}</small>
            </span>
        </div>
        <div class="dashboard-stat-card dashboard-stat-blue">
            <span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-file-text2"></i></span></span>
            <span class="dashboard-stat-copy">
                <span class="dashboard-stat-label">Upcoming Exams</span>
                <strong>{{ $upcoming_exam_count }}</strong>
                <small>{{ $next_exam ? 'Next: ' . $next_exam['name'] . ' · ' . \Illuminate\Support\Carbon::parse($next_exam['date'])->format('d M Y') : 'No exam dates scheduled yet' }}</small>
            </span>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-8">
            {{-- Today's periods for my class --}}
            <section class="dashboard-panel mb-4">
                <div class="dashboard-panel-heading">
                    <div>
                        <h5 class="mb-1"><i class="icon-calendar3 text-blue mr-1"></i> Today&rsquo;s Periods</h5>
                        <p class="dashboard-muted mb-0">{{ $today_name }} &middot; your class timetable for today</p>
                    </div>
                    <a href="{{ route('tt.index') }}" class="dashboard-view-all">Full Timetable <i class="icon-arrow-right7"></i></a>
                </div>
                <div class="table-responsive">
                    <table class="table dashboard-table mb-0">
                        <thead><tr><th>Time</th><th>Subject</th><th>Status</th></tr></thead>
                        <tbody>
                        @forelse($today_periods as $period)
                            <tr>
                                <td>{{ $period['time'] }}</td>
                                <td><strong>{{ $period['subject'] }}</strong></td>
                                <td><span class="dashboard-status stu-status-{{ Str::slug($period['status']) }}">{{ $period['status'] }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="dashboard-empty">No periods are scheduled for your class today.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            {{-- My subjects and their teachers --}}
            <section class="dashboard-panel mb-4">
                <div class="dashboard-panel-heading">
                    <div>
                        <h5 class="mb-1"><i class="icon-book text-blue mr-1"></i> My Subjects</h5>
                        <p class="dashboard-muted mb-0">Subjects and teachers for {{ $student_record ? (optional($student_record->my_class)->name ?: 'your class') : 'your class' }}</p>
                    </div>
                </div>
                @forelse($subjects as $subject)
                    <div class="stu-subject-row">
                        <span class="stu-subject-name"><strong>{{ $subject->name }}</strong></span>
                        <span class="stu-subject-teacher"><i class="icon-user-tie"></i> {{ optional($subject->teacher)->name ?: 'Teacher not assigned' }}</span>
                    </div>
                @empty
                    <div class="dashboard-empty">No subjects have been set up for your class yet.</div>
                @endforelse
            </section>
        </div>

        <div class="col-xl-4">
            {{-- Attendance summary --}}
            <section class="dashboard-panel mb-4">
                <div class="dashboard-panel-heading">
                    <div>
                        <h5 class="mb-1"><i class="icon-calendar52 text-blue mr-1"></i> Attendance Summary</h5>
                        <p class="dashboard-muted mb-0">This session, so far</p>
                    </div>
                    <a href="{{ route('attendance.my') }}" class="dashboard-view-all">My Attendance <i class="icon-arrow-right7"></i></a>
                </div>
                @if($attendance['total'] > 0)
                    <div class="stu-attendance-body">
                        <div class="stu-attendance-percent">{{ $attendance['percent'] }}%</div>
                        <div class="stu-attendance-rows">
                            <span><i class="stu-dot stu-dot-present"></i> Present <strong>{{ number_format($attendance['present']) }}</strong></span>
                            <span><i class="stu-dot stu-dot-late"></i> Late <strong>{{ number_format($attendance['late']) }}</strong></span>
                            <span><i class="stu-dot stu-dot-absent"></i> Absent <strong>{{ number_format($attendance['absent']) }}</strong></span>
                        </div>
                    </div>
                @else
                    <div class="dashboard-empty">No attendance has been marked for you yet this session.</div>
                @endif
            </section>

            {{-- Quick actions --}}
            <section class="dashboard-panel dashboard-quick-actions mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-flash2 text-blue mr-1"></i> Quick Actions</h5><p class="dashboard-muted mb-0">Jump to your pages.</p></div>
                    <span class="dashboard-heading-icon"><i class="icon-arrow-right7"></i></span>
                </div>
                <div class="dashboard-action-grid stu-action-grid">
                    <a href="{{ route('attendance.my') }}"><i class="icon-calendar52"></i><span>My Attendance</span></a>
                    <a href="{{ route('admit_cards.my') }}"><i class="icon-file-text2"></i><span>My Admit Card</span></a>
                    <a href="{{ route('tt.index') }}"><i class="icon-calendar3"></i><span>Timetable</span></a>
                    <a href="{{ route('circulars.index') }}"><i class="icon-megaphone"></i><span>Circulars</span></a>
                    <a href="{{ route('my_account') }}"><i class="icon-user-tie"></i><span>My Profile</span></a>
                </div>
            </section>

            {{-- Recent circulars for students --}}
            <section class="dashboard-panel mb-4">
                <div class="dashboard-panel-heading">
                    <div>
                        <h5 class="mb-1"><i class="icon-megaphone text-blue mr-1"></i> Recent Circulars</h5>
                        <p class="dashboard-muted mb-0">Announcements for students</p>
                    </div>
                    <a href="{{ route('circulars.index') }}" class="dashboard-view-all">View All <i class="icon-arrow-right7"></i></a>
                </div>
                @forelse($recent_circulars as $circular)
                    <a href="{{ route('circulars.show', $circular) }}" class="stu-circular-row">
                        <span class="stu-circular-copy">
                            <strong>{{ $circular->title }}</strong>
                            <small>{{ $circular->created_at ? $circular->created_at->format('d M Y') : '' }}</small>
                        </span>
                        <span class="dashboard-status stu-prio-{{ $circular->priority }}">{{ ucfirst($circular->priority) }}</span>
                    </a>
                @empty
                    <div class="dashboard-empty">No circulars have been published for students yet.</div>
                @endforelse
            </section>
        </div>
    </div>

    {{-- Reminders — real records only; the strip hides when there is nothing to remind about --}}
    @php
        $due_soon = $active_loans->filter(function ($loan) {
            return $loan->end_date && $loan->end_date->lte(now()->addDays(7));
        });
    @endphp
    @if($next_exam || $due_soon->isNotEmpty() || $circulars_week_count > 0)
        <section class="dashboard-panel stu-reminders mb-4">
            <div class="dashboard-panel-heading">
                <div><h5 class="mb-1"><i class="icon-bell2 text-blue mr-1"></i> Important Reminders</h5><p class="dashboard-muted mb-0">Worth your attention.</p></div>
            </div>
            <div class="stu-reminder-chips">
                @if($next_exam)
                    <a href="{{ route('tt.index') }}" class="stu-reminder-chip">
                        <i class="icon-file-text2"></i>
                        <span><strong>{{ $next_exam['name'] }}</strong><small>Exam on {{ \Illuminate\Support\Carbon::parse($next_exam['date'])->format('d M Y') }}</small></span>
                    </a>
                @endif
                @foreach($due_soon as $loan)
                    <span class="stu-reminder-chip {{ $loan->isOverdue() ? 'stu-reminder-overdue' : '' }}">
                        <i class="icon-books"></i>
                        <span><strong>{{ optional($loan->book)->name ?: 'Library book' }}</strong><small>{{ $loan->isOverdue() ? 'Overdue — was due ' : 'Due back ' }}{{ $loan->end_date->format('d M Y') }}</small></span>
                    </span>
                @endforeach
                @if($circulars_week_count > 0)
                    <a href="{{ route('circulars.index') }}" class="stu-reminder-chip">
                        <i class="icon-megaphone"></i>
                        <span><strong>{{ $circulars_week_count }} new circular{{ $circulars_week_count > 1 ? 's' : '' }}</strong><small>Published in the last 7 days</small></span>
                    </a>
                @endif
            </div>
        </section>
    @endif
@endsection
