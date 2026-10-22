@extends('layouts.master')
@section('page_title', 'Audit Log')
@section('content')
    {{-- Loop 106: rebuilt from the raw Limitless card onto the
         platform design scope (.l106) — panel + table language of the
         school Loops. Same records, columns and pagination. --}}
  <div class="l106 l106-audit">
    <section class="l106-panel">
        <div class="l106-panel-head">
            <div>
                <h5><i class="icon-history"></i> Audit Log</h5>
                <p>Who did what, and when — every security-relevant action on the platform</p>
            </div>
            <span class="l106-count">{{ number_format($logs->total()) }} entries</span>
        </div>
        @if($logs->isEmpty())
            <div class="l106-empty">
                @include('partials.patterns.empty_state', [
                    'icon' => 'icon-history',
                    'message' => 'No audit entries yet. Logins, payments, mark updates and deletions will appear here.',
                ])
            </div>
        @else
            <div class="table-responsive">
                <table class="l106-table">
                    <thead>
                    <tr>
                        <th>When</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Subject</th>
                        <th>Details</th>
                        <th>IP</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($logs as $log)
                        <tr>
                            <td class="l106-nowrap">{{ $log->created_at->format('d M Y, h:i A') }}</td>
                            <td><strong>{{ optional($log->user)->name ?: '—' }}</strong></td>
                            <td><code class="l106-code">{{ $log->action }}</code></td>
                            <td>{{ $log->subject_type ? class_basename($log->subject_type).($log->subject_id ? ' #'.$log->subject_id : '') : '—' }}</td>
                            <td>{{ $log->description ?: '—' }}</td>
                            <td class="l106-nowrap">{{ $log->ip_address ?: '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="l106-pager">{{ $logs->links() }}</div>
        @endif
    </section>
  </div>
@endsection
