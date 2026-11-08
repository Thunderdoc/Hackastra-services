@extends('layouts.master')
@section('page_title', 'Parent Message')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Communication &nbsp;/&nbsp; <a href="{{ route('parent_messages.index') }}">Parent Messages</a> &nbsp;/&nbsp; {!! $__env->yieldContent('page_title') !!}
@endsection
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
                <table class="table hk-std hk-std-560">
                {{-- Inside-Matter Job 2 (2026-11-08): hk-std standard — colgroup widths sum to 100%. --}}
                <colgroup><col style="width:25%"><col style="width:75%"></colgroup>
                    <tbody>
                        <tr><th>From</th><td>{{ $message->sender->name ?? '—' }}</td></tr>
                        <tr><th>Class</th><td>{{ $message->my_class->name ?? '—' }}</td></tr>
                        <tr><th>Section</th><td>{{ $message->section->name ?? 'All sections' }}</td></tr>
                        <tr><th>Sent</th><td class="hk-nw">{{ $message->created_at ? $message->created_at->format('d M Y, h:i A') : '—' }}</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="mt-3" style="white-space: pre-wrap;">{{ $message->body }}</div>
        </div>
    </div>
@endsection
