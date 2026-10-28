@extends('layouts.master')
@section('page_title', 'Parent Message')
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            <h5 class="card-title">{{ $message->title }}</h5>
            <div class="header-elements">
                <a href="{{ route('parent_messages.index') }}" class="btn btn-light btn-sm">Back to Messages</a>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table">
                    <tbody>
                        <tr><th>From</th><td>{{ $message->sender->name ?? '—' }}</td></tr>
                        <tr><th>Class</th><td>{{ $message->my_class->name ?? '—' }}</td></tr>
                        <tr><th>Section</th><td>{{ $message->section->name ?? 'All sections' }}</td></tr>
                        <tr><th>Sent</th><td>{{ $message->created_at ? $message->created_at->format('d M Y, h:i A') : '—' }}</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="mt-3" style="white-space: pre-wrap;">{{ $message->body }}</div>
        </div>
    </div>
@endsection
