@extends('layouts.master')
@section('page_title', 'Dashboard')
@section('hide_page_header', true)
@section('content')
    {{-- Loop 99: student home — premium dashboard pass, same standard
         as the teacher (Loop 79), accountant (Loop 92) and parent
         (Loop 96) homes. Every number and row is the signed-in
         student's OWN data: attendance reuses HomeController's
         attendanceSummary (the attendance report's computation), fees
         reuse PaymentRepo::getAllMyPR for the current session (the
         fees portal's assembly), assignments reuse the student
         assignment scoping + open rule from AssignmentController.
         No marks/scores here — results stay behind the marksheet
         flow and its publish gate. --}}
    <div class="dashboard-hero mb-4">
        <div>
            <h2 class="mb-1">{{ $greeting }}, {{ Auth::user()->name }} <span aria-hidden="true">👋</span></h2>
            <p class="mb-0 dashboard-muted">
                @if($student_record)
                    {{ optional($student_record->my_class)->name ?: 'Class —' }} &middot; {{ optional($student_record->section)->name ?: 'Section —' }} &middot; Here&rsquo;s your school day at a glance.
                    @if(optional($student_record->section)->whatsapp_link)
                        &middot; <a href="{{ $student_record->section->whatsapp_link }}" target="_blank" rel="noopener" style="color:#25D366; font-weight:600;"><i class="icon-bubbles2 mr-1"></i>Class WhatsApp Group</a>
                    @endif
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
                </span>
            </div>
            <a href="{{ route('tt.mine') }}" class="btn dashboard-primary-action">
                <i class="icon-calendar3 mr-2"></i> My Timetable
            </a>
        </div>
    </div>

    {{-- KPI strip — own figures only --}}
    <div class="stu99-kpis mb-4">
        <div class="dashboard-stat-card dashboard-stat-green">
            <span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-calendar52"></i></span></span>
            <span class="dashboard-stat-copy">
                <span class="dashboard-stat-label">My Attendance</span>
                <strong>{{ $attendance['percent'] !== null ? $attendance['percent'] . '%' : '—' }}</strong>
                <small>{{ $attendance['total'] > 0 ? 'Present or late, of ' . $attendance['total'] . ' marked days' : 'No attendance recorded yet' }}</small>
            </span>
        </div>
        <div class="dashboard-stat-card dashboard-stat-blue">
            <span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-book"></i></span></span>
            <span class="dashboard-stat-copy">
                <span class="dashboard-stat-label">Subjects in My Class</span>
                <strong>{{ $subjects->count() }}</strong>
                <small>{{ $student_record ? 'Subjects taught in ' . (optional($student_record->my_class)->name ?: 'your class') : 'No class assigned yet' }}</small>
            </span>
        </div>
        <a href="{{ route('assignments.index') }}" class="dashboard-stat-card dashboard-stat-orange">
            <span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-clipboard3"></i></span></span>
            <span class="dashboard-stat-copy">
                <span class="dashboard-stat-label">Open Assignments</span>
                <strong>{{ $open_assignments_count }}</strong>
                <small>{{ $open_assignments_count > 0 ? 'Still to submit' : ($open_assignments_submitted > 0 ? 'All caught up — ' . $open_assignments_submitted . ' open, already submitted' : 'Nothing open right now') }}</small>
            </span>
        </a>
        <div class="dashboard-stat-card {{ $fees['has_records'] && $fees['due'] > 0 ? 'dashboard-stat-orange' : 'dashboard-stat-purple' }}">
            <span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-coin-dollar"></i></span></span>
            <span class="dashboard-stat-copy">
                <span class="dashboard-stat-label">Fee Status</span>
                @if(!$fees['has_records'])
                    <strong>No fees yet</strong>
                    <small>No fee records for this session</small>
                @elseif($fees['due'] > 0)
                    <strong>{{ \App\Helpers\Money::inr($fees['due']) }} due</strong>
                    <small>{{ \App\Helpers\Money::inr($fees['paid']) }} paid of {{ \App\Helpers\Money::inr($fees['total']) }} &middot; {{ $fees['pending_records'] }} record{{ $fees['pending_records'] > 1 ? 's' : '' }} pending</small>
                @else
                    <strong>Paid in full</strong>
                    <small>{{ \App\Helpers\Money::inr($fees['total']) }} paid &middot; nothing outstanding</small>
                @endif
            </span>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-8">
            {{-- Today's classes for my class --}}
            <section class="dashboard-panel mb-4">
                <div class="dashboard-panel-heading">
                    <div>
                        <h5 class="mb-1"><i class="icon-calendar3 text-blue mr-1"></i> Today&rsquo;s Classes</h5>
                        <p class="dashboard-muted mb-0">{{ $today_name }} &middot; your class timetable for today</p>
                    </div>
                    <a href="{{ route('tt.mine') }}" class="dashboard-view-all">Full Timetable <i class="icon-arrow-right7"></i></a>
                </div>
                <div class="table-responsive">
                    <table class="table dashboard-table mb-0">
                        <thead><tr><th>Time</th><th>Subject</th><th>Teacher</th><th>Status</th></tr></thead>
                        <tbody>
                        @forelse($today_periods as $period)
                            <tr>
                                <td>{{ $period['time'] }}</td>
                                <td><strong>{{ $period['subject'] }}</strong></td>
                                <td>{{ $period['teacher'] }}</td>
                                <td><span class="dashboard-status stu-status-{{ \Illuminate\Support\Str::slug($period['status']) }}">{{ $period['status'] }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="dashboard-empty">No classes are scheduled for your class today.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            {{-- Assignments due — open work for my class, nearest due first --}}
            <section class="dashboard-panel mb-4">
                <div class="dashboard-panel-heading">
                    <div>
                        <h5 class="mb-1"><i class="icon-clipboard3 text-blue mr-1"></i> Assignments Due</h5>
                        <p class="dashboard-muted mb-0">Open assignments for your class, nearest due first</p>
                    </div>
                    <a href="{{ route('assignments.index') }}" class="dashboard-view-all">All Assignments <i class="icon-arrow-right7"></i></a>
                </div>
                @forelse($open_assignments as $assignment)
                    @php
                        $submitted = $assignment->submissions->isNotEmpty();
                        $due = $assignment->due_at;
                    @endphp
                    <a href="{{ route('assignments.show', $assignment) }}" class="stu99-asg-row">
                        <span class="stu99-asg-copy">
                            <strong>{{ $assignment->title }}</strong>
                            <small>{{ optional($assignment->subject)->name ?: 'Subject —' }}@if(optional($assignment->teacher)->name) &middot; {{ $assignment->teacher->name }}@endif</small>
                        </span>
                        <span class="stu99-asg-side">
                            @if(!$due)
                                <span class="stu99-due-chip">No due date</span>
                            @elseif($due->isPast())
                                <span class="stu99-due-chip stu99-due-overdue">Overdue &middot; {{ $due->format('d M') }}</span>
                            @elseif($due->isToday())
                                <span class="stu99-due-chip stu99-due-soon">Due today</span>
                            @else
                                <span class="stu99-due-chip">Due {{ $due->format('d M') }} &middot; in {{ now()->startOfDay()->diffInDays($due->copy()->startOfDay()) }} day{{ now()->startOfDay()->diffInDays($due->copy()->startOfDay()) > 1 ? 's' : '' }}</span>
                            @endif
                            <span class="dashboard-status {{ $submitted ? 'stu99-badge-done' : 'stu99-badge-todo' }}">{{ $submitted ? 'Submitted' : 'To do' }}</span>
                        </span>
                    </a>
                @empty
                    <div class="dashboard-empty">No open assignments for your class right now — you&rsquo;re all caught up.</div>
                @endforelse
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
            {{-- My ID card (owner order 2026-10-30): the student's
                 basic identity card lives on the dashboard itself —
                 photo (or initials), class, admission number and
                 the details the printed card carries. --}}
            @if($student_record)
            <section class="dashboard-panel mb-4">
                <div class="dashboard-panel-heading">
                    <div>
                        <h5 class="mb-1"><i class="icon-profile text-blue mr-1"></i> My ID Card</h5>
                        <p class="dashboard-muted mb-0">{{ Qs::getSetting('system_name') }}</p>
                    </div>
                    <a href="{{ route('id_card.mine') }}" class="dashboard-view-all">Full Card <i class="icon-arrow-right7"></i></a>
                </div>
                <div class="d-flex align-items-center" style="gap:14px;">
                    <span class="l7-avatar" style="width:64px; height:64px; font-size:24px;" aria-hidden="true">{{ strtoupper(substr(Auth::user()->name, 0, 1)) }}@if(Qs::hasRealPhoto(Auth::user()->photo))<img src="{{ Auth::user()->photo }}" alt="" onerror="this.remove()">@endif</span>
                    <div>
                        <div style="font-weight:600; font-size:16px;">{{ Auth::user()->name }}</div>
                        <div class="dashboard-muted">{{ optional($student_record->my_class)->name }} &middot; Section {{ optional($student_record->section)->name }}</div>
                        <div class="dashboard-muted">Adm No: {{ $student_record->adm_no }}</div>
                    </div>
                </div>
                <table class="table table-sm mt-3 mb-0">
                    <tbody>
                        <tr><td class="text-muted" style="width:42%;">Date of Birth</td><td>{{ Auth::user()->dob ? \Carbon\Carbon::parse(Auth::user()->dob)->format('d M Y') : '—' }}</td></tr>
                        <tr><td class="text-muted">Session</td><td>{{ $student_record->session ?? '—' }}</td></tr>
                        <tr><td class="text-muted">Phone</td><td>{{ Auth::user()->phone ?? '—' }}</td></tr>
                        @if(optional($student_record->section)->whatsapp_link)
                            <tr><td class="text-muted">Class Group</td><td><a href="{{ $student_record->section->whatsapp_link }}" target="_blank" rel="noopener" style="color:#25D366; font-weight:600;"><i class="icon-bubbles2 mr-1"></i>WhatsApp Group</a></td></tr>
                        @endif
                    </tbody>
                </table>
            </section>
            @endif

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

            {{-- Quick actions — real student routes only --}}
            <section class="dashboard-panel dashboard-quick-actions mb-4">
                <div class="dashboard-panel-heading">
                    <div><h5 class="mb-1"><i class="icon-rocket text-blue mr-1"></i> Quick Actions</h5><p class="dashboard-muted mb-0">Jump to your pages.</p></div>
                </div>
                <div class="dashboard-action-grid stu-action-grid">
                    <a href="{{ route('tt.mine') }}"><i class="icon-table2"></i><span>My Timetable</span></a>
                    <a href="{{ route('assignments.index') }}"><i class="icon-file-text2"></i><span>Assignments</span></a>
                    <a href="{{ route('materials.index') }}"><i class="icon-book"></i><span>Study Materials</span></a>
                    <a href="{{ route('marks.year_selector', Qs::hash(Auth::user()->id)) }}"><i class="icon-book2"></i><span>Marksheet</span></a>
                    <a href="{{ route('admit_cards.my') }}"><i class="icon-profile"></i><span>Admit Card</span></a>
                    <a href="{{ route('attendance.my') }}"><i class="icon-calendar3"></i><span>My Attendance</span></a>
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
                    <a href="{{ route('tt.mine') }}" class="stu-reminder-chip">
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
