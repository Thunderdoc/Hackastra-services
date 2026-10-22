@extends('layouts.master')
@section('page_title', 'Study Materials')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Home</a> &nbsp;/&nbsp; Study Materials
@endsection
@section('content')
    {{-- Workstream CS2 (LMS): study materials grouped by subject on
         the shared list_page pattern (Loop 28). Teachers (and teamSA)
         get Add/Delete; students and parents open files and links
         read-only. $materials is CS1's contract variable. --}}
    @if(session('flash_success'))
        <div class="alert alert-success border-0">{{ session('flash_success') }}</div>
    @endif
    @if(session('flash_danger'))
        <div class="alert alert-danger border-0">{{ session('flash_danger') }}</div>
    @endif

    @php
        $canManage = Qs::userIsTeacher() || Qs::userIsTeamSA();
        $grouped = $materials->groupBy(function ($m) { return optional($m->subject)->name ?? 'General'; });
    @endphp

    @component('partials.patterns.list_page')
        @slot('title')
            <i class="icon-book mr-2"></i> Study Materials
        @endslot
        @slot('subtitle')
            Notes, slides, videos and links shared by teachers — everything for study, in one place.
        @endslot
        @if($canManage)
            @slot('action')
                <a href="{{ route('materials.create') }}" class="btn btn-primary"><i class="icon-plus2 mr-1"></i> Add Material</a>
            @endslot
        @endif

        @forelse($grouped as $subjectName => $items)
            <div class="lms-mat-group">
                <h6 class="lms-mat-group-title"><i class="icon-book mr-1"></i>{{ $subjectName }}</h6>
                @foreach($items as $material)
                    <div class="lms-mat-row">
                        <span class="lms-mat-icon"><i class="{{ $material->file_path ? 'icon-file-text2' : 'icon-link' }}"></i></span>
                        <span class="lms-mat-copy">
                            <strong>{{ $material->title }}</strong>
                            <small>
                                {{ optional($material->myClass)->name ?? 'Class —' }}{{ $material->section ? ' · ' . optional($material->section)->name : ' · Whole class' }}
                                &middot; by {{ optional($material->teacher)->name ?? 'Teacher' }}
                                @if($material->created_at) &middot; {{ \Carbon\Carbon::parse($material->created_at)->format('d M Y') }}@endif
                            </small>
                            @if($material->description)
                                <span class="lms-mat-desc">{{ \Illuminate\Support\Str::limit($material->description, 160) }}</span>
                            @endif
                        </span>
                        <span class="lms-mat-actions">
                            @if($material->file_path)
                                <a href="{{ route('materials.download', $material->id) }}" class="btn btn-light btn-sm"><i class="icon-download mr-1"></i> Download</a>
                            @endif
                            @if($material->link_url)
                                <a href="{{ $material->link_url }}" target="_blank" rel="noopener" class="btn btn-light btn-sm"><i class="icon-link mr-1"></i> Open Link</a>
                            @endif
                            @if($canManage)
                                <form method="post" action="{{ route('materials.destroy', $material->id) }}" style="display:inline">@csrf @method('delete')<button type="submit" class="btn btn-light btn-sm text-danger" data-confirm="Delete this study material?" data-confirm-title="Delete Material" data-confirm-action="Delete" data-confirm-danger><i class="icon-trash mr-1"></i> Delete</button></form>
                            @endif
                        </span>
                    </div>
                @endforeach
            </div>
        @empty
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-book',
                'message' => $canManage
                    ? 'No study materials yet. Add the first notes, slides or video link for your classes.'
                    : 'No study materials yet. When your teachers share notes or links, they will appear here.',
                'action_url' => $canManage ? route('materials.create') : null,
                'action_label' => 'Add Material',
            ])
        @endforelse

        @if(method_exists($materials, 'links'))
            {{ $materials->links() }}
        @endif
    @endcomponent
@endsection
