@extends('layouts.master')
@section('page_title', $material->title)
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Learning &nbsp;/&nbsp; <a href="{{ route('materials.index') }}">Study Materials</a> &nbsp;/&nbsp; {{ \Illuminate\Support\Str::limit($material->title, 40) }}
@endsection
@section('content')
    {{-- R3 LMS P1: in-platform reader. The file bytes are served by
         materials.view (inline, Range-capable); this page is only
         the slim toolbar + embed. Download stays first-class. --}}
    <div class="card mb-2">
        <div class="card-body py-2">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <div class="d-flex align-items-center flex-wrap">
                    <a href="{{ route('materials.index') }}" class="btn btn-light btn-sm mr-2"><i class="icon-arrow-left13 mr-1"></i> Back to Materials</a>
                    <strong class="mr-2">{{ $material->title }}</strong>
                    <span class="lms-chip lms-chip-subject"><i class="icon-book mr-1"></i>{{ optional($material->subject)->name ?? 'Subject —' }}</span>
                    <span class="lms-chip"><i class="icon-users mr-1"></i>{{ optional($material->myClass)->name ?? 'Class —' }}{{ $material->section ? ' · ' . optional($material->section)->name : ' · Whole class' }}</span>
                </div>
                <div class="mt-1 mt-md-0" style="white-space:nowrap">
                    <a href="{{ route('materials.view', $material->id) }}" target="_blank" rel="noopener" class="btn btn-light btn-sm"><i class="icon-enlarge mr-1"></i> Open in new tab</a>
                    <a href="{{ route('materials.download', $material->id) }}" class="btn btn-primary btn-sm"><i class="icon-download mr-1"></i> Download</a>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            @if($is_image)
                <div class="text-center p-2">
                    <img src="{{ route('materials.view', $material->id) }}" alt="{{ $material->title }}" class="lms-viewer-img">
                </div>
            @else
                <iframe src="{{ route('materials.view', $material->id) }}" class="lms-viewer-frame" title="{{ $material->title }}"></iframe>
            @endif
        </div>
    </div>
@endsection
