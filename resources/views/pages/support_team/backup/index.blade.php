@extends('layouts.master')
@section('page_title', 'Backup & Monitoring')
@section('content')
    <style>
        .bk-wrap { max-width: 100%; }
        .bk-card { background: #fff; border: 1px solid #ddd; border-radius: 6px; padding: 14px; margin-bottom: 12px; max-width: 100%; }
        .bk-card h4 { margin: 0 0 8px 0; font-size: 16px; }
        .bk-card p { margin: 0 0 8px 0; font-size: 14px; line-height: 1.5; word-wrap: break-word; overflow-wrap: anywhere; }
        .bk-row { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 4px 12px; padding: 6px 0; border-top: 1px solid #eee; font-size: 14px; }
        .bk-row span:first-child { word-wrap: break-word; overflow-wrap: anywhere; }
        .bk-ok { color: #2e7d32; font-weight: 600; }
        .bk-fail { color: #c62828; font-weight: 600; }
        .bk-note { background: #fff8e1; border: 1px solid #ffe082; border-radius: 6px; padding: 12px; font-size: 14px; line-height: 1.5; margin-bottom: 12px; max-width: 100%; }
        /* Beauty Batch 1 (2026-11-07, audit G6): dark parity —
           the cards and the read-first note were hardcoded white;
           they now take the app dark tokens (the note keeps the
           dark warning tint, same family as alert-warning). */
        html.hk-dark .bk-card { background: #26272a; border-color: #36383c; color: #e7e8e9; }
        html.hk-dark .bk-row { border-top-color: #36383c; }
        html.hk-dark .bk-note { background: #3a3016; border-color: #5a4a1e; color: #f0dfa8; }
        html.hk-dark .bk-ok { color: #7fd79a; }
        html.hk-dark .bk-fail { color: #f2a9a9; }
    </style>
    <div class="bk-wrap">
        <div class="bk-note">
            <strong>Read this first.</strong> This page does not download the database —
            by design. School data lives on the managed database (TiDB Cloud), which
            keeps its own backups, and deploys do not erase it. For a school on its own
            host, backups are taken at the host level — see the Backup &amp; Restore
            Runbook. This page only verifies that the checks below pass, and records
            when they were last verified.
        </div>

        <div class="bk-card">
            <h4>System checks</h4>
            <div class="bk-row">
                <span>Database connection</span>
                <span class="{{ $database === 'ok' ? 'bk-ok' : 'bk-fail' }}">{{ $database === 'ok' ? 'OK' : 'FAIL' }}</span>
            </div>
            <div class="bk-row">
                <span>Public health endpoint (/health)</span>
                <span class="{{ $health === 'ok' ? 'bk-ok' : 'bk-fail' }}">{{ $health === 'ok' ? 'OK' : 'FAIL' }}</span>
            </div>
            <p>Point an uptime monitor at /health and alert on anything but a 200 response.
            After 15 idle minutes on the free tier the first hit is slow (cold start, about
            a minute) — that is the tier sleeping, not an outage.</p>
        </div>

        <div class="bk-card">
            <h4>Storage disks</h4>
            <p>Disk names and writability only. Remote disks are listed but not probed
            from the app, so no remote write is ever attempted here.</p>
            @foreach($disks as $disk)
                <div class="bk-row">
                    <span>{{ $disk['name'] }}</span>
                    <span class="{{ $disk['writable'] === 'yes' ? 'bk-ok' : ($disk['writable'] === 'no' ? 'bk-fail' : '') }}">{{ $disk['writable'] === 'yes' ? 'Writable: Yes' : ($disk['writable'] === 'no' ? 'Writable: No' : 'Not probed (remote disk)') }}</span>
                </div>
            @endforeach
        </div>

        <div class="bk-card">
            <h4>Last verified</h4>
            <p>{{ $last_verified ? 'Last verified: ' . $last_verified : 'Not verified yet.' }}</p>
            <form method="POST" action="{{ route('backup.refresh') }}">
                @csrf
                <button type="submit" class="btn btn-primary">Refresh checks</button>
            </form>
        </div>
    </div>
@endsection
