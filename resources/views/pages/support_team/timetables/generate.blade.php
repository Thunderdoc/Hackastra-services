@extends('layouts.master')
@section('page_title', 'Auto-Generate Timetables')
@section('content')

    {{-- Auto-Generate Timetables (owner's ADMIN-LOAD-ZERO law,
         2026-10-23): the system asks ONLY for the school-day
         basics below, then builds every class timetable itself —
         subjects placed from each class's own list, teachers never
         double-booked in the same period. The Admin then reviews
         and edits any period on each timetable's Manage page. --}}
    @component('partials.patterns.form_page', [
        'form_action' => route('tt.generate'),
        'submit_label' => 'Generate Timetables',
    ])
        @slot('title')
            <i class="icon-magic-wand mr-2"></i> Auto-Generate Timetables
        @endslot

        <div class="pat-form-section">
            <p class="text-muted">Tell the system how your school day runs — it will build the timetable for every class itself. No teacher is placed in two classes in the same period. Afterwards you can edit any period from a timetable's <strong>Manage</strong> page. The fields below apply to "One class only". For "All classes", each level uses its own block further down.</p>
            <div class="pat-fields">
                <div class="pat-field">
                    <label for="start_time">School starts at <span class="text-danger">*</span></label>
                    <input id="start_time" name="start_time" type="time" required class="form-control" value="{{ old('start_time', $defaults['start_time']) }}">
                </div>
                <div class="pat-field">
                    <label for="period_minutes">One period is (minutes) <span class="text-danger">*</span></label>
                    <input id="period_minutes" name="period_minutes" type="number" min="20" max="90" required class="form-control" value="{{ old('period_minutes', $defaults['period_minutes']) }}">
                </div>
                <div class="pat-field">
                    <label for="periods_per_day">Periods per day <span class="text-danger">*</span></label>
                    <input id="periods_per_day" name="periods_per_day" type="number" min="4" max="12" required class="form-control" value="{{ old('periods_per_day', $defaults['periods_per_day']) }}">
                </div>
                <div class="pat-field">
                    <label for="days">School days per week <span class="text-danger">*</span></label>
                    <select id="days" name="days" class="form-control">
                        <option value="5" {{ old('days', $defaults['days']) == 5 ? 'selected' : '' }}>5 days (Mon – Fri)</option>
                        <option value="6" {{ old('days', $defaults['days']) == 6 ? 'selected' : '' }}>6 days (Mon – Sat)</option>
                    </select>
                </div>
                <div class="pat-field">
                    <label for="lunch_after">Lunch after period <span class="text-danger">*</span></label>
                    <input id="lunch_after" name="lunch_after" type="number" min="1" max="12" required class="form-control" value="{{ old('lunch_after', $defaults['lunch_after']) }}">
                </div>
                <div class="pat-field">
                    <label for="lunch_minutes">Lunch break (minutes) <span class="text-danger">*</span></label>
                    <input id="lunch_minutes" name="lunch_minutes" type="number" min="15" max="90" required class="form-control" value="{{ old('lunch_minutes', $defaults['lunch_minutes']) }}">
                </div>
                <div class="pat-field">
                    <label for="break_after">Short break after period (0 = none)</label>
                    <input id="break_after" name="break_after" type="number" min="0" max="12" class="form-control" value="{{ old('break_after', $defaults['break_after']) }}">
                </div>
                <div class="pat-field">
                    <label for="break_minutes">Short break (minutes)</label>
                    <input id="break_minutes" name="break_minutes" type="number" min="5" max="45" class="form-control" value="{{ old('break_minutes', $defaults['break_minutes']) }}">
                </div>
                <div class="pat-field">
                    <label for="scope">Generate for <span class="text-danger">*</span></label>
                    <select id="scope" name="scope" class="form-control">
                        <option value="all" {{ old('scope', !empty($preset_class) ? 'one' : 'all') === 'all' ? 'selected' : '' }}>All classes</option>
                        <option value="one" {{ old('scope', !empty($preset_class) ? 'one' : 'all') === 'one' ? 'selected' : '' }}>One class only</option>
                    </select>
                </div>
                <div class="pat-field">
                    <label for="class_id">Class (when "One class only")</label>
                    <select id="class_id" name="class_id" class="form-control" onchange="getClassSections(this.value, '#section_id')">
                        <option value="">Select Class</option>
                        @foreach($my_classes as $mc)
                            <option value="{{ $mc->id }}" {{ old('class_id', $preset_class ?? '') == $mc->id ? 'selected' : '' }}>{{ $mc->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-field">
                    <label for="section_id">Section (when "One class only")</label>
                    <select id="section_id" name="section_id" class="form-control">
                        <option value="">Whole class (all sections)</option>
                        @foreach($preset_sections as $sec)
                            <option value="{{ $sec->id }}" {{ old('section_id') == $sec->id ? 'selected' : '' }}>{{ $sec->name }}</option>
                        @endforeach
                    </select>
                    <small class="text-muted">Leave blank for the whole-class grid. Pick a section to generate that section's own grid, with its own teachers and timings.</small>
                </div>
                <div class="pat-field">
                    <label for="mode">Classes that already have a timetable <span class="text-danger">*</span></label>
                    <select id="mode" name="mode" class="form-control">
                        <option value="skip" {{ old('mode') === 'skip' ? 'selected' : '' }}>Keep theirs — generate only the missing ones</option>
                        <option value="replace" {{ old('mode') === 'replace' ? 'selected' : '' }}>Replace theirs with a fresh generated one</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="pat-form-section">
            <div class="pat-form-section-title">Level presets (when "All classes")</div>
            <p class="text-muted">Primary (Prep – Class 5) ends around 2:00 PM with 6 periods; Secondary (Class 6 – 12) ends at 3:20 PM with 8 periods. Both start at 9:00. Change any number — the last-used values are remembered for next time.</p>
            @foreach(['primary' => 'Primary (Prep – Class 5)', 'secondary' => 'Secondary (Class 6 – 12)'] as $lv => $lvLabel)
            <div class="pat-fields">
                <div class="pat-field pat-field-wide"><strong>{{ $lvLabel }}</strong></div>
                <div class="pat-field">
                    <label>School starts at</label>
                    <input name="{{ $lv }}_start_time" type="time" class="form-control" value="{{ old($lv . '_start_time', $level_defaults[$lv]['start_time']) }}">
                </div>
                <div class="pat-field">
                    <label>One period is (minutes)</label>
                    <input name="{{ $lv }}_period_minutes" type="number" min="20" max="90" class="form-control" value="{{ old($lv . '_period_minutes', $level_defaults[$lv]['period_minutes']) }}">
                </div>
                <div class="pat-field">
                    <label>Periods per day</label>
                    <input name="{{ $lv }}_periods_per_day" type="number" min="4" max="12" class="form-control" value="{{ old($lv . '_periods_per_day', $level_defaults[$lv]['periods_per_day']) }}">
                </div>
                <div class="pat-field">
                    <label>School days per week</label>
                    <select name="{{ $lv }}_days" class="form-control">
                        <option value="5" {{ old($lv . '_days', $level_defaults[$lv]['days']) == 5 ? 'selected' : '' }}>5 days (Mon – Fri)</option>
                        <option value="6" {{ old($lv . '_days', $level_defaults[$lv]['days']) == 6 ? 'selected' : '' }}>6 days (Mon – Sat)</option>
                    </select>
                </div>
                <div class="pat-field">
                    <label>Lunch after period</label>
                    <input name="{{ $lv }}_lunch_after" type="number" min="1" max="12" class="form-control" value="{{ old($lv . '_lunch_after', $level_defaults[$lv]['lunch_after']) }}">
                </div>
                <div class="pat-field">
                    <label>Lunch break (minutes)</label>
                    <input name="{{ $lv }}_lunch_minutes" type="number" min="15" max="90" class="form-control" value="{{ old($lv . '_lunch_minutes', $level_defaults[$lv]['lunch_minutes']) }}">
                </div>
                <div class="pat-field">
                    <label>Short break after period (0 = none)</label>
                    <input name="{{ $lv }}_break_after" type="number" min="0" max="12" class="form-control" value="{{ old($lv . '_break_after', $level_defaults[$lv]['break_after']) }}">
                </div>
                <div class="pat-field">
                    <label>Short break (minutes)</label>
                    <input name="{{ $lv }}_break_minutes" type="number" min="5" max="45" class="form-control" value="{{ old($lv . '_break_minutes', $level_defaults[$lv]['break_minutes']) }}">
                </div>
            </div>
            @endforeach
        </div>

        <div class="pat-form-section">
            <div class="pat-form-section-title">Subjects to include</div>
            <p class="text-muted">Uncheck subjects to leave them out of the generated grids. All subjects are included by default. Subjects with no teacher are marked — their periods may stay empty until a teacher is assigned.</p>
            @php $subjectsSubmitted = old('subjects_present') !== null; @endphp
            @foreach($subject_groups as $group)
                @if($group['subjects']->isNotEmpty())
                <details @if($loop->first) open @endif style="margin-bottom: 8px;">
                    <summary>{{ $group['class']->name }} — {{ ($class_levels[$group['class']->id] ?? 'secondary') === 'primary' ? 'Primary' : 'Secondary' }} ({{ $group['subjects']->count() }} subjects)</summary>
                    <input type="hidden" name="subjects_present[{{ $group['class']->id }}]" value="1">
                    @foreach($group['subjects'] as $sub)
                        @php $checked = $subjectsSubmitted ? in_array($sub->id, (array) old('subjects.' . $group['class']->id, [])) : true; @endphp
                        <label style="display: inline-block; margin: 2px 12px 2px 0;">
                            <input type="checkbox" name="subjects[{{ $group['class']->id }}][]" value="{{ $sub->id }}" {{ $checked ? 'checked' : '' }}>
                            {{ $sub->name }}@if(!$sub->teacher_id) <span class="text-danger">(no teacher)</span>@endif
                        </label>
                    @endforeach
                </details>
                @endif
            @endforeach
        </div>
    @endcomponent

@endsection
