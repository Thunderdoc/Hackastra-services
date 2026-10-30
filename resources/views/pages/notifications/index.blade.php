@extends('layouts.master')
@section('page_title', 'Notifications')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Notifications
@endsection
@section('content')
    @if(session('flash_success'))
        <div class="alert alert-success">{{ session('flash_success') }}</div>
    @endif

    {{-- Owner order 2026-10-30: the full list, done properly —
         every notification this user has received, unread marked,
         an explicit Mark all read, and the mute/settings one tap
         away in My Account. --}}
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
            <h5 class="card-title mb-0">Notifications
                @if($unread_count > 0)
                    <span class="badge badge-danger">{{ $unread_count }} new</span>
                @endif
            </h5>
            <div>
                <a href="{{ route('notifications.index') }}" class="btn btn-sm {{ $only_unread ? 'btn-light' : 'btn-primary' }}">All</a>
                <a href="{{ route('notifications.index', ['filter' => 'unread']) }}" class="btn btn-sm {{ $only_unread ? 'btn-primary' : 'btn-light' }}">Unread</a>
                @if($unread_count > 0)
                    <form method="POST" action="{{ route('notifications.read') }}" class="d-inline">
                        @csrf
                        <input type="hidden" name="redirect" value="1">
                        <button type="submit" class="btn btn-sm btn-success">Mark all read</button>
                    </form>
                @endif
                <a href="{{ route('my_account') }}#notifications" class="btn btn-sm btn-light">Notification settings</a>
            </div>
        </div>
        <div class="card-body">
            @if($notifications->isEmpty())
                <p class="text-muted mb-0">{{ $only_unread ? 'Nothing unread — you are all caught up.' : 'No notifications yet. When something happens for you, it will appear here.' }}</p>
            @else
                <div class="list-group">
                    @foreach($notifications as $notif)
                        <a href="{{ $notif->link ?: '#' }}" class="list-group-item list-group-item-action">
                            <div class="d-flex justify-content-between align-items-start">
                                <span>
                                    @if($notif->isUnread())<strong>{{ $notif->title }}</strong>@else{{ $notif->title }}@endif
                                    @if($notif->body)<br><span class="text-muted">{{ $notif->body }}</span>@endif
                                </span>
                                <span class="text-nowrap ml-3">
                                    @if($notif->isUnread())<span class="badge badge-danger">New</span>@endif
                                    <small class="text-muted">{{ $notif->created_at ? $notif->created_at->diffForHumans() : '' }}</small>
                                </span>
                            </div>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endsection
