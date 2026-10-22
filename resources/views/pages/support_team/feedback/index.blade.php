@extends('layouts.master')
@section('page_title', 'Feedback Inbox')
@section('content')
    {{-- Feedback Inbox (Workstream CW): the school Admin's queue.
         Compile flow: triage (status/note) → merge duplicates into a
         canonical report → forward the compiled set to the Super
         Admin with the bulk action at the bottom of the table. --}}
    @if(session('flash_success'))
        <div class="alert alert-success border-0">{{ session('flash_success') }}</div>
    @endif

    @component('partials.patterns.list_page')
        @slot('subtitle')
            All feedback and issues reported in the school. Merge duplicates first, then forward the compiled reports to the Super Admin.
        @endslot
        @slot('action')
            <a href="{{ route('feedback.create') }}" class="btn btn-light"><i class="icon-plus2 mr-1"></i> New Report</a>
        @endslot

        @slot('stats')
            <div class="row dashboard-stats">
                @foreach([
                    ['label' => 'New', 'value' => $counts['new'] ?? 0, 'sub' => 'Waiting for triage', 'icon' => 'icon-bubbles', 'tone' => 'orange'],
                    ['label' => 'In Progress', 'value' => ($counts['acknowledged'] ?? 0) + ($counts['in_progress'] ?? 0), 'sub' => 'Acknowledged + being worked on', 'icon' => 'icon-stack', 'tone' => 'blue'],
                    ['label' => 'Resolved', 'value' => $counts['resolved'] ?? 0, 'sub' => 'Closed by the school', 'icon' => 'icon-checkmark-circle2', 'tone' => 'green'],
                    ['label' => 'Duplicates Merged', 'value' => $counts['duplicate'] ?? 0, 'sub' => 'Folded into canonical reports', 'icon' => 'icon-copy', 'tone' => 'purple'],
                ] as $stat)
                    <div class="col-sm-6 col-xl-3">
                        <div class="dashboard-stat-card dashboard-stat-{{ $stat['tone'] }}">
                            <span class="dashboard-stat-top">
                                <span class="dashboard-stat-icon"><i class="{{ $stat['icon'] }}"></i></span>
                            </span>
                            <span class="dashboard-stat-copy">
                                <span class="dashboard-stat-label">{{ $stat['label'] }}</span>
                                <strong>{{ number_format($stat['value']) }}</strong>
                                <small>{{ $stat['sub'] }}</small>
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>
        @endslot

        @slot('filters')
            <form method="get" action="{{ route('feedback.index') }}" class="pat-filter-form">
                <div class="pat-filter-field">
                    <label for="fb-status">Status</label>
                    <select id="fb-status" name="status" class="form-control">
                        <option value="">All statuses</option>
                        @foreach($statuses as $value => $label)
                            <option value="{{ $value }}" {{ request('status') == $value ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-filter-field">
                    <label for="fb-type">Type</label>
                    <select id="fb-type" name="type" class="form-control">
                        <option value="">All types</option>
                        @foreach($types as $value => $label)
                            <option value="{{ $value }}" {{ request('type') == $value ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-filter-field">
                    <label for="fb-category">Area</label>
                    <select id="fb-category" name="category" class="form-control">
                        <option value="">All areas</option>
                        @foreach($categories as $value => $label)
                            <option value="{{ $value }}" {{ request('category') == $value ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-filter-field">
                    <label for="fb-priority">Priority</label>
                    <select id="fb-priority" name="priority" class="form-control">
                        <option value="">All priorities</option>
                        @foreach($priorities as $value => $label)
                            <option value="{{ $value }}" {{ request('priority') == $value ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-filter-action">
                    <button class="btn btn-primary" type="submit">Filter</button>
                    <a href="{{ route('feedback.index') }}" class="btn btn-light">Reset</a>
                </div>
            </form>
        @endslot

        <form method="post" action="{{ route('feedback.forward') }}">
            @csrf
            <table class="table">
                <thead><tr>
                    <th style="width:34px"><span class="sr-only">Select to forward</span></th>
                    <th>Report</th><th>Reporter</th><th>Status</th><th>Reported</th><th></th>
                </tr></thead>
                <tbody>
                @forelse($items as $item)
                    <tr>
                        <td>
                            @if(!$item->isDuplicate() && !$item->isForwarded())
                                <input type="checkbox" name="ids[]" value="{{ $item->id }}" aria-label="Select report #{{ $item->id }} to forward">
                            @endif
                        </td>
                        <td>
                            <a href="{{ route('feedback.show', $item->id) }}"><strong>#{{ $item->id }} {{ $item->title }}</strong></a>
                            <span class="text-muted">· {{ $item->typeLabel() }}</span>
                            <div class="text-muted">{{ \Illuminate\Support\Str::limit($item->description, 80) }}</div>
                            @if($item->isDuplicate())
                                <div class="text-muted" style="font-size:.85em">Duplicate of #{{ $item->duplicate_of_id }}</div>
                            @elseif($item->isForwarded())
                                <div class="text-muted" style="font-size:.85em">Forwarded {{ $item->forwarded_at->format('d M Y') }}</div>
                            @endif
                        </td>
                        <td>{{ $item->reporter_name }}<div class="text-muted">{{ ucwords(str_replace('_', ' ', $item->reporter_role)) }}</div></td>
                        <td>@include('pages.support_team.feedback._chips', ['item' => $item])</td>
                        <td style="white-space:nowrap">{{ $item->created_at->format('d M Y') }}</td>
                        <td><a href="{{ route('feedback.show', $item->id) }}" class="btn btn-light btn-sm">Open</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted">No feedback matches. New reports from anyone in the school land here.</td></tr>
                @endforelse
                </tbody>
            </table>
            <div class="d-flex justify-content-between align-items-center flex-wrap mt-2">
                <button type="submit" class="btn btn-primary" data-confirm="Forward the selected reports to the Super Admin? Duplicates and already-forwarded items are skipped." data-confirm-title="Forward to Super Admin" data-confirm-action="Forward">
                    <i class="icon-share2 mr-1"></i> Forward selected to Super Admin
                </button>
                <span class="text-muted">Tick the canonical reports to compile &amp; forward.</span>
            </div>
        </form>
        {{ $items->links() }}
    @endcomponent
@endsection
