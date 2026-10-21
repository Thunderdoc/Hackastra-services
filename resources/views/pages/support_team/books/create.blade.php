@extends('layouts.master')
@section('page_title', 'New Book')
@section('content')
    <div class="card">
        <div class="card-body">
            @if($errors->any())
                <div class="alert alert-danger border-0">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
            @endif
            <form method="POST" action="{{ route('books.store') }}">
                @csrf
                @include('pages.support_team.books._form')
                <button type="submit" class="btn btn-primary">Add Book</button>
                <a href="{{ route('books.index') }}" class="btn btn-light">Cancel</a>
            </form>
        </div>
    </div>
@endsection
