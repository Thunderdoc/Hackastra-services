@extends('layouts.master')
@section('page_title', 'Manage Subjects')
@section('content')
    {{-- Batch 4: rebuilt on the shared patterns (Loop 28). Was: one
         stacked table per class under a tab strip (90KB rendered, 41
         dead anchors). Now: a class picker (?class_id=, filtered
         server-side, default = first class) + ONE table, and the
         create form as a form_page card. Create contract unchanged —
         POST subjects.store via .ajax-store, same fields/validation;
         row actions are the same Edit page + confirmDelete flow,
         rendered as buttons instead of a href="#" dropdown. --}}
    @component('partials.patterns.list_page')
        @slot('title')
            <i class="icon-pin mr-2"></i> Manage Subjects
        @endslot
        @slot('subtitle')
            @if($selected_class)
                Showing subjects for <strong>{{ $selected_class->name }}</strong> — pick another class to switch.
            @else
                No classes exist yet.
            @endif
        @endslot
        @slot('action')
            <a href="#add-subject" class="btn btn-primary"><i class="icon-plus2 mr-2"></i> Add Subject</a>
        @endslot

        @slot('filters')
            <form method="get" action="{{ route('subjects.index') }}" class="pat-filter-form">
                <div class="pat-filter-field">
                    <label for="class_id">Class:</label>
                    <select id="class_id" name="class_id" class="form-control select" data-placeholder="Select Class">
                        @foreach($my_classes as $c)
                            <option {{ ($selected_class && $selected_class->id == $c->id) ? 'selected' : '' }} value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-filter-action">
                    <button type="submit" class="btn btn-primary">View Subjects <i class="icon-paperplane ml-2"></i></button>
                </div>
            </form>
        @endslot

        @if($selected_class && $subjects->count())
            <table class="table datatable-button-html5-columns">
                <thead>
                <tr>
                    <th>S/N</th>
                    <th>Name</th>
                    <th>Short Name</th>
                    <th>Class</th>
                    <th>Teacher</th>
                    <th>Action</th>
                </tr>
                </thead>
                <tbody>
                @foreach($subjects as $s)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td>{{ $s->name }}</td>
                        <td>{{ $s->slug }}</td>
                        <td>{{ $s->my_class->name }}</td>
                        <td>{{ $s->teacher->name }}</td>
                        <td class="text-center">
                            @if(Qs::userIsTeamSA())
                                <a href="{{ route('subjects.edit', $s->id) }}" class="btn btn-sm btn-primary"><i class="icon-pencil"></i> Edit</a>
                                <button type="button" id="{{ $s->id }}" onclick="confirmDelete(this.id)" class="btn btn-sm btn-danger"><i class="icon-trash"></i> Delete</button>
                                <form method="post" id="item-delete-{{ $s->id }}" action="{{ route('subjects.destroy', $s->id) }}" class="hidden">@csrf @method('delete')</form>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @else
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-pin',
                'message' => 'No subjects in this class yet. Add the first one with the form below.',
            ])
        @endif
    @endcomponent

    <div id="add-subject">
        @component('partials.patterns.form_page', [
            'form_action' => route('subjects.store'),
            'form_class' => 'ajax-store',
            'submit_label' => 'Submit form',
        ])
            @slot('title')
                <i class="icon-plus2 mr-2"></i> Add Subject
            @endslot

            <div class="pat-form-section">
                <div class="pat-fields">
                    <div class="pat-field">
                        <label for="name">Name <span class="text-danger">*</span></label>
                        <input id="name" name="name" value="{{ old('name') }}" required type="text" class="form-control" placeholder="Name of subject">
                    </div>
                    <div class="pat-field">
                        <label for="slug">Short Name <span class="text-danger">*</span></label>
                        <input id="slug" required name="slug" value="{{ old('slug') }}" type="text" class="form-control" placeholder="Eg. B.Eng">
                    </div>
                    <div class="pat-field">
                        <label for="my_class_id">Select Class <span class="text-danger">*</span></label>
                        <select required data-placeholder="Select Class" class="form-control select" name="my_class_id" id="my_class_id">
                            <option value=""></option>
                            @foreach($my_classes as $c)
                                <option {{ old('my_class_id', $selected_class->id ?? null) == $c->id ? 'selected' : '' }} value="{{ $c->id }}">{{ $c->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="pat-field">
                        <label for="teacher_id">Teacher <span class="text-danger">*</span></label>
                        <select required data-placeholder="Select Teacher" class="form-control select-search" name="teacher_id" id="teacher_id">
                            <option value=""></option>
                            @foreach($teachers as $t)
                                <option {{ old('teacher_id') == Qs::hash($t->id) ? 'selected' : '' }} value="{{ Qs::hash($t->id) }}">{{ $t->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>
        @endcomponent
    </div>
@endsection
