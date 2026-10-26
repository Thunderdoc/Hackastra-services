@extends('layouts.master')
@section('page_title', 'Share Assignment')
@section('content')
    @component('partials.patterns.form_page', [
        'form_action' => route('assignments.share.store', $assignment->id),
        'back' => route('assignments.index'),
        'submit_label' => 'Share to selected sections',
    ])
        <div class="pat-form-section">
            <p class="text-muted">Sharing <strong>{{ $assignment->title }}</strong> ({{ $subject->name ?? '' }} — {{ $assignment->section->name ?? '' }}) to your other sections. The seeded copy starts with no submissions; the question file is copied by the system — you do not recreate it.</p>
            @if($targets->isEmpty())
                <p class="text-muted">No other section to share to: you teach this subject in no other section of this class.</p>
            @else
            <div class="pat-fields">
                @foreach($targets as $section)
                <div class="pat-field">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="section_ids[]" id="section_{{ $section->id }}" value="{{ $section->id }}" {{ in_array((int) $section->id, $already, true) ? 'disabled' : '' }}>
                        <label class="form-check-label" for="section_{{ $section->id }}">{{ $section->my_class->name ?? '' }} {{ $section->name }}{{ in_array((int) $section->id, $already, true) ? ' — already shared' : '' }}</label>
                    </div>
                </div>
                @endforeach
            </div>
            @endif
        </div>
    @endcomponent
@endsection
