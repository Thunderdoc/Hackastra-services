@extends('layouts.master')
@section('page_title', 'Manage Dorms')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Hostel &nbsp;/&nbsp; {!! $__env->yieldContent('page_title') !!}
@endsection
@section('content')
    {{-- Workstream BW: rebuilt on the shared patterns (Loop 28),
         following the Batch 4 academics template (sections/classes/
         subjects). Was: ONE tabbed legacy card (Manage/Create tabs)
         with the create form in a narrow half-column tab pane and
         the page title repeated as the card heading. Now: list_page
         table + form_page create card below. Create contract
         POST dorms.store via a plain form (Loop 112: post-save lands on the index), same fields;
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
            @if(Qs::userIsTeamSAWrite())
            <a href="#add-dorm" class="btn btn-primary"><i class="icon-plus2 mr-2"></i> Create New Dorm</a>
            @endif
        @endslot

        @if($dorms->count())
            <div class="table-responsive">
            {{-- Inside-Matter Job 2 (2026-11-08): hk-std standard — colgroup widths sum to 100%. --}}
            <table class="table hk-std hk-std-560 l108-stack">
                <colgroup><col style="width:6%"><col style="width:26%"><col style="width:44%"><col style="width:24%"></colgroup>
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
                        <td data-label="S/N">{{ $loop->iteration }}</td>
                        <td data-label="Name">{{ $d->name }}</td>
                        <td data-label="Description" class="hk-ellip">{{ $d->description}}</td>
                        <td class="text-center" data-label="Action">
                            @if(Qs::userIsTeamSAWrite())
                                <a href="{{ route('dorms.edit', $d->id) }}" class="btn btn-sm btn-primary"><i class="icon-pencil"></i> Edit</a>
                                <button type="button" id="{{ $d->id }}" data-item-name="{{ $d->name }}" onclick="confirmDelete(this.id, this.dataset.itemName)" class="btn btn-sm btn-danger"><i class="icon-trash"></i> Delete</button>
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
        @if(Qs::userIsTeamSAWrite())
        @component('partials.patterns.form_page', [
            'form_action' => route('dorms.store'),
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
        @endif
    </div>
@endsection
