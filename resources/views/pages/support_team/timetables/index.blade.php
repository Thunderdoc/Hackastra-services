@extends('layouts.master')
@section('page_title', 'Manage TimeTables')
@section('content')

    @if(Qs::userIsTeamSA())
    {{-- Workstream BW: create form rebuilt on form_page (was a
         legacy row of col-md-* form-groups in its own card). Same
         .ajax-store POST to ttr.store, same fields/bindings; the
         timetable list card below is unchanged. --}}
    @component('partials.patterns.form_page', [
        'form_action' => route('ttr.store'),
        'form_class' => 'ajax-store',
        'submit_label' => 'Create',
    ])
        @slot('title')
            <i class="icon-table2 mr-2"></i> Create Timetable
        @endslot

        <div class="pat-form-section">
            <div class="pat-fields">
                <div class="pat-field">
                    <label for="name">Name <span class="text-danger">*</span></label>
                    <input id="name" name="name" value="{{ old('name') }}" required type="text" class="form-control" placeholder="Name of TimeTable">
                </div>
                <div class="pat-field">
                    <label for="my_class_id">Class <span class="text-danger">*</span></label>
                    <select required data-placeholder="Select Class" class="form-control select" name="my_class_id" id="my_class_id">
                        @foreach($my_classes as $mc)
                            <option {{ old('my_class_id') == $mc->id ? 'selected' : '' }} value="{{ $mc->id }}">{{ $mc->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-field">
                    <label for="exam_id">Type (Class or Exam)</label>
                    <select class="select form-control" name="exam_id" id="exam_id">
                        <option value="">Class Timetable</option>
                        @foreach($exams as $ex)
                            <option {{ old('exam_id') == $ex->id ? 'selected' : '' }} value="{{ $ex->id }}">{{ $ex->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>
    @endcomponent
    @endif

    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">Timetables</h6>
            {!! Qs::getPanelOptions() !!}
        </div>

        <div class="card-body">
            @php $has_records = $tt_records->count() > 0; @endphp

            @if(!$has_records)
                <div class="ttx-empty-state">
                    <i class="icon-table2"></i>
                    <p>No timetables have been created yet.</p>
                </div>
            @endif

            @foreach($my_classes as $mc)
                @php $records = $tt_records->where('my_class_id', $mc->id); @endphp
                @if($records->count())
                    <div class="ttx-group">
                        <div class="ttx-group-head">
                            <h6 class="ttx-group-title"><i class="icon-library2"></i> {{ $mc->name }}</h6>
                            <span class="badge badge-light">{{ $records->count() }} timetable{{ $records->count() === 1 ? '' : 's' }}</span>
                        </div>
                        <div class="l7-table-scroll">
                            <table class="table ttx-table">
                                <thead>
                                <tr>
                                    <th style="width:56px">S/N</th>
                                    <th>Name</th>
                                    <th>Type</th>
                                    <th>Year</th>
                                    <th style="width:300px">Action</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($records as $ttr)
                                    <tr>
                                        <td>{{ $loop->iteration }}</td>
                                        <td><strong>{{ $ttr->name }}</strong></td>
                                        <td>{{ ($ttr->exam_id) ? $ttr->exam->name : 'Class TimeTable' }}</td>
                                        <td>{{ $ttr->year }}</td>
                                        <td>
                                            <div class="ttx-actions">
                                                <a href="{{ route('ttr.show', $ttr->id) }}" class="btn btn-light btn-sm"><i class="icon-eye mr-1"></i> View</a>
                                                <a target="_blank" href="{{ route('ttr.print', $ttr->id) }}" class="btn btn-light btn-sm"><i class="icon-printer mr-1"></i> Print</a>
                                                @if(Qs::userIsTeamSA())
                                                    <a href="{{ route('ttr.manage', $ttr->id) }}" class="btn btn-light btn-sm"><i class="icon-plus-circle2 mr-1"></i> Manage</a>
                                                    <a href="{{ route('ttr.edit', $ttr->id) }}" class="btn btn-light btn-sm"><i class="icon-pencil mr-1"></i> Edit</a>
                                                    <a id="{{ $ttr->id }}" onclick="confirmDelete(this.id)" href="#" class="btn btn-light btn-sm text-danger"><i class="icon-trash mr-1"></i> Delete</a>
                                                    <form method="post" id="item-delete-{{ $ttr->id }}" action="{{ route('ttr.destroy', $ttr->id) }}" class="hidden">@csrf @method('delete')</form>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            @endforeach
        </div>
    </div>

    {{--TimeTable Ends--}}

@endsection
