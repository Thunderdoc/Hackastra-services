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
        'back' => route('tt.index'),
        'submit_label' => 'Generate Timetables',
    ])
        @slot('title')
            <i class="icon-magic-wand mr-2"></i> Auto-Generate Timetables
        @endslot

        {{-- T5 constraint tiers (owner framework 2026-10-29): the
             Admin is told, before generating, which rules the
             generator will NEVER break and which it treats as
             preferences — and the result message reports every
             preference it could not meet instead of hiding it. --}}
        <div class="pat-form-section">
            <h6 class="mb-2">How generation decides</h6>
            <div class="row">
                <div class="col-md-6">
                    <p class="mb-1"><strong>Mandatory — never broken</strong></p>
                    <ul class="text-muted pl-3 mb-2">
                        <li>No teacher in two places at the same time</li>
                        <li>No room or lab double-booked (a lab subject only ever sits in a lab)</li>
                        <li>Teacher daily cap (below) and the weekly cap from Settings</li>
                        <li>One period per class per slot</li>
                        <li>Timetables with submitted attendance or arranged substitutions are kept, never replaced</li>
                    </ul>
                </div>
                <div class="col-md-6">
                    <p class="mb-1"><strong>Preferences — best effort, always reported</strong></p>
                    <ul class="text-muted pl-3 mb-2">
                        <li>Every subject's weekly periods, spread across the week</li>
                        <li>Double periods formed back-to-back (never across a break)</li>
                        <li>The least-loaded free teacher picked first</li>
                        <li>The smallest room that seats the class</li>
                    </ul>
                </div>
            </div>
            <p class="text-muted mb-0">If a preference cannot be met, the result message names it — nothing is silently dropped.</p>
        </div>

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
                    <label for="max_teacher_periods_per_day">Max periods per teacher per day (empty = no cap)</label>
                    <input id="max_teacher_periods_per_day" name="max_teacher_periods_per_day" type="number" min="1" max="12" class="form-control" value="{{ old('max_teacher_periods_per_day', $cap_day_default ?? '') }}" placeholder="e.g. 6 — capped periods stay free for the teacher">
                    <small class="form-text text-muted">Prefilled with the school default from Settings ({{ $cap_day_default ?? 8 }}/day). Generation also always respects the weekly cap of {{ $cap_week ?? 40 }} periods per teacher from Settings.</small>
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
                    <small class="text-muted">Leave blank for the whole-class grid. Pick a section to generate that section's own grid, with its own teachers and timings. Fully-streamed classes (every section has a stream) have no whole-class grid — pick a section.</small>
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

        <div class="pat-form-section" id="tt-subjects">
            <div class="pat-form-section-title">Subjects to include</div>
            <p class="text-muted">Classes run in order (Prep, Class 1 … Class 12), grouped by school stage — Primary (Prep – Class 5) and Secondary (Class 6 – 12). For Classes 11–12 the subjects are grouped by CBSE stream: Common, PCM, PCB, shared PCM &amp; PCB, Commerce and Arts. Uncheck subjects to leave them out of the generated grids. All subjects are included by default. Subjects with no teacher are marked — their periods may stay empty until a teacher is assigned. <span id="tt-subject-count"></span></p>
            <p class="text-muted" id="tt-scope-note"></p>
            <div style="display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px;">
                <input type="text" id="tt-subject-filter" class="form-control" style="max-width: 320px;" placeholder="Filter subjects… e.g. Physics">
                <button type="button" class="btn btn-light btn-sm" id="tt-expand-all">Expand all classes</button>
                <button type="button" class="btn btn-light btn-sm" id="tt-collapse-all">Collapse all classes</button>
                <button type="button" class="btn btn-light btn-sm" id="tt-select-all-subjects">Select all subjects</button>
                <button type="button" class="btn btn-light btn-sm" id="tt-clear-all-subjects">Clear all subjects</button>
            </div>
            @php
                $subjectsSubmitted = old('subjects_present') !== null;
                $stageGroups = ['primary' => [], 'secondary' => []];
                foreach ($subject_groups as $group) {
                    if ($group['subjects']->isEmpty()) continue;
                    $stage = ($class_levels[$group['class']->id] ?? 'secondary') === 'primary' ? 'primary' : 'secondary';
                    $stageGroups[$stage][] = $group;
                }
                $firstGroup = true;
            @endphp
            @foreach(['primary' => 'Primary — Prep to Class 5', 'secondary' => 'Secondary — Class 6 to Class 12'] as $stageKey => $stageLabel)
                @if(!empty($stageGroups[$stageKey]))
                <div style="margin: 16px 0 4px; font-weight: 700; font-size: 15px;">{{ $stageLabel }}</div>
                @foreach($stageGroups[$stageKey] as $group)
                    @php
                        $openFirst = $firstGroup; $firstGroup = false;
                        $checkedCount = 0;
                        foreach ($group['subjects'] as $sub) {
                            if ($subjectsSubmitted ? in_array($sub->id, (array) old('subjects.' . $group['class']->id, [])) : true) $checkedCount++;
                        }
                        $streamGroups = \App\Support\Streams::groupForDisplay($group['subjects']);
                    @endphp
                    <details class="tt-class-group" data-class-id="{{ $group['class']->id }}" @if($openFirst) open @endif style="margin-bottom: 10px; border: 1px solid #e5e7eb; border-radius: 8px; padding: 8px 12px;">
                        <summary style="cursor: pointer; font-weight: 600;">{{ $group['class']->name }} — {{ $stageKey === 'primary' ? 'Primary' : 'Secondary' }} ({{ $group['subjects']->count() }} subjects) · <span class="tt-class-count">{{ $checkedCount }} of {{ $group['subjects']->count() }} selected</span></summary>
                        <div style="margin: 8px 0;">
                            <button type="button" class="btn btn-light btn-sm tt-toggle" data-scope="class" data-check="1">All</button>
                            <button type="button" class="btn btn-light btn-sm tt-toggle" data-scope="class" data-check="0">None</button>
                        </div>
                        <input type="hidden" name="subjects_present[{{ $group['class']->id }}]" value="1">
                        @foreach($streamGroups as $streamGroup)
                            @if(count($streamGroups) > 1)
                            <div class="tt-stream" style="margin-top: 10px;">
                                <div style="display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 4px;">
                                    <strong>{{ $streamGroup['label'] }}</strong>
                                    <button type="button" class="btn btn-light btn-sm tt-toggle" data-scope="stream" data-check="1">All</button>
                                    <button type="button" class="btn btn-light btn-sm tt-toggle" data-scope="stream" data-check="0">None</button>
                                </div>
                            @else
                            <div class="tt-stream" style="margin-top: 6px;">
                            @endif
                                @foreach($streamGroup['subjects'] as $sub)
                                    @php $checked = $subjectsSubmitted ? in_array($sub->id, (array) old('subjects.' . $group['class']->id, [])) : true; @endphp
                                    <label class="tt-subject-label" style="display: inline-block; margin: 2px 12px 2px 0;">
                                        <input type="checkbox" class="tt-subject-check" name="subjects[{{ $group['class']->id }}][]" value="{{ $sub->id }}" {{ $checked ? 'checked' : '' }}>
                                        {{ $sub->name }}@if(!$sub->teacher_id) <span class="text-danger">(no teacher)</span>@endif
                                    </label>
                                @endforeach
                            </div>
                        @endforeach
                    </details>
                @endforeach
                @endif
            @endforeach
        </div>
    @endcomponent

