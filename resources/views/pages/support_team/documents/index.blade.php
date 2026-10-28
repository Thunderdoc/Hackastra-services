@extends('layouts.master')
@section('page_title', 'Student Documents')
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
                <table class="table table-sm">
                    <thead><tr><th>Title</th><th>Student</th><th>File</th><th>Size</th><th>Uploaded</th><th></th></tr></thead>
                    <tbody>
                    @forelse($documents as $doc)
                        <tr>
                            <td>{{ $doc->title }}</td>
                            <td>{{ optional(optional($doc->studentRecord)->user)->name ?? '—' }}</td>
                            <td>{{ $doc->original_name }}</td>
                            <td>{{ $doc->size_bytes ? round($doc->size_bytes / 1024, 1) . ' KB' : '—' }}</td>
                            <td>{{ $doc->created_at ? $doc->created_at->format('d M Y') : '—' }}</td>
                            <td>
                                <a href="{{ route('documents.download', $doc->id) }}" class="btn btn-light btn-sm">Download</a>
                                @if($is_admin)
                                    <form method="POST" action="{{ route('documents.destroy', $doc->id) }}" class="d-inline" onsubmit="return confirm('Delete this document and its file?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-light btn-sm">Delete</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted">No documents yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            {{ $documents->links() }}
        </div>
    </div>
@endsection
