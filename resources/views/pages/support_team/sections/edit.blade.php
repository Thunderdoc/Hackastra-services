@extends('layouts.master')
@section('page_title', 'Edit Section of '.$s->my_class->name)
@section('content')
    {{-- Workstream BW: rebuilt on the shared form_page pattern
         (Loop 28/39 grid, full width). Was the legacy narrow
         label-column card from the owner's screenshot, with the
         page title repeated as the card heading — the heading is
         dropped, the common header carries the title. Shell only:
         same PUT to sections.update (Loop 112: plain form, post-save lands on the index), same fields,
         bindings and hashed teacher values. --}}
    @component('partials.patterns.form_page', [
        'form_action' => route('sections.update', $s->id),
        'form_method' => 'PUT',
        'back' => route('sections.index'),
        'submit_label' => 'Submit form',
    ])
        <div class="pat-form-section">
            <div class="pat-fields">
                <div class="pat-field">
                    <label for="name">Name <span class="text-danger">*</span></label>
                    <input id="name" name="name" value="{{ $s->name }}" required type="text" class="form-control" placeholder="Name of Class">
                </div>
                <div class="pat-field">
                    <label for="my_class_id">Class</label>
                    <input class="form-control" id="my_class_id" disabled="disabled" type="text" value="{{ $s->my_class->name }}">
                </div>
                <div class="pat-field">
                    <label for="teacher_id">Teacher</label>
                    <select data-placeholder="Select Teacher" class="form-control select-search" name="teacher_id" id="teacher_id">
                        <option value=""></option>
                        @foreach($teachers as $t)
                            <option {{ $s->teacher_id == $t->id ? 'selected' : '' }} value="{{ Qs::hash($t->id) }}">{{ $t->name }}</option>
                        @endforeach
                    </select>
                    @if($s->teacher_id && !($liveTeacherIds ?? collect())->has((int) $s->teacher_id))
                        <small class="form-text text-danger">Current section teacher is no longer an active teacher — choose a replacement.</small>
                    @endif
                </div>
                <div class="pat-field">
                    <label for="stream">Stream (Class 11/12)</label>
                    <select class="form-control select" name="stream" id="stream">
                        <option value="">No stream (general section)</option>
                        @foreach(\App\Support\Streams::ALL as $key => $label)
                            <option {{ $s->stream === $key ? 'selected' : '' }} value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <small class="form-text text-muted">For Class 11/12: the section's stream. Promotion and section timetables use it to pick the section's subjects.</small>
                </div>

                <div class="form-group col-md-6">
                    <label for="capacity">Section capacity (seats)</label>
                    <input type="number" name="capacity" id="capacity" min="0" max="1000" class="form-control" placeholder="Empty = no limit" value="{{ old('capacity', $s->capacity) }}">
                    <small class="form-text text-muted">Students now: {{ \App\Support\SectionCapacity::strength($s->id) }}. When the section fills up, new admissions are automatically placed in the next section with room.</small>
                </div>
            </div>
        </div>
    @endcomponent

    {{-- Section build (2026-10-25): per-section subject teachers.
         Blank = the subject's class teacher teaches this section;
         a chosen teacher teaches that subject in THIS section only.
         Separate form (own PUT) so the section edit above is untouched. --}}
    @if(isset($subjects) && $subjects->count())
    <div class="card mt-3"><div class="card-body">
        <h6 class="font-weight-bold">Subject teachers for this section</h6>
        <p class="text-muted">Leave a subject blank to use its class teacher. A teacher chosen here teaches that subject in {{ $s->name }} only — other sections keep their own teachers.</p>
        <form method="post" action="{{ route('sections.teachers.update', $s->id) }}">
            @csrf @method('PUT')
            <div class="table-responsive"><table class="table table-sm">
                <thead><tr><th>Subject</th><th>Class teacher</th><th>{{ $s->name }} teacher</th></tr></thead>
                <tbody>
                @foreach($subjects as $sub)
                    @php $ov = $overrides[$sub->id] ?? null; $eff = $ov ?? $sub->teacher_id; $effLive = !$eff || ($liveTeacherIds ?? collect())->has((int) $eff); @endphp
                    <tr>
                        <td>{{ $sub->name }} @if(!$effLive)<span class="text-danger"> — No live teacher assigned</span>@elseif(!$sub->teacher_id && !$ov)<span class="text-danger"> — No teacher assigned</span>@endif</td>
                        <td>{{ $sub->teacher->name ?? 'Vacant' }}</td>
                        <td>
                            <select name="teachers[{{ $sub->id }}]" class="form-control select-search" data-placeholder="Use class teacher">
                                <option value=""></option>
                                @foreach($teachers as $t)
                                    <option {{ (int) $ov === (int) $t->id ? 'selected' : '' }} value="{{ Qs::hash($t->id) }}">{{ $t->name }}</option>
                                @endforeach
                            </select>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            @if(isset($staleOverrides) && $staleOverrides->count())
            <div class="alert alert-warning mt-3">
                <strong>Stale overrides:</strong> these subject teachers belong to subjects {{ $s->my_class->name }} does not offer, so they never apply to this section — but they still count in the Overrides total. Tick to remove them with this save:
                @foreach($staleOverrides as $st)
                    <div class="form-check mt-1">
                        <input class="form-check-input" type="checkbox" name="clear_stale[]" value="{{ $st->subject_id }}" id="stale-{{ $st->subject_id }}">
                        <label class="form-check-label" for="stale-{{ $st->subject_id }}">{{ $st->subject_name }} — {{ $st->teacher_name ?? 'unknown teacher' }}</label>
                    </div>
                @endforeach
            </div>
            @endif
            <button type="submit" class="btn btn-primary">Save subject teachers</button>
        </form>
    </div></div>
    @endif
@endsection
