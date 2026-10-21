@extends('layouts.master')
@section('page_title', 'New Circular')
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">New Circular</h6>
            {!! Qs::getPanelOptions() !!}
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-8">
                    <form method="post" action="{{ route('circulars.store') }}">
                        @csrf
                        @include('pages.support_team.circulars._form')
                        <div class="text-right">
                            <button type="submit" class="btn btn-primary">Save Circular <i class="icon-paperplane ml-2"></i></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
