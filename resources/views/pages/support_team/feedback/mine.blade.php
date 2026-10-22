@extends('layouts.master')
@section('page_title', 'My Feedback')
@section('content')
    {{-- My Feedback (Workstream CW): the reporter's own items only,
         with live status — including the Super Admin's reply once the
         Admin has forwarded the report and the SA has answered. --}}
    @if(session('flash_success'))
        <div class="alert alert-success border-0">{{ session('flash_success') }}</div>
    @endif

    @component('partials.patterns.list_page')
        @slot('subtitle')
            Everything you have reported to the school Admin, with its live status.
        @endslot
        @slot('action')
            <a href="{{ route('feedback.create') }}" class="btn btn-primary"><i class="icon-plus2 mr-1"></i> New Report</a>
        @endslot

        <table class="table">
            <thead><tr><th>Report</th><th>Status</th><th>Reported</th><th></th></tr></thead>
            <tbody>
            @forelse($items as $item)
                <tr>
                    <td>
                        <a href="{{ route('feedback.mine.show', $item->id) }}"><strong>{{ $item->title }}</strong></a>
                        <span class="text-muted">· {{ $item->typeLabel() }}</span>
                        <div class="text-muted">{{ \Illuminate\Support\Str::limit($item->description, 90) }}</div>
                        @if($item->attachments->count())
                            <div class="mt-1">@include('pages.support_team.feedback._attachments', ['item' => $item])</div>
                        @endif
                        @if($item->sa_reply)
                            <div class="mt-1"><strong>Super Admin reply:</strong> {{ \Illuminate\Support\Str::limit($item->sa_reply, 140) }}</div>
                        @endif
                    </td>
                    <td>
                        @include('pages.support_team.feedback._chips', ['item' => $item])
                        @if($item->isDuplicate())
                            <div class="text-muted mt-1" style="font-size:.85em">
                                Merged with a similar report{{ $item->duplicateOf ? ' (#' . $item->duplicateOf->id . ')' : '' }} —
                                now tracking: <strong>{{ $item->effectiveStatusLabel() }}</strong>
                            </div>
                        @endif
                    </td>
                    <td style="white-space:nowrap">{{ $item->created_at->format('d M Y') }}<div class="text-muted">{{ $item->created_at->format('h:i A') }}</div></td>
                    <td><a href="{{ route('feedback.mine.show', $item->id) }}" class="btn btn-light btn-sm">View</a></td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-center text-muted">You have not reported anything yet. Use <strong>New Report</strong> to send feedback to the school Admin.</td></tr>
            @endforelse
            </tbody>
        </table>
        {{ $items->links() }}
    @endcomponent
@endsection
