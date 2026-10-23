@extends('layouts.master')
@section('page_title', 'Teacher Allocation')
@section('content')

    {{-- Teacher Allocation (Scheduling Engine Slice C): every
         class-subject that needs attention — unassigned, or its
         teacher over the weekly cap — with the engine's
         recommendation and the reason for it. Only ELIGIBLE
         teachers are ever proposed or accepted (server-side,
         hard rule). Crossing the weekly cap needs an explicit
         override with a reason; both go to the audit log. --}}
    @component('partials.patterns.list_page')
        @slot('subtitle')
            Unassigned subjects and overloaded teachers, with a recommended fix for each. Only eligible teachers are proposed — weekly cap: {{ $cap }} periods.
        @endslot
        @slot('action')
            <a href="{{ route('planning.staffing') }}" class="btn btn-light btn-sm"><i class="icon-stats-bars mr-1"></i> Staffing Dashboard</a>
            <a href="{{ route('planning.eligibility') }}" class="btn btn-light btn-sm"><i class="icon-user-check mr-1"></i> Teacher Eligibility</a>
        @endslot

        @if(empty($rows))
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-checkmark-circle',
                'message' => 'Nothing needs attention — every class-subject has a teacher and nobody is over the weekly cap.',
            ])
        @else
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Class — Subject</th><th>Needs / Wk</th><th>Current Teacher</th><th>Issue</th><th>Recommendation</th><th>Why</th><th>Apply</th></tr></thead>
                    <tbody>
                    @foreach($rows as $row)
                        @php $s = $row['subject']; $rec = $row['recommended_id'] ? $teachers->get($row['recommended_id']) : null; @endphp
                        <tr>
                            <td>{{ $s->my_class->name }} — {{ $s->name }}</td>
                            <td>{{ $s->periods_per_week ?: 'Not set' }}</td>
                            <td>{{ $s->teacher ? $s->teacher->name . ' (' . ($row['loads'][$s->teacher_id] ?? 0) . ' periods)' : '—' }}</td>
                            <td>
                                @if($row['issue'] === 'unassigned')<span class="badge badge-warning">No teacher</span>
                                @else<span class="badge badge-danger">Teacher over cap</span>@endif
                            </td>
                            <td>{{ $rec ? $rec->name . ' (' . ($row['loads'][$rec->id] ?? 0) . ' periods)' : '—' }}</td>
                            <td style="max-width: 320px;">{{ $row['reason'] }}</td>
                            <td>
                                @if($rec)
                                    <form method="post" action="{{ route('planning.allocation.apply') }}" style="display:inline">
                                        @csrf
                                        <input type="hidden" name="subject_id" value="{{ Qs::hash($s->id) }}">
                                        <input type="hidden" name="teacher_id" value="{{ Qs::hash($rec->id) }}">
                                        <button type="submit" class="btn btn-primary btn-sm">Apply</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <p class="text-muted" style="margin-top: .75rem;">Assignments made on the Subjects page follow the same eligibility rule: once any eligibility is recorded for a subject, only eligible teachers can be assigned to it anywhere in the app.</p>
        @endif
    @endcomponent
@endsection
