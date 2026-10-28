@extends('layouts.master')
@section('page_title', 'Manage Class Sections')
@section('content')
    {{-- Batch 4: rebuilt on the shared patterns (Loop 28). Was: one
         stacked table per class under a tab strip (73KB rendered, 30
         inputs, a DataTables search per table). Now: a class picker
         (?class_id=, filtered server-side, default = first class) +
         ONE table, and the create form as a form_page card. Create
         contract: POST sections.store via a plain form (Loop 112: post-save lands on the index),
         same fields/validation; row actions are the same Edit page +
         confirmDelete flow, rendered as buttons instead of a
         href="#" dropdown. The default (active) section still cannot
         be deleted — that guard lives in SectionController@destroy. --}}
    @component('partials.patterns.list_page')
        @slot('title')
            <i class="icon-fence mr-2"></i> Manage Class Sections
        @endslot
        @slot('subtitle')
            @if($selected_class)
                Showing sections for <strong>{{ $selected_class->name }}</strong> — pick another class to switch.
            @else
                No classes exist yet.
            @endif
        @endslot
        @slot('action')
            @if(Qs::userIsTeamSAWrite())
            <a href="#add-section" class="btn btn-primary"><i class="icon-plus2 mr-2"></i> Create New Section</a>
            @endif
        @endslot

        @slot('filters')
            <form method="get" action="{{ route('sections.index') }}" class="pat-filter-form">
                <div class="pat-filter-field">
                    <label for="class_id">Class:</label>
                    <select id="class_id" name="class_id" class="form-control select" data-placeholder="Select Class">
                        @foreach($my_classes as $c)
                            <option {{ ($selected_class && $selected_class->id == $c->id) ? 'selected' : '' }} value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-filter-action">
                    <button type="submit" class="btn btn-primary">View Sections <i class="icon-paperplane ml-2"></i></button>
                </div>
            </form>
        @endslot

        @if($selected_class && $sections->count())
            <div class="table-responsive">
            <table class="table l108-stack">
                <thead>
                <tr>
                    <th>S/N</th>
                    <th>Name</th>
                    <th>Class</th>
                    <th>Teacher</th>
                    <th>Overrides</th>
                    <th>Unassigned</th>
                    <th>Action</th>
                </tr>
                </thead>
                <tbody>
                @foreach($sections as $s)
                    <tr>
                        <td data-label="S/N">{{ $loop->iteration }}</td>
                        <td data-label="Name">{{ $s->name }} @if($s->active)<i class="icon-check"></i>@endif</td>
                        <td data-label="Class">{{ $s->my_class->name }}</td>
                        @if($s->teacher)
                            <td data-label="Teacher"><a target="_blank" href="{{ route('users.show', Qs::hash($s->teacher_id)) }}">{{ $s->teacher->name }}</a>@if(!($liveTeacherIds ?? collect())->has((int) $s->teacher_id)) <span class="text-danger">(not an active teacher)</span>@endif</td>
                        @elseif($s->teacher_id)
                            <td data-label="Teacher">Vacant</td>
                        @else
                            <td data-label="Teacher"> - </td>
                        @endif
                        <td data-label="Overrides">
                            @if(($overrideCounts[$s->id] ?? 0) > 0)
                                <a href="{{ route('sections.edit', $s->id) }}">{{ $overrideCounts[$s->id] }} override{{ $overrideCounts[$s->id] > 1 ? 's' : '' }}</a>
                            @else
                                -
                            @endif
                        </td>
                        <td data-label="Unassigned">
                            @php $un = $unresolvedSubjects[$s->id] ?? []; @endphp
                            @if(count($un) > 0)
                                <span class="text-danger" title="No teacher for: {{ implode(', ', $un) }}">{{ count($un) }} unassigned: {{ implode(', ', $un) }}</span>
                            @else
                                <span class="text-muted">All staffed</span>
                            @endif
                        </td>
                        <td class="text-center" data-label="Action">
                            @if(Qs::userIsTeamSAWrite())
                                <a href="{{ route('sections.edit', $s->id) }}" class="btn btn-sm btn-primary"><i class="icon-pencil"></i> Edit</a>
                                <button type="button" id="{{ $s->id }}" onclick="confirmDelete(this.id)" class="btn btn-sm btn-danger"><i class="icon-trash"></i> Delete</button>
                                <form method="post" id="item-delete-{{ $s->id }}" action="{{ route('sections.destroy', $s->id) }}" class="hidden">@csrf @method('delete')</form>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        @else
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-fence',
                'message' => 'No sections in this class yet. Add the first one with the form below.',
            ])
        @endif
    @endcomponent

    <div id="add-section">
        @if(Qs::userIsTeamSAWrite())
        @component('partials.patterns.form_page', [
            'form_action' => route('sections.store'),
            'submit_label' => 'Submit form',
        ])
            @slot('title')
                <i class="icon-plus2 mr-2"></i> Create New Section
            @endslot

            <div class="pat-form-section">
                <div class="pat-fields">
                    <div class="pat-field">
                        <label for="name">Name <span class="text-danger">*</span></label>
                        <input id="name" name="name" value="{{ old('name') }}" required type="text" class="form-control" placeholder="Name of Section">
                    </div>
                    <div class="pat-field">
                        <label for="my_class_id">Select Class <span class="text-danger">*</span></label>
                        <select required data-placeholder="Select Class" class="form-control select" name="my_class_id" id="my_class_id">
                            @foreach($my_classes as $c)
                                <option {{ old('my_class_id', $selected_class->id ?? null) == $c->id ? 'selected' : '' }} value="{{ $c->id }}">{{ $c->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="pat-field">
                        <label for="teacher_id">Teacher</label>
                        <select data-placeholder="Select Teacher" class="form-control select-search" name="teacher_id" id="teacher_id">
                            <option value=""></option>
                            @foreach($teachers as $t)
                                <option {{ old('teacher_id') == Qs::hash($t->id) ? 'selected' : '' }} value="{{ Qs::hash($t->id) }}">{{ $t->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="pat-field">
                        <label for="stream">Stream (Class 11/12)</label>
                        <select class="form-control select" name="stream" id="stream">
                            <option value="">No stream (general section)</option>
                            @foreach(\App\Support\Streams::ALL as $key => $label)
                                <option {{ old('stream') === $key ? 'selected' : '' }} value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>
        @endcomponent
        @endif
    </div>
@endsection
