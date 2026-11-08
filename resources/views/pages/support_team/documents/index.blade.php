@extends('layouts.master')
@section('page_title', 'Student Documents')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Documents &nbsp;/&nbsp; {!! $__env->yieldContent('page_title') !!}
@endsection
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">Student Documents Vault</h6>
            {!! Qs::getPanelOptions() !!}
        </div>
        <div class="card-body">
            <p class="text-muted">Certificates and ID proofs. Uploads are admin-only; downloads are served through the system and limited to the student, their parent, or an admin.</p>
            @if($is_admin)
                <div class="mb-3">
                    <a href="{{ route('documents.create') }}" class="btn btn-success btn-sm">Upload Document</a>
                </div>
            @endif
            <div class="table-responsive">
                <table class="table table-sm hk-std hk-std-720 l108-stack">
                    {{-- Inside-Matter Job 2 (2026-11-08): hk-std standard — colgroup widths sum to 100%. --}}
                    <colgroup><col style="width:24%"><col style="width:20%"><col style="width:20%"><col style="width:9%"><col style="width:11%"><col style="width:16%"></colgroup>
                    <thead><tr><th>Title</th><th>Student</th><th>File</th><th>Size</th><th>Uploaded</th><th></th></tr></thead>
                    <tbody>
                    @forelse($documents as $doc)
                        <tr>
                            <td data-label="Title" class="hk-ellip">{{ $doc->title }}</td>
                            <td data-label="Student">{{ optional(optional($doc->studentRecord)->user)->name ?? '—' }}</td>
                            <td data-label="File" class="hk-ellip">{{ $doc->original_name }}</td>
                            <td data-label="Size" class="hk-nw">{{ $doc->size_bytes ? round($doc->size_bytes / 1024, 1) . ' KB' : '—' }}</td>
                            <td data-label="Uploaded" class="hk-nw">{{ $doc->created_at ? $doc->created_at->format('d M Y') : '—' }}</td>
                            <td data-label="Actions">
                                @if(($doc->mime_type ?? null) === 'application/pdf')
                                    <a href="{{ route('documents.view', $doc->id) }}" target="_blank" rel="noopener" class="btn btn-light btn-sm"><i class="icon-eye mr-1"></i> View</a>
                                @endif
                                <a href="{{ route('documents.download', $doc->id) }}" class="btn btn-light btn-sm">Download</a>
                                @if($is_admin)
                                    <form method="POST" action="{{ route('documents.destroy', $doc->id) }}" class="d-inline" data-confirm="Delete {{ $doc->title }} and its file? This cannot be undone." data-confirm-title="Delete Document" data-confirm-action="Delete" data-confirm-danger>
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-light btn-sm">Delete</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6">@include('partials.patterns.empty_state', ['icon' => 'icon-file-text2', 'message' => 'No documents yet.'])</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            {{ $documents->links() }}
        </div>
    </div>
@endsection
