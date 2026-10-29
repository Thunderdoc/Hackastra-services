@extends('layouts.master')
@section('page_title', 'Edit Subject - '.$s->name. ' ('.$s->my_class->name.')')
@section('content')
    {{-- Workstream BW: rebuilt on the shared form_page pattern
         (Loop 28/39 grid, full width). Was the legacy narrow
         label-column card with the title repeated in the card
         header — dropped, the common header carries it. Shell
         only: same PUT to subjects.update (Loop 112: plain form, post-save lands on the index), same
         fields, bindings and hashed teacher values. --}}
    @component('partials.patterns.form_page', [
        'form_action' => route('subjects.update', $s->id),
        'form_method' => 'PUT',
        'back' => route('subjects.index'),
        'submit_label' => 'Submit form',
    ])
        <div class="pat-form-section">
            <div class="pat-fields">
                <div class="pat-field">
                    <label for="name">Name <span class="text-danger">*</span></label>
                    <input id="name" name="name" value="{{ $s->name }}" required type="text" class="form-control" placeholder="Name of Subject">
                </div>
                <div class="pat-field">
                    <label for="slug">Short Name</label>
                    <input id="slug" name="slug" value="{{ $s->slug }}" type="text" class="form-control" placeholder="Short Name">
                </div>
                <div class="pat-field">
                    <label for="my_class_id">Class <span class="text-danger">*</span></label>
                    <select required data-placeholder="Select Class" class="form-control select" name="my_class_id" id="my_class_id">
                        @foreach($my_classes as $c)
                            <option {{ $s->my_class_id == $c->id ? 'selected' : '' }} value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-field">
                    <label for="teacher_id">Teacher</label>
                    <select data-placeholder="Select Teacher" class="form-control select-search" name="teacher_id" id="teacher_id">
                        <option value=""></option>
                        @foreach($teachers as $t)
                            <option {{ $s->teacher_id == $t->id ? 'selected' : '' }} value="{{ Qs::hash($t->id) }}">{{ $t->name }}</option>
                        @endforeach
                    </select>
                    <small class="form-text text-muted">Choose the empty option to leave this subject Vacant — it will appear on the Teacher Allocation page.</small>
                </div>
                <div class="pat-field">
                    <label for="periods_per_week">Periods per Week</label>
                    <input id="periods_per_week" name="periods_per_week" value="{{ $s->periods_per_week }}" type="number" min="1" max="40" class="form-control" placeholder="e.g. 5 — blank = not planned yet">
                    <small class="form-text text-muted">The curriculum requirement the staffing and timetable engines plan against.</small>
                </div>
                <div class="pat-field">
                    <label for="subject_type">Type</label>
                    <select class="form-control select" name="subject_type" id="subject_type">
                        <option value="theory" {{ $s->subject_type === 'practical' ? '' : 'selected' }}>Theory</option>
                        <option value="practical" {{ $s->subject_type === 'practical' ? 'selected' : '' }}>Practical / Lab</option>
                    </select>
                </div>
                <div class="pat-field">
                    <label for="needs_double">Double Period</label>
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" name="needs_double" id="needs_double" value="1" {{ $s->needs_double ? 'checked' : '' }}>
                        <label class="form-check-label" for="needs_double">Needs one double (back-to-back) period each week</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" name="needs_lab" id="needs_lab" value="1" {{ $s->needs_lab ? 'checked' : '' }}>
                        <label class="form-check-label" for="needs_lab">Needs a lab room</label>
                    </div>
                </div>
                <div class="pat-field">
                    <label>Streams (Class 11/12)</label>
                    @php $streamTags = $s->stream ? array_map('trim', explode(',', $s->stream)) : []; @endphp
                    @foreach(\App\Support\Streams::ALL as $key => $label)
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="stream_tags[]" id="stream_{{ $key }}" value="{{ $key }}" {{ in_array($key, $streamTags) ? 'checked' : '' }}>
                        <label class="form-check-label" for="stream_{{ $key }}">{{ $label }}</label>
                    </div>
                    @endforeach
                    <small class="form-text text-muted">Leave all blank for a common subject (every stream). Tick streams for a stream subject — Physics and Chemistry belong to both PCM and PCB.</small>
                </div>
            </div>
        </div>
    @endcomponent
@endsection
