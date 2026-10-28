@extends('layouts.master')
@section('page_title', 'Per-Student Net Fees')
@section('page_breadcrumb')
    Payments &nbsp;/&nbsp; Fee Structure Builder &nbsp;/&nbsp; Per-Student Net Fees
@endsection
@section('content')
    <div class="card">
        <div class="card-header"><h5 class="card-title">Students — Net Fee View</h5></div>
        <div class="card-body">
            <p>Net payable = fee head amount minus that student's concessions, computed live.</p>
            @if($students->count())
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr><th>Student</th><th>Admission No.</th><th>Class</th><th>Action</th></tr>
                        </thead>
                        <tbody>
                            @foreach($students as $sr)
                                <tr>
                                    <td>{{ optional($sr->user)->name }}</td>
                                    <td>{{ $sr->adm_no }}</td>
                                    <td>{{ optional($sr->my_class)->name }}</td>
                                    <td><a href="{{ route('fee_structure.net', ['student_record' => $sr->id]) }}" class="btn btn-sm btn-info">View Net Fees</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                {{ $students->links() }}
            @else
                <p>No students found.</p>
            @endif
        </div>
    </div>
@endsection
