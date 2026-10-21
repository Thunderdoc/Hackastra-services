@extends('layouts.master')
@section('page_title', 'Manage Classes')
@section('content')
    {{-- Batch 4: rebuilt on the shared patterns (Loop 28). Was: a
         tabbed card with the create form hidden in a tab (58KB
         rendered, 20 dead anchors in the page body). Now: ONE table
         on the list_page pattern + the create form as a form_page
         card below it. Create contract unchanged — POST classes.store
         via .ajax-store, same fields/validation (creating a class
         still auto-creates its default section "A"); row actions are
         the same Edit page + confirmDelete flow, rendered as buttons
         instead of a href="#" dropdown. No href="#" remains on this
         page. --}}
    @component('partials.patterns.list_page')
        @slot('title')
            <i class="icon-windows2 mr-2"></i> Manage Classes
        @endslot
        @slot('subtitle')
            All classes in the school. When a class is created, a default section is created for it automatically.
        @endslot
        @slot('action')
            <a href="#add-class" class="btn btn-primary"><i class="icon-plus2 mr-2"></i> Create New Class</a>
        @endslot

        @if($my_classes->count())
            <table class="table datatable-button-html5-columns">
                <thead>
                <tr>
                    <th>S/N</th>
                    <th>Name</th>
                    <th>Class Type</th>
                    <th>Action</th>
                </tr>
                </thead>
                <tbody>
                @foreach($my_classes as $c)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td>{{ $c->name }}</td>
                        <td>{{ $c->class_type->name }}</td>
                        <td class="text-center">
                            @if(Qs::userIsTeamSA())
                                <a href="{{ route('classes.edit', $c->id) }}" class="btn btn-sm btn-primary"><i class="icon-pencil"></i> Edit</a>
                                <button type="button" id="{{ $c->id }}" onclick="confirmDelete(this.id)" class="btn btn-sm btn-danger"><i class="icon-trash"></i> Delete</button>
                                <form method="post" id="item-delete-{{ $c->id }}" action="{{ route('classes.destroy', $c->id) }}" class="hidden">@csrf @method('delete')</form>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @else
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-windows2',
                'message' => 'No classes found yet. Create the first class using the form below.',
            ])
        @endif
    @endcomponent

    <div id="add-class">
        @component('partials.patterns.form_page', [
            'form_action' => route('classes.store'),
            'form_class' => 'ajax-store',
            'submit_label' => 'Submit form',
        ])
            @slot('title')
                <i class="icon-plus2 mr-2"></i> Create New Class
            @endslot

            <div class="alert alert-info border-0 alert-dismissible">
                <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
                <span>When a class is created, a Section will be automatically created for the class, you can edit it or add more sections to the class at <a target="_blank" href="{{ route('sections.index') }}">Manage Sections</a></span>
            </div>

            <div class="pat-form-section">
                <div class="pat-fields">
                    <div class="pat-field">
                        <label for="name">Name <span class="text-danger">*</span></label>
                        <input id="name" name="name" value="{{ old('name') }}" required type="text" class="form-control" placeholder="Name of Class">
                    </div>
                    <div class="pat-field">
                        <label for="class_type_id">Class Type</label>
                        <select required data-placeholder="Select Class Type" class="form-control select" name="class_type_id" id="class_type_id">
                            @foreach($class_types as $ct)
                                <option {{ old('class_type_id') == $ct->id ? 'selected' : '' }} value="{{ $ct->id }}">{{ $ct->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>
        @endcomponent
    </div>
@endsection
