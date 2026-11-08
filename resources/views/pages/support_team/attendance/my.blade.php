@extends('layouts.master')
@section('page_title', 'My Attendance')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Attendance &nbsp;/&nbsp; {!! $__env->yieldContent('page_title') !!}
@endsection
@section('content')
    {{-- LMS build (2026-11-08, owner-approved sample): the raw
         month input is GONE — the month cards below ARE the
         picker. By-Subject follows the college pattern the owner
         picked: Conducted | Attended | %, and every subject row
         expands into its real date-wise register. An absence an
         approved leave covers wears the dashed "Absent · Leave
         approved" tag (display only — totals follow the settled
         (present + late) / marked rule, like every other surface). --}}
    <div class="pat-list">
        <div class="card pat-card">
        <div class="card-body">
            @if(!$student)
                @include('partials.patterns.empty_state', [
                    'icon' => 'icon-users',
                    'message' => $is_parent
                        ? 'No student record is linked to your account yet. Please contact the school office so they can link your child to your account.'
                        : 'No student record is linked to your account yet. Please contact the school office.',
                ])
            @else
                @if($students->count() > 1)
                    <form method="GET" action="{{ route('attendance.my') }}" class="pat-filter-form mb-3">
                        <div class="pat-filter-field">
                            <label for="att_my_student">Child</label>
                            <select id="att_my_student" name="student_id" class="form-control" onchange="this.form.submit()">
                                @foreach($students as $s)
                                    <option value="{{ $s->id }}" {{ $student->id === $s->id ? 'selected' : '' }}>{{ optional($s->user)->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="pat-filter-action">
                            <span class="text-muted">{{ optional($student->my_class)->name }}@if($student->section) &middot; {{ $student->section->name }}@endif</span>
                        </div>
                    </form>
                @endif

                {{-- Month cards = the picker (sample contract). --}}
                <div class="mw-monthcards" role="navigation" aria-label="Pick a month">
                    <a class="mw-monthcard {{ $view === 'session' ? 'active' : '' }}" href="{{ route('attendance.my', ['student_id' => $student->id]) }}">
                        <span class="mw-monthcard-top"><strong>Whole session</strong>@if($view === 'session')<span class="mw-showing">Showing</span>@endif</span>
                        <span class="mw-monthcard-sub">{{ $months->sum('marked') }} periods marked</span>
                    </a>
                    @foreach($months as $m)
                        <a class="mw-monthcard {{ $view === 'month' && $month->format('Y-m') === $m['key'] ? 'active' : '' }}" href="{{ route('attendance.my', ['month' => $m['key'], 'student_id' => $student->id]) }}">
                            <span class="mw-monthcard-top"><strong>{{ $m['label'] }}</strong>@if($view === 'month' && $month->format('Y-m') === $m['key'])<span class="mw-showing">Showing</span>@endif</span>
                            <span class="mw-monthcard-sub">{{ $m['percent'] === null ? '—' : $m['percent'] . '%' }} &middot; {{ $m['marked'] }} periods</span>
                            @if($m['percent'] !== null)
                                <span class="mw-minibar"><span style="width: {{ $m['percent'] }}%"></span></span>
                            @endif
                        </a>
                    @endforeach
                </div>

                @if($entries->isEmpty())
                    @include('partials.patterns.empty_state', [
                        'icon' => 'icon-calendar2',
                        'message' => $view === 'session' ? 'No period attendance has been recorded yet this session.' : 'No period attendance was recorded in ' . $month->format('F Y') . '.',
                    ])
                @else
                    @include('pages.support_team.attendance.partials.summary_block', ['mw_uid' => 'att'])
                @endif
            @endif
        </div>
        </div>
    </div>
@endsection
