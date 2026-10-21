@extends('layouts.master')
@section('page_title', 'Edit Event')
@section('content')
    <div class="card">
        <div class="card-body">
            @if($errors->any())
                <div class="alert alert-danger border-0">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
            @endif
            <form method="POST" action="{{ route('events.update', $event->id) }}">
                @csrf @method('put')
                @include('pages.support_team.events._form')
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <a href="{{ route('events.index') }}" class="btn btn-light">Cancel</a>
            </form>
        </div>
    </div>
@endsection
