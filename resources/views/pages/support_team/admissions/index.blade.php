@extends('layouts.master')
@section('page_title', 'Admission Enquiries')
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">Admission Enquiries</h6>
            {!! Qs::getPanelOptions() !!}
        </div>
        <div class="card-body">
            <p class="text-muted">Every public enquiry lands here. Work it through the pipeline — contact, schedule a test, approve — then Convert pre-fills the Admit Student form. Mark an enquiry Converted on this board once the student record actually exists.</p>
            <div class="mb-3">
                <a href="{{ route('admissions.index') }}" class="btn btn-sm {{ $status === 'all' ? 'btn-primary' : 'btn-light' }}">All ({{ $admissions->total() }})</a>
                @foreach($statuses as $key => $label)
                    <a href="{{ route('admissions.index', ['status' => $key]) }}" class="btn btn-sm {{ $status === $key ? 'btn-primary' : 'btn-light' }}">{{ $label }} ({{ $counts[$key] ?? 0 }})</a>
                @endforeach
            </div>
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead><tr><th>#</th><th>Name</th><th>Phone</th><th>Class Sought</th><th>Message</th><th>Received</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                    @forelse($admissions as $a)
                        <tr>
                            <td>{{ $a->id }}</td>
                            <td>{{ $a->name }}</td>
                            <td>{{ $a->phone }}</td>
                            <td>{{ optional($a->myClass)->name ?? '—' }}</td>
                            <td>{{ \Illuminate\Support\Str::limit($a->message, 80) }}</td>
                            <td>{{ $a->created_at ? $a->created_at->format('d M Y') : '—' }}</td>
                            <td><span class="badge badge-{{ $a->status === 'new' ? 'primary' : ($a->status === 'converted' ? 'success' : ($a->status === 'rejected' ? 'danger' : 'info')) }}">{{ $a->status_label }}</span></td>
                            <td>
                                <form method="POST" action="{{ route('admissions.status', $a->id) }}" class="form-inline">
                                    @csrf
                                    <select name="status" class="form-control form-control-sm mr-1">
                                        @foreach($statuses as $key => $label)
                                            <option value="{{ $key }}" {{ $a->status === $key ? 'selected' : '' }}>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    <button type="submit" class="btn btn-light btn-sm mr-1">Move</button>
                                </form>
                                <a href="{{ route('admissions.convert', $a->id) }}" class="btn btn-success btn-sm mt-1">Convert → Admit</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted">No enquiries yet — the public form at /admission-enquiry feeds this board.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            {{ $admissions->appends(['status' => $status])->links() }}
        </div>
    </div>
@endsection
