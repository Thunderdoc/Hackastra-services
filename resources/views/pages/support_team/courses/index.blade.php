@extends('layouts.master')
@section('page_title', 'My Courses')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Learning &nbsp;/&nbsp; My Courses
@endsection
@section('content')

    {{-- LMS courses (2026-11-08, owner-approved sample): Learning
         starts from SUBJECT CARDS, not flat lists. A student sees
         only their own section's subjects; a teacher sees only
         the subject × section units they effectively teach (plus
         today's substitute covers, read-only). --}}
    @if($mode === 'student')
        <p class="ma-intro">Your subjects for {{ optional($sr->my_class)->name }}@if($sr->section) &middot; Section {{ $sr->section->name }}@endif. Open a course to find its notices, materials, assignments and quizzes in one place.</p>

        @if($cards->isEmpty())
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-books',
                'message' => 'No subjects are assigned to your class yet.',
            ])
        @else
            <div class="row course-grid">
                @foreach($cards as $card)
                    @php $color = \App\Support\SubjectColor::forName($card['subject']->name); @endphp
                    <div class="col-sm-6 col-xl-4">
                        <a class="course-card" href="{{ route('courses.show', $card['subject']->id) }}">
                            <span class="course-card-band" style="background: {{ $color }}">
                                <span class="course-card-icon"><i class="icon-book"></i></span>
                                <span class="course-card-name">{{ $card['subject']->name }}</span>
                                @if($card['pinned_notices'] > 0)
                                    <span class="course-card-notice"><i class="icon-pin"></i> {{ $card['pinned_notices'] }} notice{{ $card['pinned_notices'] === 1 ? '' : 's' }}</span>
                                @endif
                            </span>
                            <span class="course-card-body">
                                <span class="course-card-teacher">
                                    <span class="course-avatar" style="background: {{ $color }}">{{ strtoupper(substr($card['teacher'] ?? '—', 0, 1)) }}</span>
                                    {{ $card['teacher'] ?: 'No teacher assigned' }}@if($card['is_lab']) &middot; Lab &middot; Practical @endif
                                </span>
                                <span class="course-card-stats">
                                    <span><strong>{{ $card['materials'] }}</strong> materials</span>
                                    <span class="{{ $card['to_submit'] > 0 ? 'amber' : '' }}"><strong>{{ $card['to_submit'] }}</strong> to submit</span>
                                    <span>{{ $card['next_quiz'] }}</span>
                                </span>
                                <span class="course-card-open">Open course <i class="icon-arrow-right7"></i></span>
                            </span>
                        </a>
                    </div>
                @endforeach
            </div>
        @endif
    @else
        <p class="ma-intro">The courses you teach. Open one to post notices, pin what matters and arrange the order your students see.</p>

        @if($units->isEmpty())
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-books',
                'message' => 'No courses are assigned to you yet. Your courses appear here for every subject and section you teach.',
            ])
        @else
            <div class="row course-grid">
                @foreach($units as $unit)
                    @php $color = \App\Support\SubjectColor::forName($unit['subject']->name); @endphp
                    <div class="col-sm-6 col-xl-4">
                        <a class="course-card" href="{{ route('courses.show', ['subject' => $unit['subject']->id, 'section' => $unit['section']->id ?? null]) }}">
                            <span class="course-card-band" style="background: {{ $color }}">
                                <span class="course-card-icon"><i class="icon-book"></i></span>
                                <span class="course-card-name">{{ $unit['subject']->name }}</span>
                                @if($unit['substitute'])
                                    <span class="course-card-notice"><i class="icon-users"></i> Substitute today</span>
                                @endif
                            </span>
                            <span class="course-card-body">
                                <span class="course-card-teacher">
                                    {{ optional($unit['subject']->my_class)->name }}@if($unit['section']) &middot; Section {{ $unit['section']->name }} @else &middot; All sections @endif
                                </span>
                                <span class="course-card-stats">
                                    <span><strong>{{ $unit['counts']['materials'] }}</strong> materials</span>
                                    <span><strong>{{ $unit['counts']['assignments'] }}</strong> assignments</span>
                                    <span><strong>{{ $unit['counts']['quizzes'] }}</strong> quizzes</span>
                                </span>
                                <span class="course-card-open">{{ $unit['substitute'] ? 'View course (read-only)' : 'Open course' }} <i class="icon-arrow-right7"></i></span>
                            </span>
                        </a>
                    </div>
                @endforeach
            </div>
        @endif
    @endif

@endsection
