@extends('layouts.master')
@section('page_title', 'Audit Log')
@section('content')
  {{-- Item 19 (2026-11-01): audit tooling — search + user/action/
       date filters, quick-filter pills by action group with counts,
       plain-English action labels (codes stay stored), subject
       NAMES resolved from the stored ids, 15/page, CSV export of
       the filtered set. Visibility scope unchanged (controller). --}}
  <div class="card au-head">
      <span class="au-ic"><i class="icon-history"></i></span>
      <div>
          <h2>Audit Log</h2>
          <p>Who did what, and when — every security-relevant action</p>
      </div>
      <span class="au-count">{{ number_format($logs->total()) }} entries</span>
      <a class="btn btn-primary au-export" href="{{ route('audit_logs.export', request()->query()) }}"><i class="icon-download7"></i> Export CSV</a>
  </div>

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
          <div class="au-empty">
              @include('partials.patterns.empty_state', [
                  'icon' => 'icon-history',
                  'message' => array_filter($filters) || $active_group
                      ? 'No audit entries match these filters.'
                      : 'No audit entries yet. Logins, payments, mark updates and deletions will appear here.',
              ])
          </div>
      @else
          <div class="table-responsive">
              <table class="au-table">
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
                          $initials = $log->user ? collect(explode(' ', trim($log->user->name)))->map(function ($w) { return strtoupper(substr($w, 0, 1)); })->take(2)->implode('') : '—';
                      @endphp
                      <tr>
                          <td class="au-when"><b>{{ $log->created_at->format('d M Y') }}</b><span>{{ $log->created_at->format('h:i A') }}</span></td>
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
