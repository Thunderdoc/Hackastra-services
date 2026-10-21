@extends('layouts.master')
@section('page_title', 'Edit Circular')
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">Edit Circular</h6>
            {!! Qs::getPanelOptions() !!}
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-8">
                    <form method="post" action="{{ route('circulars.update', $circular->id) }}">
                        @csrf
                        @method('put')
                        @include('pages.support_team.circulars._form')
                        <div class="text-right">
                            <button type="submit" class="btn btn-primary">Update Circular <i class="icon-paperplane ml-2"></i></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
