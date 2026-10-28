{{-- Status / category chips for feedback items (Workstream CW).
     Expects $item (FeedbackItem). Mirrors the circulars badge style. --}}
@php
    $fbStatusBadges = [
        'new' => 'badge-warning',
        'acknowledged' => 'badge-info',
        'in_progress' => 'badge-primary',
        'resolved' => 'badge-success',
        'wont_fix' => 'badge-secondary',
        'duplicate' => 'badge-light',
    ];
    $fbSaBadges = [
        'received' => 'badge-info',
        'in_progress' => 'badge-primary',
        'fixed' => 'badge-success',
        'declined' => 'badge-danger',
    ];
    $fbPriorityBadges = [
        'low' => 'badge-light',
        'medium' => 'badge-info',
        'high' => 'badge-warning',
        'urgent' => 'badge-danger',
    ];
@endphp
<span class="badge badge-light">{{ $item->categoryLabel() }}</span>
<span class="badge {{ $fbPriorityBadges[$item->priority] ?? 'badge-info' }}">{{ $item->priorityLabel() }}</span>
<span class="badge {{ $fbStatusBadges[$item->status] ?? 'badge-light' }}">{{ $item->statusLabel() }}</span>
@if($item->isForwarded() && empty($hide_sa))
    <span class="badge {{ $fbSaBadges[$item->sa_status] ?? 'badge-info' }}">SA: {{ $item->saStatusLabel() }}</span>
@endif
