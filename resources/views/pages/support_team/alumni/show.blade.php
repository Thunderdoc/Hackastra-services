@extends('layouts.master')
@section('page_title', 'Alumnus Details')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Alumni &nbsp;/&nbsp; <a href="{{ route('alumni.index') }}">Alumni Directory</a> &nbsp;/&nbsp; {!! $__env->yieldContent('page_title') !!}
@endsection
@section('content')
    <div class="panel panel-flat">
        <div class="panel-heading">
            <h5 class="panel-title">Alumnus Details</h5>
            <p class="text-muted" style="margin: 6px 0 0;">
                Read-only record of a graduated student. Nothing on this page can be edited or deleted.
            </p>
        </div>
        <div class="panel-body">
            <div class="table-responsive">
                <table class="table table-striped hk-std hk-std-560">
                {{-- Inside-Matter Job 2 (2026-11-08): hk-std standard — colgroup widths sum to 100%. --}}
                <colgroup><col style="width:30%"><col style="width:70%"></colgroup>
                    <tbody>
                        <tr>
                            <th>Student</th>
                            <td>{{ optional($alumnus->user)->name ?? '—' }}</td>
                        </tr>
                        <tr>
                            <th>Admission No.</th>
                            <td class="hk-nw">{{ $alumnus->adm_no ?? '—' }}</td>
                        </tr>
                        <tr>
                            <th>Class</th>
                            <td>{{ optional($alumnus->my_class)->name ?? '—' }}</td>
                        </tr>
                        <tr>
                            <th>Section</th>
                            <td>{{ optional($alumnus->section)->name ?? '—' }}</td>
                        </tr>
                        <tr>
                            <th>Session</th>
                            <td class="hk-nw">{{ $alumnus->session }}</td>
                        </tr>
                        <tr>
                            <th>Graduation</th>
                            <td>{{ $alumnus->grad_date ?: $alumnus->session }}</td>
                        </tr>
                        <tr>
                            <th>Year Admitted</th>
                            <td>{{ $alumnus->year_admitted ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <a href="{{ route('alumni.index') }}" class="btn btn-default">Back to Alumni Directory</a>
        </div>
    </div>
@endsection
