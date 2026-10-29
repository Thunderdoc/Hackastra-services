@extends('layouts.master')
@section('page_title', 'Manage Exams')
@section('content')
    {{-- Loop 108: rebuilt on the shared patterns (Loop 28), following
         the Batch 4 / Workstream BW academics template (classes /
         grades / dorms). Was: ONE tabbed legacy Limitless card
         (Manage/Add tabs) in the old light style — the last CRUD list
         still on that shell. Now: list_page table + form_page create
         card below. Behaviour unchanged: same exams.store POST, same
         per-exam tools (Admit Cards, Marking Scheme, Publish/Unpublish,
         Edit, Delete with its confirm flow), same teamSAWrite gating
         (Workstream DD: the create form is never offered to the
         view-only Super Admin). The table carries l108-stack so every
         column stays visible on phone widths. --}}
    @component('partials.patterns.list_page')
        @slot('title')
            <i class="icon-books mr-2"></i> Manage Exams
        @endslot
        @slot('subtitle')
            Create exams, then use each exam's tools to open its marking scheme, admit cards, result publishing and editing.
        @endslot
        @slot('action')
            @if(Qs::userIsTeamSAWrite())
            <a href="#add-exam" class="btn btn-primary"><i class="icon-plus2 mr-2"></i> Add Exam</a>
            @endif
        @endslot

        @if($exams->count())
            <table class="table l108-stack">
                <thead>
                <tr>
                    <th>S/N</th>
                    <th>Name</th>
                    <th>Term</th>
                    <th>Session</th>
                    <th>Scope</th>
                    <th>Results</th>
                    <th>Tools</th>
                </tr>
                </thead>
                <tbody>
                @foreach($exams as $ex)
                    <tr>
                        <td data-label="S/N">{{ $loop->iteration }}</td>
                        <td data-label="Name">{{ $ex->name }}</td>
                        <td data-label="Term">{{ 'Term '.$ex->term }}</td>
                        <td data-label="Session">{{ $ex->year }}</td>
                        <td data-label="Scope">{{ empty($ex->my_class_id) ? 'All classes' : ($my_classes->firstWhere('id', $ex->my_class_id)->name ?? 'Class '.$ex->my_class_id) }}</td>
                        <td data-label="Results">
                            @if($ex->results_published)
                                <span class="badge badge-success">Published</span>
                            @elseif($ex->publish_requested_at)
                                <span class="badge badge-warning">Publication requested</span>
                            @else
                                <span class="badge badge-secondary">Not published</span>
                            @endif
                        </td>
                        <td data-label="Tools">
                            {{-- Loop 108 (owner order 2026-10-23): the
                                 row Actions BUTTON + DROPDOWN is removed
                                 entirely — on phones it rendered
                                 half-clipped off the card edge and its
                                 menu overflowed the viewport. The five
                                 per-exam tools it held are now an inline
                                 tools cluster that wraps inside the
                                 card on every width: Admit Cards,
                                 Marking Scheme, Publish/Unpublish
                                 Results (Workstream BH forms, confirm
                                 attributes intact), Edit and Delete
                                 (same confirmDelete flow). Same routes,
                                 same teamSAWrite gating. --}}
                            <div class="l108-tools">
                                <a href="{{ route('admit_cards.index', ['exam_id' => $ex->id]) }}" class="btn btn-light btn-sm"><i class="icon-profile mr-1"></i> Admit Cards</a>
                                @if(Qs::userIsAdmin() || Qs::userIsPrincipal())
                                <a href="{{ route('exams.publication', $ex->id) }}" class="btn btn-light btn-sm"><i class="icon-eye mr-1"></i> Publication</a>
                                @endif
                                @if(Qs::userIsTeamSAWrite())
                                <a href="{{ route('exams.scheme', $ex->id) }}" class="btn btn-light btn-sm"><i class="icon-list mr-1"></i> Marking Scheme</a>
                                <a href="{{ route('exams.edit', $ex->id) }}" class="btn btn-primary btn-sm"><i class="icon-pencil mr-1"></i> Edit</a>
                                <button type="button" id="{{ $ex->id }}" onclick="confirmDelete(this.id)" class="btn btn-danger btn-sm"><i class="icon-trash mr-1"></i> Delete</button>
                                <form method="post" id="item-delete-{{ $ex->id }}" action="{{ route('exams.destroy', $ex->id) }}" class="hidden">@csrf @method('delete')</form>
                                @endif
                                @if(Qs::userIsPrincipal())
                                @if($ex->results_published)
                                <form method="post" action="{{ route('exams.unpublish_results', $ex->id) }}" data-confirm="Unpublish results for {{ $ex->name }}? Students and parents will no longer be able to see these results." data-confirm-title="Unpublish Results" data-confirm-action="Unpublish" data-confirm-danger>
                                    @csrf
                                    <button type="submit" class="btn btn-light btn-sm"><i class="icon-eye-blocked mr-1"></i> Unpublish Results</button>
                                </form>
                                @else
                                <form method="post" action="{{ route('exams.publish_results', $ex->id) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-light btn-sm"><i class="icon-eye mr-1"></i> Publish Results</button>
                                </form>
                                @endif
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @else
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-books',
                'message' => 'No exams yet. Add the first exam below — its marking scheme, admit cards and result publishing will live here.',
            ])
        @endif
    @endcomponent

    @if(Qs::userIsTeamSAWrite())
    <div id="add-exam">
        @component('partials.patterns.form_page', [
            'form_action' => route('exams.store'),
            'submit_label' => 'Submit form',
        ])
            @slot('title')
                <i class="icon-plus2 mr-2"></i> Add Exam
            @endslot

            <div class="alert alert-info border-0 alert-dismissible">
                <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
                <span>You are creating an Exam for the Current Session <strong>{{ Qs::getSetting('current_session') }}</strong></span>
            </div>

            {{-- Workstream BW: fields on the pattern grid (unchanged
                 in this loop — same fields, action and bindings). --}}
            <div class="pat-form-section">
                <div class="pat-fields">
                    <div class="pat-field">
                        <label for="name">Name <span class="text-danger">*</span></label>
                        <input id="name" name="name" value="{{ old('name') }}" required type="text" class="form-control" placeholder="Name of Exam">
                    </div>
                    <div class="pat-field">
                        <label for="term">Term</label>
                        <select data-placeholder="Select Term" class="form-control select-search" name="term" id="term">
                            <option {{ old('term') == 1 ? 'selected' : '' }} value="1">First Term</option>
                            <option {{ old('term') == 2 ? 'selected' : '' }} value="2">Second Term</option>
                            <option {{ old('term') == 3 ? 'selected' : '' }} value="3">Third Term</option>
                        </select>
                    </div>
                    <div class="pat-field">
                        <label for="my_class_id">Class</label>
                        <select data-placeholder="All classes" class="form-control select-search" name="my_class_id" id="my_class_id">
                            <option value="">All classes</option>
                            @foreach($my_classes as $mc)
                                <option {{ old('my_class_id') == $mc->id ? 'selected' : '' }} value="{{ $mc->id }}">{{ $mc->name }}</option>
                            @endforeach
                        </select>
                        <small class="text-muted">All classes = the old global exam. One class = only that class gets this exam for the term; other classes need their own.</small>
                    </div>
                </div>
            </div>
        @endcomponent
    </div>
    @endif

@endsection
