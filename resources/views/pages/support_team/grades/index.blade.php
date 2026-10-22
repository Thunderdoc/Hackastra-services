@extends('layouts.master')
@section('page_title', 'Manage Grades')
@section('content')
    {{-- Workstream BW: rebuilt on the shared patterns (Loop 28),
         following the Batch 4 academics template (sections/classes/
         subjects). Was: ONE tabbed legacy card (Manage/Add tabs)
         with the create form in a narrow half-column tab pane and
         the page title repeated as the card heading. Now: list_page
         table + form_page create card below. Create contract
         unchanged — plain POST grades.store, same fields/old()
         bindings; row actions are the same Edit page + confirmDelete
         flow, rendered as buttons instead of a href="#" dropdown. --}}

    @component('partials.patterns.list_page')
        @slot('title')
            <i class="icon-graduation2 mr-2"></i> Manage Grades
        @endslot
        @slot('subtitle')
            Grade bands used to convert marks into grades and remarks.
        @endslot
        @slot('action')
            @if(Qs::userIsTeamSAWrite())
            <a href="#add-grade" class="btn btn-primary"><i class="icon-plus2 mr-2"></i> Add Grade</a>
            @endif
        @endslot

        @if($grades->count())
            <div class="table-responsive">
            <table class="table">
                <thead>
                <tr>
                    <th>S/N</th>
                    <th>Name</th>
                    <th>Grade Type</th>
                    <th>Range</th>
                    <th>Remark</th>
                    <th>Action</th>
                </tr>
                </thead>
                <tbody>
                @foreach($grades as $gr)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td>{{ $gr->name }}</td>
                        <td>{{ $gr->class_type_id ? $class_types->where('id', $gr->class_type_id)->first()->name : ''}}</td>
                        <td>{{ $gr->mark_from.' - '.$gr->mark_to }}</td>
                        <td>{{ $gr->remark }}</td>
                        <td class="text-center">
                            @if(Qs::userIsTeamSAWrite())
                                <a href="{{ route('grades.edit', $gr->id) }}" class="btn btn-sm btn-primary"><i class="icon-pencil"></i> Edit</a>
                                <button type="button" id="{{ $gr->id }}" onclick="confirmDelete(this.id)" class="btn btn-sm btn-danger"><i class="icon-trash"></i> Delete</button>
                                <form method="post" id="item-delete-{{ $gr->id }}" action="{{ route('grades.destroy', $gr->id) }}" class="hidden">@csrf @method('delete')</form>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        @else
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-graduation2',
                'message' => 'No grade bands found yet. Create the first grade band using the form below.',
            ])
        @endif
    @endcomponent

    <div id="add-grade">
        @if(Qs::userIsTeamSAWrite())
        @component('partials.patterns.form_page', [
            'form_action' => route('grades.store'),
            'submit_label' => 'Submit form',
        ])
            @slot('title')
                <i class="icon-plus2 mr-2"></i> Add Grade
            @endslot

            <div class="alert alert-info border-0 alert-dismissible">
                <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
                <span>If The grade you are creating applies to all class types select <strong>NOT APPLICABLE.</strong> Otherwise select the Class Type That the grade applies to</span>
            </div>

            <div class="pat-form-section">
                <div class="pat-fields">
                    <div class="pat-field">
                        <label for="name">Name <span class="text-danger">*</span></label>
                        <input id="name" name="name" value="{{ old('name') }}" required type="text" class="form-control text-uppercase" placeholder="E.g. A1">
                    </div>
                    <div class="pat-field">
                        <label for="class_type_id">Grade Type</label>
                        <select class="form-control select" name="class_type_id" id="class_type_id">
                            <option value="">Not Applicable</option>
                            @foreach($class_types as $ct)
                                <option {{ old('class_type_id') == $ct->id ? 'selected' : '' }} value="{{ $ct->id }}">{{ $ct->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="pat-field">
                        <label for="mark_from">Mark From <span class="text-danger">*</span></label>
                        <input id="mark_from" min="0" max="100" name="mark_from" value="{{ old('mark_from') }}" required type="number" class="form-control" placeholder="0">
                    </div>
                    <div class="pat-field">
                        <label for="mark_to">Mark To <span class="text-danger">*</span></label>
                        <input id="mark_to" min="0" max="100" name="mark_to" value="{{ old('mark_to') }}" required type="number" class="form-control" placeholder="0">
                    </div>
                    <div class="pat-field">
                        <label for="remark">Remark</label>
                        <select class="form-control select" name="remark" id="remark">
                            <option value="">Select Remark...</option>
                            @foreach(Mk::getRemarks() as $rem)
                                <option {{ old('remark') == $rem ? 'selected' : '' }} value="{{ $rem }}">{{ $rem }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>
        @endcomponent
        @endif
    </div>
@endsection