@endsection

@section('scripts')
    <script>
        (function () {
            var root = document.getElementById('tt-subjects');
            if (!root) return;

            function updateCounts() {
                var totalChecked = 0, total = 0;
                root.querySelectorAll('.tt-class-group').forEach(function (g) {
                    var checks = g.querySelectorAll('.tt-subject-check');
                    var checked = g.querySelectorAll('.tt-subject-check:checked').length;
                    var span = g.querySelector('.tt-class-count');
                    if (span) span.textContent = checked + ' of ' + checks.length + ' selected';
                    totalChecked += checked; total += checks.length;
                });
                var totalSpan = document.getElementById('tt-subject-count');
                if (totalSpan) totalSpan.textContent = totalChecked + ' of ' + total + ' subjects selected.';
            }

            function updateScopeNote() {
                var scope = document.getElementById('scope');
                var classSel = document.getElementById('class_id');
                var note = document.getElementById('tt-scope-note');
                if (!scope || !note) return;
                if (scope.value === 'one') {
                    var name = (classSel && classSel.value) ? classSel.options[classSel.selectedIndex].text : '';
                    note.textContent = name
                        ? 'One class only: only ' + name + ' subjects below will be used.'
                        : 'One class only: pick a class above — only that class’s subjects will be used.';
                } else {
                    note.textContent = 'All classes: every class’s subjects below are used.';
                }
            }

            root.addEventListener('change', function (e) {
                if (e.target.classList && e.target.classList.contains('tt-subject-check')) updateCounts();
            });

            root.addEventListener('click', function (e) {
                var btn = e.target.closest ? e.target.closest('.tt-toggle') : null;
                if (!btn) return;
                var container = btn.dataset.scope === 'stream' ? btn.closest('.tt-stream') : btn.closest('.tt-class-group');
                if (!container) return;
                var check = btn.dataset.check === '1';
                container.querySelectorAll('.tt-subject-check').forEach(function (c) { c.checked = check; });
                updateCounts();
            });

            function setAll(check) {
                root.querySelectorAll('.tt-subject-check').forEach(function (c) { c.checked = check; });
                updateCounts();
            }
            document.getElementById('tt-select-all-subjects').addEventListener('click', function () { setAll(true); });
            document.getElementById('tt-clear-all-subjects').addEventListener('click', function () { setAll(false); });
            document.getElementById('tt-expand-all').addEventListener('click', function () {
                root.querySelectorAll('.tt-class-group').forEach(function (g) { g.open = true; });
            });
            document.getElementById('tt-collapse-all').addEventListener('click', function () {
                root.querySelectorAll('.tt-class-group').forEach(function (g) { g.open = false; });
            });

            var filter = document.getElementById('tt-subject-filter');
            if (filter) {
                filter.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });
                filter.addEventListener('input', function () {
                    var q = filter.value.trim().toLowerCase();
                    root.querySelectorAll('.tt-subject-label').forEach(function (label) {
                        label.style.display = (!q || label.textContent.toLowerCase().indexOf(q) !== -1) ? 'inline-block' : 'none';
                    });
                    root.querySelectorAll('.tt-stream').forEach(function (stream) {
                        var visible = Array.prototype.filter.call(stream.querySelectorAll('.tt-subject-label'), function (l) { return l.style.display !== 'none'; }).length;
                        stream.style.display = visible ? '' : 'none';
                    });
                    root.querySelectorAll('.tt-class-group').forEach(function (g) {
                        var visible = Array.prototype.filter.call(g.querySelectorAll('.tt-subject-label'), function (l) { return l.style.display !== 'none'; }).length;
                        g.style.display = visible ? '' : 'none';
                    });
                });
            }

            var scope = document.getElementById('scope');
            var classSel = document.getElementById('class_id');
            if (scope) scope.addEventListener('change', updateScopeNote);
            if (classSel) classSel.addEventListener('change', updateScopeNote);
            updateCounts();
            updateScopeNote();
        })();
    </script>
@endsection
