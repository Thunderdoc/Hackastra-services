@extends('layouts.master')
@section('page_title', 'Hostel Dorms')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Hostel &nbsp;/&nbsp; {!! $__env->yieldContent('page_title') !!}
@endsection
@section('content')
    {{-- Beauty Wave 3: the slim module banner (partials/module_banner)
         opens the hostel module's landing list, with the ledger's
         own counts. The card below — buttons included — is unchanged. --}}
    @include('partials.module_banner', [
        'hkModIcon' => 'icon-home9',
        'hkModTone' => 'green',
        'hkModSub' => 'Dorms, rooms and the students allocated to them.',
        'hkModStats' => [
            ['value' => number_format($dorms->count()), 'label' => 'Dorms'],
            ['value' => number_format($rooms_count), 'label' => 'Rooms'],
            ['value' => number_format($allocations_count), 'label' => 'Allocated'],
        ],
    ])
    <div class="card">
        <div class="card-header header-elements-inline">
            <h5 class="card-title">All Dorms</h5>
            <div class="header-elements">
                <a href="{{ route('hostel.rooms.index') }}" class="btn btn-sm btn-primary">Rooms</a>
                <a href="{{ route('hostel.allocations.index') }}" class="btn btn-sm btn-primary">Allocations</a>
            </div>
        </div>
        <div class="card-body">
            @if($dorms->count())
                <div class="table-responsive">
                    <table class="table hk-std hk-std-640">
                    {{-- Inside-Matter Job 2 (2026-11-08): hk-std standard — colgroup widths sum to 100%. --}}
                    <colgroup><col style="width:7%"><col style="width:26%"><col style="width:41%"><col style="width:26%"></colgroup>
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
                                    <td class="hk-nw">{{ $loop->iteration }}</td>
                                    <td>{{ $dorm->name }}</td>
                                    <td>{{ $dorm->description }}</td>
                                    <td>
                                        @if(auth()->user() && auth()->user()->user_type === 'hostel')
                                            <a href="{{ route('hostel.dorms.edit', ['hostel_dorm' => $dorm->id]) }}" class="btn btn-sm btn-primary">Edit</a>
                                            <form method="post" action="{{ route('hostel.dorms.destroy', ['hostel_dorm' => $dorm->id]) }}" class="d-inline" data-confirm="Delete dorm {{ $dorm->name }}? This cannot be undone." data-confirm-title="Delete Dorm" data-confirm-action="Delete" data-confirm-danger>
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
                @include('partials.patterns.empty_state', [
                    'icon' => 'icon-home9',
                    'message' => 'No dorms found yet. Create the first dorm using the form below.',
                ])
            @endif
            @if(auth()->user() && auth()->user()->user_type === 'hostel')
                <hr>
                <h6>Create Dorm</h6>
                <form method="post" action="{{ route('hostel.dorms.store') }}">
                    @csrf
                    <div class="form-group">
                        <label for="name">Name *</label>
                        <input id="name" name="name" value="{{ old('name') }}" required type="text" class="form-control @error('name') is-invalid @enderror" placeholder="Name of dorm">@include('partials.errors.field', ['field' => 'name'])
                    </div>
                    <div class="form-group">
                        <label for="description">Description</label>
                        <input id="description" name="description" value="{{ old('description') }}" type="text" class="form-control @error('description') is-invalid @enderror" placeholder="Description">@include('partials.errors.field', ['field' => 'description'])
                    </div>
                    <button type="submit" class="btn btn-primary">Create Dorm</button>
                </form>
            @endif
        </div>
    </div>
@endsection
