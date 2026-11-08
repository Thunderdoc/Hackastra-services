@extends('layouts.master')
@section('page_title', 'Exam Seating')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Examinations &nbsp;/&nbsp; <a href="{{ route('exams.index') }}">Manage Exams</a> &nbsp;/&nbsp; {!! $__env->yieldContent('page_title') !!}
@endsection
@section('content')

    {{-- F3 — Exam Seating (design §4): one plan per exam slot
         (date + start time from the date sheet). The Admin picks
         an exam, then generates each slot's plan into the school's
         halls; capacity is a hard limit and "no rooms" is a
         first-class blocking state, never a silent failure. --}}
    @component('partials.patterns.list_page')
        @slot('subtitle')
            Seat every student of a sitting into the school's halls — deterministically, never over a hall's capacity. Generate a slot, review it, submit it, and the Principal publishes it.
        @endslot
        @slot('action')
            <a href="{{ route('exam_dates.manage') }}" class="btn btn-light btn-sm"><i class="icon-calendar mr-1"></i> Date Sheet</a>
            <a href="{{ route('rooms.index') }}" class="btn btn-light btn-sm"><i class="icon-office mr-1"></i> Rooms</a>
        @endslot

        <form method="get" action="{{ route('exam_seating.index') }}" class="mb-3">
            <div class="pat-fields">
                <div class="pat-field">
                    <label for="exam_id">Exam</label>
                    <select name="exam_id" id="exam_id" class="form-control select" onchange="this.form.submit()">
                        <option value="">Choose an exam…</option>
                        @foreach($exams as $ex)
                            <option value="{{ $ex->id }}" {{ (string) $exam_id === (string) $ex->id ? 'selected' : '' }}>{{ $ex->name }} ({{ $ex->year }})</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </form>

        @if($exam_id && isset($exam) && $exam)
            @if($no_rooms)
                <div class="alert alert-danger border-0">
                    <strong>No rooms are defined yet.</strong>
                    A seating plan seats students into physical halls, and this school has none on record.
                    Add the exam halls on the <a href="{{ route('rooms.index') }}">Rooms page</a> first — no plan can be generated until then.
                </div>
            @endif

            @if(empty($slots))
                <div class="alert alert-info border-0">
                    This exam has no date sheet yet, so there are no sittings to seat.
                    Set the paper dates on the <a href="{{ route('exam_dates.manage') }}">Date Sheet page</a> first.
                </div>
            @else
                <div class="table-responsive">
                    <table class="table hk-std hk-std-seating">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Time</th>
                                <th>Sittings</th>
                                <th>Students</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($slots as $slot)
                                <tr>
                                    <td>{{ \Carbon\Carbon::parse($slot['exam_date'])->format('D, d M Y') }}</td>
                                    <td>{{ $slot['time_key'] !== '' ? \Carbon\Carbon::parse($slot['time_key'])->format('h:i A') : '—' }}</td>
                                    <td>
                                        @foreach($slot['sittings'] as $sitting)
                                            <div>{{ $sitting->my_class->name ?? '' }} — {{ $sitting->subject->name ?? '' }}</div>
                                        @endforeach
                                    </td>
                                    <td>{{ $slot['students'] }}</td>
                                    <td>
                                        @if($slot['plan'])
                                            @if($slot['plan']->status === 'published')
                                                <span class="badge badge-success">Published</span>
                                            @elseif($slot['plan']->status === 'submitted')
                                                <span class="badge badge-warning">Awaiting Principal</span>
                                            @else
                                                <span class="badge badge-secondary">Draft</span>
                                            @endif
                                            @if($slot['stale'])
                                                <span class="badge badge-danger">Changed since generation</span>
                                            @endif
                                        @else
                                            <span class="text-muted">Not generated</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($slot['plan'])
                                            <a href="{{ route('exam_seating.show', $slot['plan']->id) }}" class="btn btn-light btn-sm">View Plan</a>
                                        @endif
                                        @if(!empty($slot['lock']))
                                            {{-- ONE-TIME GENERATION lock: no Generate
                                                 control on a locked slot — the lock
                                                 note says why and where the key is. --}}
                                            <span class="text-muted"><i class="icon-lock mr-1"></i>{{ $slot['lock'] }}</span>
                                        @elseif(Qs::userIsTeamSAWrite() && !$no_rooms)
                                            <button type="button" class="btn btn-primary btn-sm" data-toggle="collapse" data-target="#gen-{{ $loop->index }}">Generate</button>
                                        @endif
                                    </td>
                                </tr>
                                @if(empty($slot['lock']) && Qs::userIsTeamSAWrite() && !$no_rooms)
                                    <tr class="collapse" id="gen-{{ $loop->index }}">
                                        <td colspan="6">
                                            <form method="post" action="{{ route('exam_seating.generate') }}">
                                                @csrf
                                                <input type="hidden" name="exam_id" value="{{ $exam->id }}">
                                                <input type="hidden" name="exam_date" value="{{ $slot['exam_date'] }}">
                                                <input type="hidden" name="time_key" value="{{ $slot['time_key'] }}">
                                                <div class="mb-2"><strong>Halls for this slot</strong> <span class="text-muted">(classrooms pre-ticked; labs are opt-in)</span></div>
                                                <div class="d-flex flex-wrap" style="gap:.75rem 1.25rem;">
                                                    @foreach($rooms as $room)
                                                        <label class="mb-0">
                                                            <input type="checkbox" name="room_ids[]" value="{{ $room->id }}" {{ $room->kind === 'classroom' && $room->capacity > 0 ? 'checked' : '' }}>
                                                            {{ $room->name }} ({{ $room->capacity }} seats{{ $room->kind === 'lab' ? ', lab' : '' }})
                                                        </label>
                                                    @endforeach
                                                </div>
                                                <div class="mt-2">
                                                    <label class="mb-0 mr-3"><input type="radio" name="mode" value="separate" checked> Separate — each class kept together</label>
                                                    <label class="mb-0"><input type="radio" name="mode" value="mixed"> Mixed — classes interleaved across halls</label>
                                                </div>
                                                <button type="submit" class="btn btn-success btn-sm mt-2">{{ $slot['plan'] ? 'Regenerate Plan' : 'Generate Plan' }}</button>
                                                @if($slot['plan'])
                                                    <span class="text-muted ml-2">Regenerating replaces the current snapshot and returns the plan to Draft.</span>
                                                @endif
                                            </form>
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endif
    @endcomponent
@endsection
