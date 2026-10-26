@extends('layouts.master')
@section('page_title', 'Teacher Eligibility')
@section('content')

    {{-- Teacher Eligibility (Scheduling Engine Slice A): the
         subjects each teacher is QUALIFIED to teach. This is the
         pool the staffing dashboard counts as capacity and the
         allocation engine may assign from. It is deliberately
         separate from the current assignment on each subject —
         changing eligibility never moves a class; it only changes
         what the engines may plan next. --}}
    @component('partials.patterns.list_page')
        @slot('subtitle')
            Who can teach what. Eligibility is a teacher's qualification, not their current classes — the staffing and allocation engines plan only from this list (plus subjects a teacher already teaches).
        @endslot
        @slot('action')
            <a href="{{ route('subjects.index') }}" class="btn btn-light btn-sm"><i class="icon-pin mr-1"></i> Subjects</a>
        @endslot

        @if($teachers->isEmpty())
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-user-check',
                'message' => 'No teachers yet. Add teachers first, then record what each of them is qualified to teach.',
            ])
        @else
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>#</th><th>Teacher</th><th>Qualified To Teach</th><th>Currently Teaching</th><th>Action</th></tr></thead>
                    <tbody>
                    @foreach($teachers as $i => $t)
                        <tr>
                            <td>{{ $i + 1 }}</td>
                            <td>{{ $t->name }}</td>
                            <td>
                                @forelse($eligibility->get($t->id, []) as $name)
                                    <span class="badge badge-info">{{ $name }}</span>
                                @empty
                                    <span class="text-muted">Not recorded</span>
                                @endforelse
                            </td>
                            <td>
                                @forelse($assigned->get($t->id, []) as $name)
                                    <span class="badge badge-secondary">{{ $name }}</span>
                                @empty
                                    <span class="text-muted">—</span>
                                @endforelse
                            </td>
                            <td><a href="{{ route('planning.eligibility', ['teacher' => Qs::hash($t->id)]) }}" class="btn btn-light btn-sm">Edit Eligibility</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            @if($selected_teacher)
                <div class="pat-form-section" id="eligibility-form" style="margin-top: 1.5rem; scroll-margin-top: 70px;">
                    <div class="pat-form-section-title">Eligibility — {{ $selected_teacher->name }}</div>
                    <div class="pat-form-section-sub">Tick every subject this teacher is qualified to teach, in any class. Saving replaces the previous list.</div>
                    <form method="post" action="{{ route('planning.eligibility.save') }}">
                        @csrf
                        <input type="hidden" name="teacher_id" value="{{ Qs::hash($selected_teacher->id) }}">
                        <div class="pat-fields">
                            @foreach($subject_names as $name)
                                <div class="form-check" style="min-width: 220px; margin-bottom: .5rem;">
                                    <input class="form-check-input" type="checkbox" name="subjects[]" id="elig-{{ $loop->index }}" value="{{ $name }}"
                                        {{ in_array($name, $eligibility->get($selected_teacher->id, []), true) ? 'checked' : '' }}>
                                    <label class="form-check-label" for="elig-{{ $loop->index }}">{{ $name }}</label>
                                </div>
                            @endforeach
                        </div>
                        <button type="submit" class="btn btn-primary">Save Eligibility</button>
                        <a href="{{ route('planning.eligibility') }}" class="btn btn-light">Cancel</a>
                    </form>
                </div>
                {{-- Batch fix 2026-10-25: Edit links land here — the
                     form sits below a long table, so without this
                     the click looks dead (page reloads at top). --}}
                <script>
                    (function () {
                        var el = document.getElementById('eligibility-form');
                        if (el) { el.scrollIntoView({ block: 'start' }); }
                    })();
                </script>
            @endif
        @endif
    @endcomponent
@endsection
