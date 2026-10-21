@extends('layouts.master')
@section('page_title', 'Audit Log')
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            <span class="text-muted">Who did what, and when</span>
            {!! Qs::getPanelOptions() !!}
        </div>
        <div class="card-body">
            @if($logs->isEmpty())
                <p class="text-muted mb-0">No audit entries yet. Logins, payments, mark updates and deletions will appear here.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-striped">
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
                                <td>{{ $log->created_at->format('d M Y, h:i A') }}</td>
                                <td>{{ optional($log->user)->name ?: '—' }}</td>
                                <td><code>{{ $log->action }}</code></td>
                                <td>{{ $log->subject_type ? class_basename($log->subject_type).($log->subject_id ? ' #'.$log->subject_id : '') : '—' }}</td>
                                <td>{{ $log->description ?: '—' }}</td>
                                <td>{{ $log->ip_address ?: '—' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                {{ $logs->links() }}
            @endif
        </div>
    </div>
@endsection
