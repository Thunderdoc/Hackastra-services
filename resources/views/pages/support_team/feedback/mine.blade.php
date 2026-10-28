@extends('layouts.master')
@section('page_title', 'My Feedback')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Communication &nbsp;/&nbsp; My Feedback
@endsection
@section('content')
    {{-- My Feedback (Workstream CW + Loop 87 uplift): the reporter's
         OWN items only — summary cards and status tabs run on the
         model's real status set; the list is filtered server-side
         (status tab + search + sort, all combinable). Counts and the
         Admin Response column are derived from real fields only. --}}
  <div class="l75-fb l87-mine">
    @component('partials.patterns.list_page')
        @slot('subtitle')
            Share feedback, suggestions or issues with the school admin. Track the status of your reports here.
        @endslot
        @slot('action')
            <a href="{{ route('feedback.create') }}" class="btn btn-primary"><i class="icon-plus2 mr-1"></i> New Report</a>
        @endslot

        @slot('stats')
            @php
                $fbPending = (int) (($counts['new'] ?? 0) + ($counts['acknowledged'] ?? 0) + ($counts['in_progress'] ?? 0));
                $fbResolved = (int) ($counts['resolved'] ?? 0);
                $fbClosed = (int) (($counts['wont_fix'] ?? 0) + ($counts['duplicate'] ?? 0));
            @endphp
            {{-- Loop 118: the reference's KPI cards are plain and
                 value-led — the number first, its label beneath, no
                 icon disc and no subtext line. --}}
            <div class="row dashboard-stats l118-fb-stats">
                @foreach([
                    ['label' => 'Total Reports', 'value' => $total, 'tone' => 'blue'],
                    ['label' => 'Pending', 'value' => $fbPending, 'tone' => 'orange'],
                    ['label' => 'Resolved', 'value' => $fbResolved, 'tone' => 'green'],
                    ['label' => 'Closed', 'value' => $fbClosed, 'tone' => 'red'],
                ] as $stat)
                    <div class="col-sm-6 col-xl-3">
                        <div class="dashboard-stat-card dashboard-stat-{{ $stat['tone'] }}">
                            <span class="dashboard-stat-copy">
                                <strong>{{ number_format($stat['value']) }}</strong>
                                <span class="dashboard-stat-label">{{ $stat['label'] }}</span>
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>
        @endslot

        {{-- Loop 118: the list chrome (status tabs + search) renders
             ALWAYS — the reference's empty state keeps it above the
             empty illustration instead of dropping it at zero reports. --}}
        @slot('filters')
            <div class="l87-tabs" role="tablist" aria-label="Filter by status">
                @php
                    $fbTabs = ['' => 'All Reports'] + $statuses;
                    $fbTabUrl = function ($st) use ($q, $sort) {
                        return route('feedback.mine', array_filter([
                            'status' => $st ?: null,
                            'q' => $q !== '' ? $q : null,
                            'sort' => $sort !== 'newest' ? $sort : null,
                        ]));
                    };
                @endphp
                @foreach($fbTabs as $value => $label)
                    <a href="{{ $fbTabUrl($value) }}" class="l87-tab {{ $active_status === $value ? 'is-active' : '' }}" role="tab" aria-selected="{{ $active_status === $value ? 'true' : 'false' }}">
                        <i class="{{ $value === '' ? 'icon-list' : 'icon-bubbles' }} mr-1"></i> {{ $label }}
                        <span class="l87-tab-count">{{ number_format($value === '' ? $total : (int) ($counts[$value] ?? 0)) }}</span>
                    </a>
                @endforeach
            </div>
            <form method="get" action="{{ route('feedback.mine') }}" class="pat-filter-form l87-search-form">
                @if($active_status !== '')
                    <input type="hidden" name="status" value="{{ $active_status }}">
                @endif
                <div class="pat-filter-field l87-search-field">
                    <label for="fb-q">Search</label>
                    <input id="fb-q" type="text" name="q" value="{{ $q }}" class="form-control" placeholder="Search reports by title or description...">
                </div>
                <div class="pat-filter-field">
                    <label for="fb-sort">Sort</label>
                    <select id="fb-sort" name="sort" class="form-control">
                        <option value="newest" {{ $sort === 'newest' ? 'selected' : '' }}>Newest first</option>
                        <option value="oldest" {{ $sort === 'oldest' ? 'selected' : '' }}>Oldest first</option>
                    </select>
                </div>
                <div class="pat-filter-action">
                    <button type="submit" class="btn btn-primary"><i class="icon-search4 mr-1"></i> Search</button>
                    @if($q !== '' || $active_status !== '' || $sort !== 'newest')
                        <a href="{{ route('feedback.mine') }}" class="btn btn-light ml-2">Clear Filters</a>
                    @endif
                </div>
            </form>
        @endslot

        @if($total === 0)
            {{-- Loop 118: the reference's empty state keeps the table
                 header row above the empty illustration. --}}
            <table class="table l118-fb-empty-table">
                <thead><tr><th style="width:3rem">#</th><th>Report</th><th>Category</th><th>Status</th><th>Submitted On</th><th>Admin Response</th><th>Actions</th></tr></thead>
                <tbody>
                <tr>
                    <td colspan="7" class="l118-fb-empty-cell">
                        <div class="l87-empty">
                            <div class="l87-empty-art" aria-hidden="true">
                                <span class="l87-empty-doc"><i class="icon-file-text2"></i></span>
                                <span class="l87-empty-bubble"><i class="icon-bubbles"></i></span>
                            </div>
                            <h5 class="l87-empty-title">No reports yet</h5>
                            <p class="l87-empty-text">You have not reported anything yet. Use the New Report button to send feedback, suggestions or issues to the school admin.</p>
                            <a href="{{ route('feedback.create') }}" class="btn btn-primary"><i class="icon-plus2 mr-1"></i> New Report</a>
                        </div>
                    </td>
                </tr>
                </tbody>
            </table>
        @else
        <table class="table">
            <thead><tr><th style="width:3rem">#</th><th>Report</th><th>Category</th><th>Status</th><th>Submitted On</th><th>Admin Response</th><th>Actions</th></tr></thead>
            <tbody>
            @forelse($items as $item)
                <tr>
                    <td class="text-muted">{{ $items->firstItem() + $loop->index }}</td>
                    <td>
                        <a class="fb-report-title" href="{{ route('feedback.mine.show', $item->id) }}">{{ $item->title }}</a>
                        <span class="text-muted">· {{ $item->typeLabel() }}</span>
                        <div class="text-muted">{{ \Illuminate\Support\Str::limit($item->description, 90) }}</div>
                        @if($item->attachments->count())
                            <div class="mt-1">@include('pages.support_team.feedback._attachments', ['item' => $item])</div>
                        @endif
                    </td>
                    <td>
                        <span class="badge badge-light">{{ $item->categoryLabel() }}</span>
                        <span class="badge {{ ['low' => 'badge-light', 'medium' => 'badge-info', 'high' => 'badge-warning', 'urgent' => 'badge-danger'][$item->priority] ?? 'badge-info' }}">{{ $item->priorityLabel() }}</span>
                    </td>
                    <td>
                        <span class="badge {{ ['new' => 'badge-warning', 'acknowledged' => 'badge-info', 'in_progress' => 'badge-primary', 'resolved' => 'badge-success', 'wont_fix' => 'badge-secondary', 'duplicate' => 'badge-light'][$item->status] ?? 'badge-light' }}">{{ $item->statusLabel() }}</span>
                        @if($item->isDuplicate())
                            <div class="text-muted mt-1" style="font-size:.85em">
                                Merged with a similar report{{ $item->duplicateOf ? ' (#' . $item->duplicateOf->id . ')' : '' }} —
                                now tracking: <strong>{{ $item->effectiveStatusLabel() }}</strong>
                            </div>
                        @endif
                    </td>
                    <td style="white-space:nowrap">{{ $item->created_at->format('d M Y') }}<div class="text-muted">{{ $item->created_at->format('h:i A') }}</div></td>
                    <td>
                        @if($item->admin_note)
                            <span class="l87-resp-yes"><i class="icon-checkmark-circle2 mr-1"></i>Admin responded</span>
                            <div class="text-muted">{{ \Illuminate\Support\Str::limit($item->admin_note, 70) }}</div>
                        @else
                            <span class="text-muted"><i class="icon-hour-glass mr-1"></i>Awaiting response</span>
                        @endif
                    </td>
                    <td><a href="{{ route('feedback.mine.show', $item->id) }}" class="btn btn-light btn-sm"><i class="icon-eye mr-1"></i> View</a></td>
                </tr>
            @empty
                <tr><td colspan="7" class="fb-empty text-muted">No reports match the current filters. <a href="{{ route('feedback.mine') }}">Clear filters</a> to see everything you have reported.</td></tr>
            @endforelse
            </tbody>
        </table>
        {{ $items->links() }}
        @endif
    @endcomponent
  </div>
@endsection
