@extends('layouts.master')
@section('page_title', 'School Feedback')
@section('content')
    {{-- School Feedback (Workstream CW): the Super Admin sees ONLY
         what school Admins compiled and forwarded — never the raw
         school inbox. The Admin is the filter, per the workflow. --}}
  <div class="l75-fb">
    @component('partials.patterns.list_page')
        @slot('subtitle')
            Reports forwarded by the school Admin after de-duplication. Open one to record the fix or decision — the reporter sees your reply.
        @endslot

        @slot('stats')
            <div class="row dashboard-stats">
                @foreach([
                    ['label' => 'Forwarded to You', 'value' => $summary['total'], 'sub' => 'Compiled by the school Admin', 'icon' => 'icon-bubbles', 'tone' => 'blue'],
                    ['label' => 'Received', 'value' => $summary['by_status']['received'] ?? 0, 'sub' => 'Not yet picked up', 'icon' => 'icon-drawer', 'tone' => 'orange'],
                    ['label' => 'Fixed', 'value' => $summary['by_status']['fixed'] ?? 0, 'sub' => 'Resolved at platform level', 'icon' => 'icon-checkmark-circle2', 'tone' => 'green'],
                    ['label' => 'Duplicates Merged', 'value' => $summary['duplicates_merged'], 'sub' => 'Filtered out by the Admin', 'icon' => 'icon-copy', 'tone' => 'purple'],
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
            <form method="get" action="{{ route('feedback.sa_index') }}" class="pat-filter-form">
                <div class="pat-filter-field">
                    <label for="fb-sa-status">Handling</label>
                    <select id="fb-sa-status" name="sa_status" class="form-control">
                        <option value="">All</option>
                        @foreach($sa_statuses as $value => $label)
                            <option value="{{ $value }}" {{ request('sa_status') == $value ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-filter-field">
                    <label for="fb-sa-type">Type</label>
                    <select id="fb-sa-type" name="type" class="form-control">
                        <option value="">All types</option>
                        @foreach($types as $value => $label)
                            <option value="{{ $value }}" {{ request('type') == $value ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-filter-field">
                    <label for="fb-sa-category">Area</label>
                    <select id="fb-sa-category" name="category" class="form-control">
                        <option value="">All areas</option>
                        @foreach($categories as $value => $label)
                            <option value="{{ $value }}" {{ request('category') == $value ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-filter-field">
                    <label for="fb-sa-priority">Priority</label>
                    <select id="fb-sa-priority" name="priority" class="form-control">
                        <option value="">All priorities</option>
                        @foreach($priorities as $value => $label)
                            <option value="{{ $value }}" {{ request('priority') == $value ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-filter-action">
                    <button class="btn btn-primary" type="submit">Filter</button>
                    <a href="{{ route('feedback.sa_index') }}" class="btn btn-light">Reset</a>
                </div>
            </form>
        @endslot

        <table class="table">
            <thead><tr><th>Report</th><th>Reporter</th><th>Handling</th><th>Forwarded</th><th></th></tr></thead>
            <tbody>
            @forelse($items as $item)
                <tr>
                    <td>
                        <a class="fb-report-title" href="{{ route('feedback.sa_show', $item->id) }}">#{{ $item->id }} {{ $item->title }}</a>
                        <span class="text-muted">· {{ $item->typeLabel() }}</span>
                        <div class="text-muted">{{ \Illuminate\Support\Str::limit($item->description, 80) }}</div>
                        @if($item->admin_note)
                            <div class="text-muted" style="font-size:.85em">Admin note: {{ \Illuminate\Support\Str::limit($item->admin_note, 80) }}</div>
                        @endif
                    </td>
                    <td>{{ $item->reporter_name }}<div class="text-muted">{{ ucwords(str_replace('_', ' ', $item->reporter_role)) }}</div></td>
                    <td>@include('pages.support_team.feedback._chips', ['item' => $item])</td>
                    <td style="white-space:nowrap">{{ $item->forwarded_at->format('d M Y') }}</td>
                    <td><a href="{{ route('feedback.sa_show', $item->id) }}" class="btn btn-light btn-sm">Open</a></td>
                </tr>
            @empty
                <tr><td colspan="5" class="fb-empty text-muted">Nothing forwarded yet. School Admins forward compiled, de-duplicated reports here.</td></tr>
            @endforelse
            </tbody>
        </table>
        {{ $items->links() }}
    @endcomponent
  </div>
@endsection
