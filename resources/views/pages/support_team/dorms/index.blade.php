@extends('layouts.master')
@section('page_title', 'Manage Dorms')
@section('content')
    {{-- Workstream BW: rebuilt on the shared patterns (Loop 28),
         following the Batch 4 academics template (sections/classes/
         subjects). Was: ONE tabbed legacy card (Manage/Create tabs)
         with the create form in a narrow half-column tab pane and
         the page title repeated as the card heading. Now: list_page
         table + form_page create card below. Create contract
         unchanged — POST dorms.store via .ajax-store, same fields;
         row actions are the same Edit page + confirmDelete flow,
         rendered as buttons instead of a href="#" dropdown. --}}

    @component('partials.patterns.list_page')
        @slot('title')
            <i class="icon-home9 mr-2"></i> Manage Dorms
        @endslot
        @slot('subtitle')
            All dormitories in the school.
        @endslot
        @slot('action')
            <a href="#add-dorm" class="btn btn-primary"><i class="icon-plus2 mr-2"></i> Create New Dorm</a>
        @endslot

        @if($dorms->count())
            <div class="table-responsive">
            <table class="table">
                <thead>
                <tr>
                    <th>S/N</th>
                    <th>Name</th>
                    <th>Description</th>
                    <th>Action</th>
                </tr>
                </thead>
                <tbody>
                @foreach($dorms as $d)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td>{{ $d->name }}</td>
                        <td>{{ $d->description}}</td>
                        <td class="text-center">
                            @if(Qs::userIsTeamSA())
                                <a href="{{ route('dorms.edit', $d->id) }}" class="btn btn-sm btn-primary"><i class="icon-pencil"></i> Edit</a>
                                <button type="button" id="{{ $d->id }}" onclick="confirmDelete(this.id)" class="btn btn-sm btn-danger"><i class="icon-trash"></i> Delete</button>
                                <form method="post" id="item-delete-{{ $d->id }}" action="{{ route('dorms.destroy', $d->id) }}" class="hidden">@csrf @method('delete')</form>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        @else
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-home9',
                'message' => 'No dormitories found yet. Create the first dormitory using the form below.',
            ])
        @endif
    @endcomponent

    <div id="add-dorm">
        @component('partials.patterns.form_page', [
            'form_action' => route('dorms.store'),
            'form_class' => 'ajax-store',
            'submit_label' => 'Submit form',
        ])
            @slot('title')
                <i class="icon-plus2 mr-2"></i> Create New Dorm
            @endslot

            <div class="pat-form-section">
                <div class="pat-fields">
                    <div class="pat-field">
                        <label for="name">Name <span class="text-danger">*</span></label>
                        <input id="name" name="name" value="{{ old('name') }}" required type="text" class="form-control" placeholder="Name of Dormitory">
                    </div>
                    <div class="pat-field">
                        <label for="description">Description</label>
                        <input id="description" name="description" value="{{ old('description') }}" type="text" class="form-control" placeholder="Description of Dormitory">
                    </div>
                </div>
            </div>
        @endcomponent
    </div>
@endsection
