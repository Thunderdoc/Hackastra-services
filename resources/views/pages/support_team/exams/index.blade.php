@extends('layouts.master')
@section('page_title', 'Manage Exams')
@section('content')

    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">Manage Exams</h6>
            {!! Qs::getPanelOptions() !!}
        </div>

        <div class="card-body">
            <ul class="nav nav-tabs nav-tabs-highlight">
                <li class="nav-item"><a href="#all-exams" class="nav-link active" data-toggle="tab">Manage Exam</a></li>
                <li class="nav-item"><a href="#new-exam" class="nav-link" data-toggle="tab"><i class="icon-plus2"></i> Add Exam</a></li>
            </ul>

            <div class="tab-content">
                    <div class="tab-pane fade show active" id="all-exams">
                        <table class="table datatable-button-html5-columns">
                            <thead>
                            <tr>
                                <th>S/N</th>
                                <th>Name</th>
                                <th>Term</th>
                                <th>Session</th>
                                <th>Action</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse($exams as $ex)
                                <tr>
                                    <td>{{ $loop->iteration }}</td>
                                    <td>{{ $ex->name }}</td>
                                    <td>{{ 'Term '.$ex->term }}</td>
                                    <td>{{ $ex->year }}</td>
                                    <td class="text-center">
                                        <div class="list-icons">
                                            <div class="dropdown">
                                                <a href="#" class="list-icons-item" data-toggle="dropdown">
                                                <i class="icon-menu9"></i>
                                                </a>

                                                <div class="dropdown-menu dropdown-menu-left">
                                                    @if(Qs::userIsTeamSA())
                                                    {{--Edit--}}
                                                    <a href="{{ route('exams.edit', $ex->id) }}" class="dropdown-item"><i class="icon-pencil"></i> Edit</a>
                                                   @endif
                                                    @if(Qs::userIsSuperAdmin())
                                                    {{--Delete--}}
                                                    <a id="{{ $ex->id }}" onclick="confirmDelete(this.id)" href="#" class="dropdown-item"><i class="icon-trash"></i> Delete</a>
                                                    <form method="post" id="item-delete-{{ $ex->id }}" action="{{ route('exams.destroy', $ex->id) }}" class="hidden">@csrf @method('delete')</form>
                                                        @endif

                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-center text-muted" style="padding:22px 12px"><svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="#3478f6" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="opacity:.55;display:block;margin:0 auto 6px" aria-hidden="true"><path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg>No exams found yet. Create the first exam using the form above.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
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
                    <div class="row">
                        <div class="col-md-6">
                            <form method="post" action="{{ route('exams.store') }}">
                                @csrf
                                <div class="form-group row">
                                    <label class="col-lg-3 col-form-label font-weight-semibold">Name <span class="text-danger">*</span></label>
                                    <div class="col-lg-9">
                                        <input name="name" value="{{ old('name') }}" required type="text" class="form-control" placeholder="Name of Exam">
                                    </div>
                                </div>

                                <div class="form-group row">
                                    <label for="term" class="col-lg-3 col-form-label font-weight-semibold">Term</label>
                                    <div class="col-lg-9">
                                        <select data-placeholder="Select Teacher" class="form-control select-search" name="term" id="term">
                                            <option {{ old('term') == 1 ? 'selected' : '' }} value="1">First Term</option>
                                            <option {{ old('term') == 2 ? 'selected' : '' }} value="2">Second Term</option>
                                            <option {{ old('term') == 3 ? 'selected' : '' }} value="3">Third Term</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="text-right">
                                    <button type="submit" class="btn btn-primary">Submit form <i class="icon-paperplane ml-2"></i></button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{--Class List Ends--}}

@endsection
