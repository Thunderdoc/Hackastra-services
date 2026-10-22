@extends('layouts.master')
@section('page_title', 'Give Feedback / Report an Issue')
@section('content')
    {{-- Feedback & issue report (Workstream CW): any signed-in user
         reports to the school Admin on the shared form_page pattern.
         The hidden page_url field is the auto-log: it carries the
         ?from= deep-link target when present, else the page the
         reporter came from — the reporter never types it. --}}
    @php
        $fbFrom = request('from');
        if (!$fbFrom) {
            $fbPrev = parse_url(url()->previous());
            $fbFrom = ($fbPrev['path'] ?? '') . (!empty($fbPrev['query']) ? '?' . $fbPrev['query'] : '');
        }
    @endphp
    @component('partials.patterns.form_page', [
        'form_action' => route('feedback.store'),
        'form_enctype' => 'multipart/form-data',
        'back' => route('feedback.mine'),
    ])
        @slot('subtitle')
            Tell the school Admin about any work, facility, service or app issue — anywhere in the school. You can follow its progress under <strong>My Feedback</strong>.
        @endslot

        <input type="hidden" name="page_url" value="{{ old('page_url', $fbFrom) }}">

        <div class="pat-form-section">
            <div class="pat-form-section-title">Report Details</div>
            <div class="pat-fields">
                <div class="pat-field">
                    <label for="type">Type <span class="text-danger">*</span></label>
                    <select name="type" id="type" class="form-control select @error('type') is-invalid @enderror">
                        @foreach($types as $value => $label)
                            <option value="{{ $value }}" {{ old('type', 'problem') == $value ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                    @include('partials.errors.field', ['field' => 'type'])
                </div>

                <div class="pat-field">
                    <label for="category">Area <span class="text-danger">*</span></label>
                    <select name="category" id="category" class="form-control select @error('category') is-invalid @enderror">
                        @foreach($categories as $value => $label)
                            <option value="{{ $value }}" {{ old('category') == $value ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                    @include('partials.errors.field', ['field' => 'category'])
                </div>

                <div class="pat-field">
                    <label for="priority">Priority <span class="text-danger">*</span></label>
                    <select name="priority" id="priority" class="form-control select @error('priority') is-invalid @enderror">
                        @foreach($priorities as $value => $label)
                            <option value="{{ $value }}" {{ old('priority', 'medium') == $value ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                    @include('partials.errors.field', ['field' => 'priority'])
                </div>

                <div class="pat-field pat-field-wide">
                    <label for="title">Title <span class="text-danger">*</span></label>
                    <input name="title" id="title" value="{{ old('title') }}" required type="text" class="form-control @error('title') is-invalid @enderror" placeholder="Eg. Water cooler not working near Block B">
                    @include('partials.errors.field', ['field' => 'title'])
                </div>

                <div class="pat-field pat-field-wide">
                    <label for="context">Where did this happen?</label>
                    <input name="context" id="context" value="{{ old('context') }}" type="text" class="form-control @error('context') is-invalid @enderror" placeholder="Eg. Ground floor, Block B, near the library">
                    @include('partials.errors.field', ['field' => 'context'])
                </div>

                <div class="pat-field pat-field-wide">
                    <label for="description">Description <span class="text-danger">*</span></label>
                    <textarea name="description" id="description" rows="5" required class="form-control @error('description') is-invalid @enderror" placeholder="Describe the issue or feedback in detail — what happened, since when, and anything that helps the Admin act on it.">{{ old('description') }}</textarea>
                    @include('partials.errors.field', ['field' => 'description'])
                </div>

                <div class="pat-field pat-field-wide">
                    <label for="attachments"><i class="icon-camera mr-1"></i> Add screenshot / files</label>
                    <input name="attachments[]" id="attachments" type="file" multiple accept="image/*,.pdf" class="form-control @error('attachments') is-invalid @enderror @error('attachments.*') is-invalid @enderror">
                    @include('partials.errors.field', ['field' => 'attachments'])
                    <span class="form-text text-muted">Attach up to <strong>3 files</strong> — screenshots help the most. Images (jpg, png, gif, webp) or PDF, up to 2 MB each.</span>
                </div>
            </div>
        </div>

        @slot('actions')
            <a href="{{ route('feedback.mine') }}" class="btn btn-light">Cancel</a>
            <button type="submit" class="btn btn-primary">Send to Admin <i class="icon-paperplane ml-2"></i></button>
        @endslot
    @endcomponent
@endsection
