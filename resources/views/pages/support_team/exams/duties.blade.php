@extends('layouts.master')
@section('page_title', 'Invigilation Duties')
@section('content')

    {{-- Invigilation Duties (Scheduling Engine Slice F): one
         invigilator per date-sheet sitting. The clash rule (never
         two duties at the same date + time) is enforced on the
         server for manual saves and auto-assign alike. Assigning a
         teacher to their own subject's paper works but is flagged,
         because many schools prefer a neutral invigilator. --}}
    @component('partials.patterns.list_page')
        @slot('subtitle')
            Every exam sitting with its invigilator. Auto-assign balances duties across teachers and never double-books anyone at the same date and time.
        @endslot
        @slot('action')
            <a href="{{ route('exam_dates.manage') }}" class="btn btn-light btn-sm"><i class="icon-calendar mr-1"></i> Date Sheet</a>
        @endslot

        <form method="get" action="{{ route('exam_duties.index') }}" class="mb-3">
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

        @if($exam_id && isset($exam))
            <div class="d-flex align-items-center mb-3" style="gap: .5rem; flex-wrap: wrap;">
                <strong>{{ $exam->name }}</strong>
                <span class="text-muted">{{ $sittings->count() }} sitting(s) · {{ $duties->count() }} with an invigilator</span>
                @if(Qs::userIsTeamSAWrite())
                    <form method="post" action="{{ route('exam_duties.auto') }}" class="ml-auto">
                        @csrf
                        <input type="hidden" name="exam_id" value="{{ $exam->id }}">
                        <button type="submit" class="btn btn-success btn-sm"><i class="icon-magic-wand mr-1"></i> Auto-Assign All Empty</button>
                    </form>
                @endif
            </div>

            @if($sittings->isEmpty())
                @include('partials.patterns.empty_state', [
                    'icon' => 'icon-calendar',
                    'message' => 'This exam has no date-sheet sittings yet. Generate or enter the date sheet first, then assign invigilators here.',
                ])
            @else
                <div class="table-responsive">
                    <table class="table">
                        <thead><tr><th>Date</th><th>Time</th><th>Class</th><th>Subject</th><th>Venue</th><th>Invigilator</th><th>Duties (this exam)</th>@if(Qs::userIsTeamSAWrite())<th>Set</th>@endif</tr></thead>
                        <tbody>
                        @foreach($sittings as $sitting)
                            @php $duty = $duties->get($sitting->id); @endphp
                            <tr>
                                <td>{{ \Carbon\Carbon::parse($sitting->exam_date)->format('D, d M Y') }}</td>
                                <td>{{ \Carbon\Carbon::parse($sitting->start_time)->format('g:i A') }}</td>
                                <td>{{ optional($sitting->my_class)->name }}</td>
                                <td>{{ optional($sitting->subject)->name }}</td>
                                <td>{{ $sitting->note ?: '—' }}</td>
                                <td>
                                    @if($duty)
                                        {{ optional($teachers->firstWhere('id', $duty->teacher_id))->name ?? 'Teacher #' . $duty->teacher_id }}
                                        @if(\App\Support\Invigilation::isOwnSubject($duty->teacher_id, $sitting))
                                            <span class="badge badge-warning">Own subject</span>
                                        @endif
                                    @else
                                        <span class="badge badge-danger">Unassigned</span>
                                    @endif
                                </td>
                                <td>{{ $duty ? ($loads->get($duty->teacher_id) ?? 0) : '—' }}</td>
                                @if(Qs::userIsTeamSAWrite())
                                    <td>
                                        <form method="post" action="{{ route('exam_duties.assign') }}" class="d-flex" style="gap:.35rem;">
                                            @csrf
                                            <input type="hidden" name="exam_date_id" value="{{ $sitting->id }}">
                                            <select name="teacher_id" class="form-control form-control-sm" style="min-width: 170px;">
                                                <option value="">Choose…</option>
                                                @foreach($teachers as $t)
                                                    <option value="{{ Qs::hash($t->id) }}" {{ $duty && (int) $duty->teacher_id === (int) $t->id ? 'selected' : '' }}>{{ $t->name }}</option>
                                                @endforeach
                                            </select>
                                            <button type="submit" class="btn btn-primary btn-sm">Save</button>
                                        </form>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endif
    @endcomponent
@endsection
