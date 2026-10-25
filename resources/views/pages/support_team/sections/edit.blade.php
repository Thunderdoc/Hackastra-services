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
                    @php $ov = $overrides[$sub->id] ?? null; @endphp
                    <tr>
                        <td>{{ $sub->name }}</td>
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
            <button type="submit" class="btn btn-primary">Save subject teachers</button>
        </form>
    </div></div>
    @endif
@endsection
