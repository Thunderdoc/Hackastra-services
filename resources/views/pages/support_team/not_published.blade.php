@extends('layouts.master')
@section('page_title', $page_title ?? 'Not Published Yet')
@section('content')

    {{-- Publication gate (owner order 2026-11-08): the full-page
         form of the shared not-published state (see
         partials/patterns/not_published). Returned server-side by
         the student/parent document controllers — admit card and
         exam date sheet — when the school has not published the
         document yet. No document data reaches this view. --}}
    <div class="card">
        <div class="card-body py-4">
            @include('partials.patterns.not_published', [
                'document' => $document ?? 'This document',
                'icon' => $icon ?? 'icon-eye-blocked',
                'heading' => $heading ?? 'Not Published Yet',
                'detail' => $detail ?? null,
            ])
            <div class="text-center">
                <a href="{{ route('dashboard') }}" class="btn btn-primary mt-2">Back to Dashboard</a>
            </div>
        </div>
    </div>

@endsection
