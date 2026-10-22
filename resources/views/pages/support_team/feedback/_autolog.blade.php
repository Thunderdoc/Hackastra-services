{{-- Auto-log context block (Workstream CW): captured with the
     report, never typed by the reporter. Expects $item. --}}
<div class="text-muted" style="font-size:.85em">
    <i class="icon-info22 mr-1"></i>
    Reported {{ $item->autoLogLine() }} · {{ $item->created_at ? $item->created_at->format('d M Y, h:i A') : '' }}
    @if($item->user_agent)
        <div style="word-break:break-all;opacity:.75">{{ $item->user_agent }}</div>
    @endif
</div>
