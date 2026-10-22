@extends('layouts.master')
@section('page_title', 'Manage Exams')
@section('content')

    <div class="card">

        <div class="card-body">
            <ul class="nav nav-tabs nav-tabs-highlight">
                <li class="nav-item"><a href="#all-exams" class="nav-link active" data-toggle="tab">Manage Exam</a></li>
                <li class="nav-item"><a href="#new-exam" class="nav-link" data-toggle="tab"><i class="icon-plus2"></i> Add Exam</a></li>
            </ul>

            <div class="tab-content">
                    <div class="tab-pane fade show active" id="all-exams">
                        <div class="table-responsive">
                        <table class="table">
                            <thead>
                            <tr>
                                <th>S/N</th>
                                <th>Name</th>
                                <th>Term</th>
                                <th>Session</th>
                                <th>Results</th>
                                <th>Action</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($exams as $ex)
                                <tr>
                                    <td>{{ $loop->iteration }}</td>
                                    <td>{{ $ex->name }}</td>
                                    <td>{{ 'Term '.$ex->term }}</td>
                                    <td>{{ $ex->year }}</td>
                                    <td>
                                        @if($ex->results_published)
                                            <span class="badge badge-success">Published</span>
                                        @else
                                            <span class="badge badge-secondary">Not published</span>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        {{-- Workstream BW: the toggle was a bare
                                             icon-menu9 glyph anchor (owner screenshot:
                                             rendered as a stray "≡"). Now the app's
                                             labelled Actions dropdown button; every
                                             item inside is unchanged. --}}
                                        <div class="dropdown">
                                            <button type="button" class="btn btn-sm btn-primary dropdown-toggle" data-toggle="dropdown">Actions</button>

                                            <div class="dropdown-menu dropdown-menu-right">
                                                    {{--Admit Cards--}}
                                                    <a href="{{ route('admit_cards.index', ['exam_id' => $ex->id]) }}" class="dropdown-item"><i class="icon-profile"></i> Admit Cards</a>
                                                    @if(Qs::userIsTeamSAWrite())
                                                    {{--Marking Scheme--}}
                                                    <a href="{{ route('exams.scheme', $ex->id) }}" class="dropdown-item"><i class="icon-list"></i> Marking Scheme</a>
                                                    {{--Result Publish switch (Workstream BH)--}}
                                                    @if($ex->results_published)
                                                    <form method="post" action="{{ route('exams.unpublish_results', $ex->id) }}" data-confirm="Unpublish results for {{ $ex->name }}? Students and parents will no longer be able to see these results." data-confirm-title="Unpublish Results" data-confirm-action="Unpublish" data-confirm-danger>
                                                        @csrf
                                                        <button type="submit" class="dropdown-item"><i class="icon-eye-blocked"></i> Unpublish Results</button>
                                                    </form>
                                                    @else
                                                    <form method="post" action="{{ route('exams.publish_results', $ex->id) }}">
                                                        @csrf
                                                        <button type="submit" class="dropdown-item"><i class="icon-eye"></i> Publish Results</button>
                                                    </form>
                                                    @endif
                                                    @endif
                                                    @if(Qs::userIsTeamSAWrite())
                                                    {{--Edit--}}
                                                    <a href="{{ route('exams.edit', $ex->id) }}" class="dropdown-item"><i class="icon-pencil"></i> Edit</a>
                                                   @endif
                                                    @if(Qs::userIsTeamSAWrite())
                                                    {{--Delete--}}
                                                    <a id="{{ $ex->id }}" onclick="confirmDelete(this.id)" href="#" class="dropdown-item"><i class="icon-trash"></i> Delete</a>
                                                    <form method="post" id="item-delete-{{ $ex->id }}" action="{{ route('exams.destroy', $ex->id) }}" class="hidden">@csrf @method('delete')</form>
                                                        @endif

                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                        </div>
                    </div>

                <div class="tab-pane fade" id="new-exam">
                    <div class="row">
                        <div class="col-md-12">
                            <div class="alert alert-info border-0 alert-dismissible">
                                <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>

                                <span>You are creating an Exam for the Current Session <strong>{{ Qs::getSetting('current_session') }}</strong></span>
                            </div>
                        </div>
                    </div>
                    {{-- Workstream BW: fields moved onto the pattern grid
                         (pat-fields/pat-field, full width). The tab shell,
                         form action and fields are unchanged; the term
                         select's data-placeholder said "Select Teacher"
                         (copy-paste relic) and now says "Select Term". --}}
                    <form method="post" action="{{ route('exams.store') }}">
                        @csrf
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
                        </div>

                        <div class="text-right mt-3">
                            <button type="submit" class="btn btn-primary">Submit form <i class="icon-paperplane ml-2"></i></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{--Class List Ends--}}

@endsection
