@extends('layouts.master')
@section('page_title', 'Certificates')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Certificates &nbsp;/&nbsp; {!! $__env->yieldContent('page_title') !!}
@endsection
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">Certificate Register</h6>
            {!! Qs::getPanelOptions() !!}
        </div>
        <div class="card-body">
            <p class="text-muted">Every issued certificate lives here with its serial. Certificates are never deleted — the register is the audit trail.</p>
            <div class="mb-3">
                <a href="{{ route('certificates.index') }}" class="btn btn-sm {{ $type === 'all' ? 'btn-primary' : 'btn-light' }}">All</a>
                <a href="{{ route('certificates.index', ['type' => 'bonafide']) }}" class="btn btn-sm {{ $type === 'bonafide' ? 'btn-primary' : 'btn-light' }}">Bonafide</a>
                <a href="{{ route('certificates.index', ['type' => 'transfer']) }}" class="btn btn-sm {{ $type === 'transfer' ? 'btn-primary' : 'btn-light' }}">Transfer</a>
                @if(Qs::userIsTeamSAWrite())
                    <a href="{{ route('certificates.create') }}" class="btn btn-success btn-sm float-right">Issue Certificate</a>
                @endif
            </div>
            <div class="table-responsive">
                <table class="table table-sm hk-std hk-std-720">
                    {{-- Inside-Matter Job 2 (2026-11-08): hk-std standard — colgroup widths sum to 100%. --}}
                    <colgroup><col style="width:16%"><col style="width:11%"><col style="width:24%"><col style="width:12%"><col style="width:13%"><col style="width:12%"><col style="width:12%"></colgroup>
                    <thead><tr><th>Serial</th><th>Type</th><th>Student</th><th>Adm No</th><th>Class</th><th>Issued</th><th></th></tr></thead>
                    <tbody>
                    @forelse($certificates as $cert)
                        <tr>
                            <td class="hk-nw"><strong>{{ $cert->serial }}</strong></td>
                            <td>{{ ucfirst($cert->type) }}</td>
                            <td>{{ $cert->student_name }}</td>
                            <td class="hk-nw">{{ $cert->adm_no ?? '—' }}</td>
                            <td>{{ $cert->class_name ?? '—' }} {{ $cert->section_name ?? '' }}</td>
                            <td class="hk-nw">{{ $cert->issued_at ? $cert->issued_at->format('d M Y') : '—' }}</td>
                            <td><a href="{{ route('certificates.print', $cert->id) }}" class="btn btn-light btn-sm" target="_blank">Print</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="7">@include('partials.patterns.empty_state', ['icon' => 'icon-profile', 'message' => 'No certificates issued yet.'])</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            {{ $certificates->appends(['type' => $type])->links() }}
        </div>
    </div>
@endsection
