@extends('layouts.master')
@section('page_title', 'Hostel Dorms')
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            <h5 class="card-title">Hostel Dorms</h5>
            <div class="header-elements">
                <a href="{{ route('hostel.rooms.index') }}" class="btn btn-sm btn-primary">Rooms</a>
                <a href="{{ route('hostel.allocations.index') }}" class="btn btn-sm btn-primary">Allocations</a>
            </div>
        </div>
        <div class="card-body">
            @if($errors->any())
                <div class="alert alert-danger">
                    @foreach($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif
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
                            @foreach($dorms as $dorm)
                                <tr>
                                    <td>{{ $loop->iteration }}</td>
                                    <td>{{ $dorm->name }}</td>
                                    <td>{{ $dorm->description }}</td>
                                    <td>
                                        @if(Qs::userIsTeamSAWrite())
                                            <a href="{{ route('hostel.dorms.edit', ['hostel_dorm' => $dorm->id]) }}" class="btn btn-sm btn-primary">Edit</a>
                                            <form method="post" action="{{ route('hostel.dorms.destroy', ['hostel_dorm' => $dorm->id]) }}" class="d-inline" onsubmit="return confirm('Delete this dorm?')">
                                                @csrf @method('delete')
                                                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p>No dorms found yet. Create the first dorm using the form below.</p>
            @endif
            @if(Qs::userIsTeamSAWrite())
                <hr>
                <h6>Create Dorm</h6>
                <form method="post" action="{{ route('hostel.dorms.store') }}">
                    @csrf
                    <div class="form-group">
                        <label for="name">Name *</label>
                        <input id="name" name="name" value="{{ old('name') }}" required type="text" class="form-control" placeholder="Name of dorm">
                    </div>
                    <div class="form-group">
                        <label for="description">Description</label>
                        <input id="description" name="description" value="{{ old('description') }}" type="text" class="form-control" placeholder="Description">
                    </div>
                    <button type="submit" class="btn btn-primary">Create Dorm</button>
                </form>
            @endif
        </div>
    </div>
@endsection
