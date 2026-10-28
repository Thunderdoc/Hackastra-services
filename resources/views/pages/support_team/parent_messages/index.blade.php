@extends('layouts.master')
@section('page_title', 'Parent Messages')
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            <h5 class="card-title">{{ $is_parent ? 'Messages for You' : 'Parent Messages You Sent' }}</h5>
            <div class="header-elements">
                @if($can_compose)
                    <a href="{{ route('parent_messages.create') }}" class="btn btn-primary btn-sm">New Message</a>
                @endif
            </div>
        </div>
        <div class="card-body">
            @if(session('flash_success'))
                <div class="alert alert-success border-0">{{ session('flash_success') }}</div>
            @endif
            @if(session('flash_danger'))
                <div class="alert alert-danger border-0">{{ session('flash_danger') }}</div>
            @endif

            @if($messages->count() === 0)
                <p class="text-muted">{{ $is_parent ? 'No messages for your children yet.' : 'No messages sent yet.' }}</p>
            @else
                <div class="table-responsive">
                    <table class="table">
                        <thead><tr><th>Title</th><th>From</th><th>Class</th><th>Section</th><th>Sent</th><th>Action</th></tr></thead>
                        <tbody>
                        @foreach($messages as $message)
                            <tr>
                                <td>{{ $message->title }}</td>
                                <td>{{ $message->sender->name ?? '—' }}</td>
                                <td>{{ $message->my_class->name ?? '—' }}</td>
                                <td>{{ $message->section->name ?? 'All sections' }}</td>
                                <td>{{ $message->created_at ? $message->created_at->format('d M Y') : '—' }}</td>
                                <td><a href="{{ route('parent_messages.show', $message->id) }}" class="btn btn-light btn-sm">View</a></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                {{ $messages->links() }}
            @endif
        </div>
    </div>
@endsection
