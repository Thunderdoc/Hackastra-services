@extends('layouts.master')
@section('page_title', 'School Readiness')
@section('content')

    {{-- School Readiness (program Phase 8): is the school set up
         to actually run? Every check reads live data — session,
         structure, requirements, loads, timetables, exams,
         documents, money mode — and links straight to the fix. --}}
    @component('partials.patterns.list_page')
        @slot('subtitle')
            A live checklist of what a school needs before it can run smoothly — nothing here is estimated; each line reads the real records.
        @endslot

        <div class="marx-stats">
            <div class="marx-stat {{ $score >= 80 ? 'marx-stat-present' : ($score >= 50 ? 'marx-stat-late' : 'marx-stat-absent') }}">
                <div class="marx-stat-num">{{ $score }}%</div>
                <div class="marx-stat-label">Readiness Score</div>
                <div class="marx-stat-cap">{{ $ok }} of {{ $total }} checks passing</div>
            </div>
        </div>

        <div class="table-responsive mt-3">
            <table class="table">
                <thead><tr><th style="width: 110px;">Status</th><th>Check</th><th>Detail</th><th style="width: 120px;">Fix</th></tr></thead>
                <tbody>
                @foreach($checks as $c)
                    <tr>
                        <td>
                            @if($c['status'] === 'ok')<span class="badge badge-success">Ready</span>
                            @elseif($c['status'] === 'warn')<span class="badge badge-warning">Attention</span>
                            @else<span class="badge badge-danger">Missing</span>@endif
                        </td>
                        <td><strong>{{ $c['label'] }}</strong></td>
                        <td>{{ $c['detail'] }}</td>
                        <td>@if($c['route'])<a class="btn btn-light btn-sm" href="{{ route($c['route']) }}">Open</a>@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endcomponent
@endsection
