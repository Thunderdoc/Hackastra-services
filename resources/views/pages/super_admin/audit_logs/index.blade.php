@extends('layouts.master')
@section('page_title', 'Audit Log')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Platform &nbsp;/&nbsp; {!! $__env->yieldContent('page_title') !!}
@endsection
@section('content')
  {{-- Item 19 (2026-11-01): audit tooling — search + user/action/
       date filters, quick-filter pills by action group with counts,
       plain-English action labels (codes stay stored), subject
       NAMES resolved from the stored ids, 15/page, CSV export of
       the filtered set. Visibility scope unchanged (controller). --}}
  {{-- Beauty Batch 3 (audit B10): the private .au-head hero is
       retired — the page now opens with the shared slim module
       banner like every other module page. The header owns the
       "Audit Log" title; the banner carries the context line, the
       entry count as its stat and Export CSV as its action. The
       .au-head styles stay in qs.css unused (append-only). --}}
  @include('partials.module_banner', [
      'hkModIcon' => 'icon-history',
      'hkModTone' => 'purple',
      'hkModSub' => 'Who did what, and when — every security-relevant action',
      'hkModStats' => [
          ['value' => number_format($logs->total()), 'label' => 'entries'],
      ],
      'hkModActions' => '<a class="btn btn-primary" href="' . route('audit_logs.export', request()->query()) . '"><i class="icon-download7"></i> Export CSV</a>',
  ])

  <div class="card au-filters">
      <form method="GET" action="{{ route('audit_logs.index') }}" class="au-fform">
          @if($active_group)<input type="hidden" name="group" value="{{ $active_group }}">@endif
          <div class="au-fld au-grow">
              <label for="au-q">Search</label>
              <input type="text" id="au-q" name="q" class="form-control" placeholder="Search details, user, IP…" value="{{ $filters['q'] ?? '' }}">
          </div>
          <div class="au-fld">
              <label for="au-user">User</label>
              <select id="au-user" name="user_id" class="form-control">
                  <option value="">All users</option>
                  @foreach($filter_users as $u)
                      <option value="{{ $u->id }}" {{ (string)($filters['user_id'] ?? '') === (string)$u->id ? 'selected' : '' }}>{{ $u->name }}</option>
                  @endforeach
              </select>
          </div>
          <div class="au-fld">
              <label for="au-action">Action</label>
              <select id="au-action" name="action" class="form-control">
                  <option value="">All actions</option>
                  @foreach($filter_actions as $a)
                      <option value="{{ $a }}" {{ ($filters['action'] ?? '') === $a ? 'selected' : '' }}>{{ \App\Support\AuditLabels::label($a) }}</option>
                  @endforeach
              </select>
          </div>
          <div class="au-fld">
              <label for="au-from">From</label>
              <input type="date" id="au-from" name="date_from" class="form-control" value="{{ $filters['date_from'] ?? '' }}">
          </div>
          <div class="au-fld">
              <label for="au-to">To</label>
              <input type="date" id="au-to" name="date_to" class="form-control" value="{{ $filters['date_to'] ?? '' }}">
          </div>
          <div class="au-fbtns">
              <button type="submit" class="btn btn-primary">Filter</button>
              <a class="btn btn-light" href="{{ route('audit_logs.index') }}">Reset</a>
          </div>
      </form>
      <div class="mt-2">
          @include('partials.patterns.saved_views', ['svList' => 'audit'])
      </div>
      <div class="au-quick">
          <a class="au-qpill {{ !$active_group ? 'on' : '' }}" href="{{ route('audit_logs.index', array_merge($filters, ['group' => null])) }}">All <b>{{ number_format(array_sum($group_counts)) }}</b></a>
          @foreach(\App\Support\AuditLabels::$groups as $key => $meta)
              @if(!empty($group_counts[$key]))
                  <a class="au-qpill {{ $active_group === $key ? 'on' : '' }}" href="{{ route('audit_logs.index', array_merge($filters, ['group' => $key])) }}">{{ $meta[0] }} <b>{{ number_format($group_counts[$key]) }}</b></a>
              @endif
          @endforeach
          @if(!empty($group_counts['other']))
              <a class="au-qpill {{ $active_group === 'other' ? 'on' : '' }}" href="{{ route('audit_logs.index', array_merge($filters, ['group' => 'other'])) }}">Other <b>{{ number_format($group_counts['other']) }}</b></a>
          @endif
      </div>
  </div>

  <div class="card au-tablecard">
      @if($logs->isEmpty())
          @include('partials.patterns.empty_state', [
                  'icon' => 'icon-history',
                  'message' => array_filter($filters) || $active_group
                      ? 'No audit entries match these filters.'
                      : 'No audit entries yet. Logins, payments, mark updates and deletions will appear here.',
              ])
      @else
          <div class="table-responsive">
              <table class="au-table hk-std hk-std-audit">
                  {{-- Beauty Batch 4 (audit D group): fixed layout, widths sum to 100%. --}}
                  <colgroup><col style="width:18%"><col style="width:23%"><col style="width:17%"><col style="width:29%"><col style="width:13%"></colgroup>
                  <thead>
                  <tr>
                      <th>When</th>
                      <th>User</th>
                      <th>Action</th>
                      <th>Subject</th>
                      <th>IP</th>
                  </tr>
                  </thead>
                  <tbody>
                  @foreach($logs as $log)
                      @php
                          $subjectName = ($log->subject_type && $log->subject_id)
                              ? ($subject_names[$log->subject_type . '#' . $log->subject_id] ?? null)
                              : null;
                          $initials = $log->user ? collect(explode(' ', trim((string) ($log->user->name ?? ''))))->map(function ($w) { return strtoupper(substr($w, 0, 1)); })->take(2)->implode('') : '—';
                          if ($initials === '') { $initials = '—'; }
                      @endphp
                      <tr>
                          <td class="au-when">@if($log->created_at)<b>{{ $log->created_at->format('d M Y') }}</b><span>{{ $log->created_at->format('h:i A') }}</span>@else<b>—</b>@endif</td>
                          <td>
                              <span class="au-user">
                                  <span class="au-ava">{{ $initials }}</span>
                                  <span><b>{{ optional($log->user)->name ?: '—' }}</b><span>{{ $log->user ? ucfirst(str_replace('_', ' ', $log->user->user_type)) : 'System' }}</span></span>
                              </span>
                          </td>
                          <td><span class="au-act {{ \App\Support\AuditLabels::tone($log->action) }}" title="{{ $log->action }}">{{ \App\Support\AuditLabels::label($log->action) }}</span></td>
                          <td class="au-subj">
                              @if($subjectName)<b>{{ $subjectName }}</b>@elseif($log->subject_type)<b>{{ class_basename($log->subject_type) }}{{ $log->subject_id ? ' #' . $log->subject_id : '' }}</b>@endif
                              @if($log->description)<span>{{ $log->description }}</span>@endif
                              @if(!$subjectName && !$log->subject_type && !$log->description)—@endif
                          </td>
                          <td class="au-nowrap">{{ $log->ip_address ?: '—' }}</td>
                      </tr>
                  @endforeach
                  </tbody>
              </table>
          </div>
          <div class="au-pager">
              <span>Showing {{ $logs->firstItem() }} to {{ $logs->lastItem() }} of {{ number_format($logs->total()) }} entries</span>
              <span class="au-pglinks">{{ $logs->links() }}</span>
          </div>
      @endif
  </div>
@endsection
