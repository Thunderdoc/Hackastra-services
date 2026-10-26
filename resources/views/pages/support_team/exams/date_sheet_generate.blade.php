@extends('layouts.master')
@section('page_title', 'Auto-Generate Date Sheet')
@section('content')

    {{-- Auto-Generate Date Sheet (owner's ADMIN-LOAD-ZERO law,
         2026-10-23): the system asks only when the exam starts,
         in how many days it must finish, how many papers run per
         day, the session times and the venue — then it allocates
         every subject of every class itself. The Admin reviews
         and re-times any paper on the Manage page. --}}
    @component('partials.patterns.form_page', [
        'form_action' => route('exam_dates.generate'),
        'submit_label' => 'Generate Date Sheet',
    ])
        @slot('title')
            <i class="icon-magic-wand mr-2"></i> Auto-Generate Date Sheet
        @endslot

        <div class="pat-form-section">
            <p class="text-muted">Tell the system the basics — it will allocate every subject's paper across the exam days for every class. No class gets two papers in the same session. Afterwards you can change any date or time on the <strong>Manage Date Sheet</strong> page.</p>
            <div class="pat-fields">
                <div class="pat-field">
                    <label for="exam_id">Exam <span class="text-danger">*</span></label>
                    <select id="exam_id" name="exam_id" required class="form-control">
                        <option value="">Select Exam</option>
                        @foreach($exams as $ex)
                            <option value="{{ $ex->id }}" {{ old('exam_id') == $ex->id ? 'selected' : '' }}>{{ $ex->name }} — Term {{ $ex->term }} ({{ $ex->year }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-field">
                    <label for="start_date">Exams start on <span class="text-danger">*</span></label>
                    <input id="start_date" name="start_date" type="date" required class="form-control" value="{{ old('start_date') }}">
                </div>
                <div class="pat-field">
                    <label for="exam_days">Finish within (days) <span class="text-danger">*</span></label>
                    <input id="exam_days" name="exam_days" type="number" min="1" max="30" required class="form-control" value="{{ old('exam_days', 6) }}">
                </div>
                <div class="pat-field">
                    <label for="papers_per_day">Papers per day <span class="text-danger">*</span></label>
                    <select id="papers_per_day" name="papers_per_day" class="form-control">
                        <option value="1" {{ old('papers_per_day') == 1 ? 'selected' : '' }}>1 paper a day</option>
                        <option value="2" {{ old('papers_per_day', 2) == 2 ? 'selected' : '' }}>2 papers a day (morning + afternoon)</option>
                    </select>
                </div>
                <div class="pat-field">
                    <label for="session1_time">First paper starts at <span class="text-danger">*</span></label>
                    <input id="session1_time" name="session1_time" type="time" required class="form-control" value="{{ old('session1_time', '09:00') }}">
                </div>
                <div class="pat-field">
                    <label for="session2_time">Second paper starts at</label>
                    <input id="session2_time" name="session2_time" type="time" class="form-control" value="{{ old('session2_time', '13:00') }}">
                    <small class="form-text text-muted">Primary (Prep–Class 5) and Secondary (Class 6–12) papers are automatically kept in separate sessions — they never share a day and session.</small>
                </div>
                <div class="pat-field">
                    <label for="venue">Venue (written on every paper)</label>
                    <input id="venue" name="venue" type="text" maxlength="100" class="form-control" placeholder="e.g. Main Hall" value="{{ old('venue') }}">
                </div>
                <div class="pat-field">
                    <label for="scope">Generate for <span class="text-danger">*</span></label>
                    <select id="scope" name="scope" class="form-control">
                        <option value="all" {{ old('scope') === 'all' ? 'selected' : '' }}>All classes</option>
                        <option value="one" {{ old('scope') === 'one' ? 'selected' : '' }}>One class only</option>
                    </select>
                </div>
                <div class="pat-field">
                    <label for="class_id">Class (when "One class only")</label>
                    <select id="class_id" name="class_id" class="form-control">
                        <option value="">Select Class</option>
                        @foreach($my_classes as $mc)
                            <option value="{{ $mc->id }}" {{ old('class_id') == $mc->id ? 'selected' : '' }}>{{ $mc->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-field">
                    <label for="mode">Classes that already have a sheet <span class="text-danger">*</span></label>
                    <select id="mode" name="mode" class="form-control">
                        <option value="skip" {{ old('mode') === 'skip' ? 'selected' : '' }}>Keep theirs — generate only the missing ones</option>
                        <option value="replace" {{ old('mode') === 'replace' ? 'selected' : '' }}>Replace theirs with a fresh generated one</option>
                    </select>
                </div>
                <div class="pat-field">
                    <label for="gap_every">Break after every (exam days, 0 = no breaks)</label>
                    <input id="gap_every" name="gap_every" type="number" min="0" max="15" class="form-control" value="{{ old('gap_every', 2) }}">
                </div>
                <div class="pat-field">
                    <label for="gap_days">Break length (days)</label>
                    <input id="gap_days" name="gap_days" type="number" min="0" max="10" class="form-control" value="{{ old('gap_days', 1) }}">
                </div>
                <div class="pat-field">
                    <label for="skip_dates">Holidays / no-exam dates (comma separated)</label>
                    <input id="skip_dates" name="skip_dates" type="text" class="form-control" placeholder="e.g. 2026-11-14, 2026-11-26" value="{{ old('skip_dates') }}">
                </div>
                <div class="pat-field">
                    <label for="skip_sunday">Sundays</label>
                    <select id="skip_sunday" name="skip_sunday" class="form-control">
                        <option value="1" {{ old('skip_sunday', '1') === '1' ? 'selected' : '' }}>No exams on Sundays</option>
                        <option value="" {{ old('skip_sunday') === '' ? 'selected' : '' }}>Sundays count as exam days</option>
                    </select>
                </div>
            </div>
        </div>
    @endcomponent

@endsection
