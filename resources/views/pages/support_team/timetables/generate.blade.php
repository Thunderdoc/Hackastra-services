@extends('layouts.master')
@section('page_title', 'Auto-Generate Timetables')
@section('content')

    {{-- Auto-Generate Timetables (owner's ADMIN-LOAD-ZERO law,
         2026-10-23; rebuild 2026-11-01, gen-redesign reference):
         the system asks ONLY for the school-day basics, then
         builds every class timetable itself. Two columns: the
         school day (fields shown ONCE per mode — the segmented
         Generate-for control shows the single-class fields or the
         level presets, never both), a live day timeline computed
         from the fields, the subject picker as class pills + one
         open panel (in One-class mode only that class's subjects
         show — the defect this rebuild fixes), the decision rules
         in two short columns, and a sticky action bar whose
         summary tracks the form live. Every input name, default
         and the POST contract are the pre-rebuild ones. --}}
    <div class="card gen-head">
        <div>
            <h2>Auto-Generate Timetables</h2>
            <p>Tell the system how your school day runs — it builds every timetable itself</p>
        </div>
        <div class="gen-steps">
            <span class="gen-step on"><i>1</i> School Day</span><span class="gen-sline"></span>
            <span class="gen-step on"><i>2</i> Subjects</span><span class="gen-sline"></span>
            <span class="gen-step idle"><i>3</i> Generate</span>
        </div>
    </div>

    <form method="POST" action="{{ route('tt.generate') }}" id="gen-form">
        @csrf
        <div class="gen-layout">
            <div class="gen-left">
                <div class="card gen-panel">
                    <h3>Generate for</h3>
                    <div class="gen-seg" id="gen-seg">
                        <button type="button" data-scope="all">All classes</button>
                        <button type="button" data-scope="one">One class only</button>
                    </div>
                    <input type="hidden" name="scope" id="scope" value="{{ old('scope', !empty($preset_class) ? 'one' : 'all') }}">

                    <div class="gen-one-only" id="gen-class-fields" style="display:none;">
                        <div class="gen-fgrid">
                            <div class="gen-fld">
                                <label for="class_id">CLASS <b>*</b></label>
                                <select id="class_id" name="class_id" class="form-control" onchange="getClassSections(this.value, '#section_id')">
                                    <option value="">Select Class</option>
                                    @foreach($my_classes as $mc)
                                        <option value="{{ $mc->id }}" data-level="{{ $class_levels[$mc->id] ?? 'secondary' }}" {{ old('class_id', $preset_class ?? '') == $mc->id ? 'selected' : '' }}>{{ $mc->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="gen-fld">
                                <label for="section_id">SECTION</label>
                                <select id="section_id" name="section_id" class="form-control">
                                    <option value="">Whole class (all sections)</option>
                                    @foreach($preset_sections as $sec)
                                        <option value="{{ $sec->id }}" {{ old('section_id') == $sec->id ? 'selected' : '' }}>{{ $sec->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <p class="gen-note">Leave Section blank for the whole-class grid. Fully-streamed classes (every section has a stream) have no whole-class grid — pick a section.</p>
                    </div>

                    <div class="gen-one-only" id="gen-base-fields" style="display:none;">
                        <div class="gen-fgrid">
                            <div class="gen-fld">
                                <label for="start_time">SCHOOL STARTS AT <b>*</b></label>
                                <input id="start_time" name="start_time" type="time" required class="form-control" value="{{ old('start_time', $defaults['start_time']) }}">
                            </div>
                            <div class="gen-fld">
                                <label for="period_minutes">ONE PERIOD (MINUTES) <b>*</b></label>
                                <input id="period_minutes" name="period_minutes" type="number" min="20" max="90" required class="form-control" value="{{ old('period_minutes', $defaults['period_minutes']) }}">
                            </div>
                            <div class="gen-fld">
                                <label for="periods_per_day">PERIODS PER DAY <b>*</b></label>
                                <input id="periods_per_day" name="periods_per_day" type="number" min="4" max="12" required class="form-control" value="{{ old('periods_per_day', $defaults['periods_per_day']) }}">
                            </div>
                            <div class="gen-fld">
                                <label for="days">SCHOOL DAYS PER WEEK <b>*</b></label>
                                <select id="days" name="days" class="form-control">
                                    <option value="5" {{ old('days', $defaults['days']) == 5 ? 'selected' : '' }}>5 days (Mon – Fri)</option>
                                    <option value="6" {{ old('days', $defaults['days']) == 6 ? 'selected' : '' }}>6 days (Mon – Sat)</option>
                                </select>
                            </div>
                            <div class="gen-fld">
                                <label for="lunch_after">LUNCH AFTER PERIOD <b>*</b></label>
                                <input id="lunch_after" name="lunch_after" type="number" min="1" max="12" required class="form-control" value="{{ old('lunch_after', $defaults['lunch_after']) }}">
                            </div>
                            <div class="gen-fld">
                                <label for="lunch_minutes">LUNCH BREAK (MINUTES) <b>*</b></label>
                                <input id="lunch_minutes" name="lunch_minutes" type="number" min="15" max="90" required class="form-control" value="{{ old('lunch_minutes', $defaults['lunch_minutes']) }}">
                            </div>
                            <div class="gen-fld">
                                <label for="break_after">SHORT BREAK AFTER PERIOD</label>
                                <input id="break_after" name="break_after" type="number" min="0" max="12" class="form-control" value="{{ old('break_after', $defaults['break_after']) }}">
                            </div>
                            <div class="gen-fld">
                                <label for="break_minutes">SHORT BREAK (MINUTES)</label>
                                <input id="break_minutes" name="break_minutes" type="number" min="5" max="45" class="form-control" value="{{ old('break_minutes', $defaults['break_minutes']) }}">
                            </div>
                            <div class="gen-fld">
                                <label for="max_teacher_periods_per_day">MAX PERIODS / TEACHER / DAY</label>
                                <input id="max_teacher_periods_per_day" name="max_teacher_periods_per_day" type="number" min="1" max="12" class="form-control" value="{{ old('max_teacher_periods_per_day', $cap_day_default ?? '') }}" placeholder="Empty = no cap">
                            </div>
                            <div class="gen-fld">
                                <label>WEEKLY CAP (FROM SETTINGS)</label>
                                <div class="gen-fixed">{{ $cap_week ?? 40 }} <small>· fixed</small></div>
                            </div>
                        </div>
                        <div class="gen-fld" style="margin-top:12px;">
                            <label>SCHOOL DAYS</label>
                            <div class="gen-days" id="gen-days">
                                <span>MON</span><span>TUE</span><span>WED</span><span>THU</span><span>FRI</span><span data-day="6">SAT</span>
                            </div>
                        </div>
                        <p class="gen-note">Prefilled with the school defaults from Settings ({{ $cap_day_default ?? 8 }}/day, weekly cap {{ $cap_week ?? 40 }}). Capped periods stay free for the teacher.</p>
                    </div>

                    {{-- Parity (gen-redesign sample, 2026-11-01): in
                         All-classes mode the card used to be an empty
                         shell (toggle + one note). This read-only
                         School day defaults summary fills it from the
                         SAME level configs the presets below edit —
                         one value where both levels agree, Primary /
                         Secondary where they differ. Display only:
                         no input names, the POST contract is
                         untouched; the values change via the Level
                         presets' Edit fields, never here. --}}
                    <div class="gen-all-only" id="gen-day-summary">
                        @php
                            $lvFmt = function ($key, $fmt = null) use ($level_defaults) {
                                $p = $level_defaults['primary'][$key] ?? '';
                                $s = $level_defaults['secondary'][$key] ?? '';
                                if ($fmt) { $p = $fmt($p); $s = $fmt($s); }
                                return (string) $p === (string) $s ? (string) $p : 'Primary ' . $p . ' · Secondary ' . $s;
                            };
                            $lvTime = function ($v) { return $v ? date('h:i A', strtotime($v)) : ''; };
                            $lvDays = function ($v) { return (string) $v === '6' ? 'Mon – Sat' : 'Mon – Fri'; };
                            $lvBreak = function ($v) { return (int) $v > 0 ? (string) (int) $v : 'None'; };
                            $anyBreak = (int) ($level_defaults['primary']['break_after'] ?? 0) > 0 || (int) ($level_defaults['secondary']['break_after'] ?? 0) > 0;
                        @endphp
                        <div class="gen-sumtitle">School day defaults</div>
                        <div class="gen-fgrid">
                            <div class="gen-fld">
                                <label>School starts at</label>
                                <div class="gen-fixed">{{ $lvFmt('start_time', $lvTime) }}</div>
                            </div>
                            <div class="gen-fld">
                                <label>One period (minutes)</label>
                                <div class="gen-fixed">{{ $lvFmt('period_minutes') }}</div>
                            </div>
                            <div class="gen-fld">
                                <label>Lunch after period</label>
                                <div class="gen-fixed">{{ $lvFmt('lunch_after') }}</div>
                            </div>
                            <div class="gen-fld">
                                <label>Lunch break (minutes)</label>
                                <div class="gen-fixed">{{ $lvFmt('lunch_minutes') }}</div>
                            </div>
                            <div class="gen-fld">
                                <label>Short break after period</label>
                                <div class="gen-fixed">{{ $lvFmt('break_after', $lvBreak) }}</div>
                            </div>
                            <div class="gen-fld">
                                <label>Short break (minutes)</label>
                                <div class="gen-fixed">{{ $anyBreak ? $lvFmt('break_minutes') : '—' }}</div>
                            </div>
                            <div class="gen-fld">
                                <label>Max periods / teacher / day</label>
                                <div class="gen-fixed">{{ $cap_day_default ?? 8 }}</div>
                            </div>
                            <div class="gen-fld">
                                <label>Weekly cap (from Settings)</label>
                                <div class="gen-fixed">{{ $cap_week ?? 40 }} <small>· fixed</small></div>
                            </div>
                            <div class="gen-fld" style="grid-column:1 / -1;">
                                <label>School days</label>
                                <div class="gen-fixed">{{ $lvFmt('days', $lvDays) }}</div>
                            </div>
                        </div>
                        <p class="gen-note">Sirf dekhne ke liye hai — values badalni hon to neeche Level presets mein Edit kholo.</p>
                    </div>
                    <p class="gen-note" id="gen-mode-note"></p>
                </div>

                <div class="card gen-panel gen-all-only" id="gen-levels" style="margin-top:16px;">
                    <h3>Level presets <span class="gen-tag">ALL CLASSES</span></h3>
                    @foreach(['primary' => 'Primary (Prep – Class 5)', 'secondary' => 'Secondary (Class 6 – 12)'] as $lv => $lvLabel)
                        <div class="gen-lvl" data-level="{{ $lv }}">
                            <span class="gen-lvl-ic {{ $lv === 'primary' ? 'gen-tone-amber' : 'gen-tone-blue' }}">{{ strtoupper(substr($lv, 0, 1)) }}</span>
                            <div>
                                <b>{{ $lvLabel }}</b>
                                <span class="gen-lvl-sum" id="gen-lvl-sum-{{ $lv }}"></span>
                            </div>
                            <button type="button" class="btn btn-link btn-sm gen-lvl-edit" data-level="{{ $lv }}">Edit</button>
                        </div>
                        <div class="gen-lvl-fields" id="gen-lvl-fields-{{ $lv }}" style="display:none;">
                            <div class="gen-fgrid">
                                <div class="gen-fld">
                                    <label>School starts at</label>
                                    <input name="{{ $lv }}_start_time" type="time" class="form-control gen-lv-input" value="{{ old($lv . '_start_time', $level_defaults[$lv]['start_time']) }}">
                                </div>
                                <div class="gen-fld">
                                    <label>One period is (minutes)</label>
                                    <input name="{{ $lv }}_period_minutes" type="number" min="20" max="90" class="form-control gen-lv-input" value="{{ old($lv . '_period_minutes', $level_defaults[$lv]['period_minutes']) }}">
                                </div>
                                <div class="gen-fld">
                                    <label>Periods per day</label>
                                    <input name="{{ $lv }}_periods_per_day" type="number" min="4" max="12" class="form-control gen-lv-input" value="{{ old($lv . '_periods_per_day', $level_defaults[$lv]['periods_per_day']) }}">
                                </div>
                                <div class="gen-fld">
                                    <label>School days per week</label>
                                    <select name="{{ $lv }}_days" class="form-control gen-lv-input">
                                        <option value="5" {{ old($lv . '_days', $level_defaults[$lv]['days']) == 5 ? 'selected' : '' }}>5 days (Mon – Fri)</option>
                                        <option value="6" {{ old($lv . '_days', $level_defaults[$lv]['days']) == 6 ? 'selected' : '' }}>6 days (Mon – Sat)</option>
                                    </select>
                                </div>
                                <div class="gen-fld">
                                    <label>Lunch after period</label>
                                    <input name="{{ $lv }}_lunch_after" type="number" min="1" max="12" class="form-control gen-lv-input" value="{{ old($lv . '_lunch_after', $level_defaults[$lv]['lunch_after']) }}">
                                </div>
                                <div class="gen-fld">
                                    <label>Lunch break (minutes)</label>
                                    <input name="{{ $lv }}_lunch_minutes" type="number" min="15" max="90" class="form-control gen-lv-input" value="{{ old($lv . '_lunch_minutes', $level_defaults[$lv]['lunch_minutes']) }}">
                                </div>
                                <div class="gen-fld">
                                    <label>Short break after period (0 = none)</label>
                                    <input name="{{ $lv }}_break_after" type="number" min="0" max="12" class="form-control gen-lv-input" value="{{ old($lv . '_break_after', $level_defaults[$lv]['break_after']) }}">
                                </div>
                                <div class="gen-fld">
                                    <label>Short break (minutes)</label>
                                    <input name="{{ $lv }}_break_minutes" type="number" min="5" max="45" class="form-control gen-lv-input" value="{{ old($lv . '_break_minutes', $level_defaults[$lv]['break_minutes']) }}">
                                </div>
                            </div>
                        </div>
                    @endforeach
                    <p class="gen-note">Fields dobara nahi bharne — Edit kholne par hi uske numbers badalte hain. Pichhli baar ke values yaad rehte hain (last-used values are remembered).</p>
                </div>

                <div class="card gen-panel" style="margin-top:16px;">
                    <h3>Classes that already have a timetable</h3>
                    <label class="gen-radio" data-mode-card="skip">
                        <input type="radio" name="mode" value="skip" {{ old('mode', 'skip') === 'skip' ? 'checked' : '' }}>
                        <span><b>Keep theirs</b><span>Generate only the missing ones — attendance/substitution wale kabhi replace nahi hote</span></span>
                    </label>
                    <label class="gen-radio" data-mode-card="replace">
                        <input type="radio" name="mode" value="replace" {{ old('mode') === 'replace' ? 'checked' : '' }}>
                        <span><b>Regenerate all</b><span>Sirf wahi classes jinpar attendance ya substitution nahi hai — baaki replace ho jayengi</span></span>
                    </label>
                </div>
            </div>

            <div class="gen-right">
                <div class="card gen-panel">
                    <h3>Your school day will look like this <span class="gen-tag" id="gen-tl-tag">SECONDARY</span></h3>
                    <div class="gen-tl-switch gen-all-only" id="gen-tl-switch">
                        <button type="button" data-tl="primary">Primary</button>
                        <button type="button" data-tl="secondary" class="on">Secondary</button>
                    </div>
                    <div class="gen-timeline" id="gen-timeline"></div>
                    <div class="gen-tend" id="gen-tend"></div>
                </div>

                <div class="card gen-panel" id="tt-subjects" style="margin-top:16px;">
                    <h3>Subjects to include <span class="gen-tag" id="tt-subject-count"></span></h3>
                    <p class="gen-note" id="tt-scope-note" style="margin-top:8px;"></p>
                    <input type="text" id="tt-subject-filter" class="form-control gen-search" placeholder="Filter subjects… e.g. Physics">
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
                            <div class="gen-grouplabel">{{ $stageLabel }}</div>
                            <div class="gen-classrow">
                                @foreach($stageGroups[$stageKey] as $group)
                                    @php
                                        $checkedCount = 0;
                                        foreach ($group['subjects'] as $sub) {
                                            if ($subjectsSubmitted ? in_array($sub->id, (array) old('subjects.' . $group['class']->id, [])) : true) $checkedCount++;
                                        }
                                    @endphp
                                    <button type="button" class="gen-cpill" data-class-pill="{{ $group['class']->id }}">{{ $group['class']->name }} <b class="gen-pill-count">{{ $checkedCount }}/{{ $group['subjects']->count() }}</b></button>
                                @endforeach
                            </div>
                        @endif
                    @endforeach

                    @foreach(['primary', 'secondary'] as $stageKey)
                        @foreach($stageGroups[$stageKey] ?? [] as $group)
                            @php
                                $openFirst = $firstGroup; $firstGroup = false;
                                $streamGroups = \App\Support\Streams::groupForDisplay($group['subjects']);
                            @endphp
                            <div class="gen-subjbox gen-class-panel" data-class-panel="{{ $group['class']->id }}" data-open-first="{{ $openFirst ? '1' : '0' }}" style="display:none;">
                                <div class="gen-subjhead">
                                    <span>{{ $group['class']->name }} — subjects · <span class="tt-class-count"></span></span>
                                    <span class="gen-subj-tools">
                                        <button type="button" class="btn btn-link btn-sm tt-toggle" data-scope="class" data-check="1">All</button>
                                        <button type="button" class="btn btn-link btn-sm tt-toggle" data-scope="class" data-check="0">None</button>
                                    </span>
                                </div>
                                <input type="hidden" name="subjects_present[{{ $group['class']->id }}]" value="1">
                                @foreach($streamGroups as $streamGroup)
                                    <div class="tt-stream">
                                        @if(count($streamGroups) > 1)
                                            <div class="gen-streamhead">
                                                <span class="gen-grouplabel" style="margin:0;">{{ $streamGroup['label'] }}</span>
                                                <button type="button" class="btn btn-link btn-sm tt-toggle" data-scope="stream" data-check="1">All</button>
                                                <button type="button" class="btn btn-link btn-sm tt-toggle" data-scope="stream" data-check="0">None</button>
                                            </div>
                                        @endif
                                        <div class="gen-subjgrid">
                                            @foreach($streamGroup['subjects'] as $sub)
                                                @php $checked = $subjectsSubmitted ? in_array($sub->id, (array) old('subjects.' . $group['class']->id, [])) : true; @endphp
                                                <label class="gen-subj tt-subject-label{{ $checked ? '' : ' off' }}{{ !$sub->teacher_id ? ' warn' : '' }}">
                                                    <input type="checkbox" class="tt-subject-check" name="subjects[{{ $group['class']->id }}][]" value="{{ $sub->id }}" {{ $checked ? 'checked' : '' }}>
                                                    {{ $sub->name }}@if(!$sub->teacher_id) — no teacher @endif
                                                </label>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                    @endforeach
                    <div class="gen-subjbox" id="gen-subj-hint" style="display:none;">
                        <p class="gen-note" style="margin:0;">Pick a class above — only that class's subjects are used in One-class mode.</p>
                    </div>

                    {{-- T5 constraint tiers (owner framework 2026-10-29),
                         compressed to two short columns. --}}
                    <h3 style="margin-top:18px;">How generation decides</h3>
                    <div class="gen-rules">
                        <div><b>Mandatory — never broken</b>No teacher in two places at once · a lab subject only in a lab · daily + weekly caps · one period per class per slot · attendance/substitution timetables never replaced.</div>
                        <div><b>Preferences — best effort, always reported</b>Every subject's weekly periods, spread out · double periods back-to-back, never across a break · least-loaded teacher first · smallest room that fits. Anything unmet is named in the result — nothing is silently dropped.</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="gen-sticky">
            <div class="gen-sum"><span id="gen-sum-main"></span><small id="gen-sum-sub"></small></div>
            <a href="{{ route('tt.index') }}" class="gen-cancel">Cancel</a>
            <button type="submit" class="btn gen-genbtn">Generate Timetables</button>
        </div>
    </form>
@endsection

@section('scripts')
    <script>
        (function () {
            var scopeInput = document.getElementById('scope');
            var classSel = document.getElementById('class_id');
            var root = document.getElementById('tt-subjects');
            var tlLevel = 'secondary';
            var openClassId = null;
            var filtering = false;

            function val(name) {
                var el = document.getElementsByName(name)[0];
                return el ? el.value : '';
            }
            function num(name, dflt) {
                var v = parseInt(val(name), 10);
                return isNaN(v) ? dflt : v;
            }
            function fmt(mins) {
                var h = Math.floor(mins / 60) % 24, m = mins % 60;
                var ap = h >= 12 ? 'PM' : 'AM';
                var h12 = h % 12 === 0 ? 12 : h % 12;
                return h12 + ':' + (m < 10 ? '0' : '') + m + ' ' + ap;
            }
            function dayPlan(prefix) {
                var st = val(prefix + 'start_time') || '09:00';
                var parts = st.split(':');
                var t = (parseInt(parts[0], 10) || 9) * 60 + (parseInt(parts[1], 10) || 0);
                var pm = num(prefix + 'period_minutes', 40);
                var ppd = num(prefix + 'periods_per_day', 8);
                var lunchAfter = num(prefix + 'lunch_after', 4);
                var lunchMin = num(prefix + 'lunch_minutes', 45);
                var breakAfter = num(prefix + 'break_after', 0);
                var breakMin = num(prefix + 'break_minutes', 15);
                var blocks = [];
                var start = t;
                for (var p = 1; p <= ppd; p++) {
                    blocks.push({ label: 'P' + p, time: t, kind: '' });
                    t += pm;
                    if (breakAfter > 0 && p === breakAfter) { blocks.push({ label: 'BREAK', time: t, kind: 'brk' }); t += breakMin; }
                    if (p === lunchAfter) { blocks.push({ label: 'LUNCH', time: t, kind: 'lun' }); t += lunchMin; }
                }
                return { blocks: blocks, start: start, end: t, ppd: ppd, pm: pm };
            }

            function activePrefix() {
                if (scopeInput.value === 'one') return '';
                return tlLevel + '_';
            }
            function renderTimeline() {
                var plan = dayPlan(activePrefix());
                var tl = document.getElementById('gen-timeline');
                tl.innerHTML = plan.blocks.map(function (b) {
                    return '<div class="gen-blk ' + b.kind + '"><b>' + b.label + '</b><span>' + fmt(b.time) + '</span></div>';
                }).join('');
                document.getElementById('gen-tend').innerHTML =
                    '<span>School starts <b>' + fmt(plan.start) + '</b></span>' +
                    '<span>' + plan.ppd + ' periods × ' + plan.pm + ' min</span>' +
                    '<span>Day ends <b>' + fmt(plan.end) + '</b></span>';
                var tag = document.getElementById('gen-tl-tag');
                if (scopeInput.value === 'one') {
                    var opt = classSel && classSel.selectedIndex >= 0 ? classSel.options[classSel.selectedIndex] : null;
                    tag.textContent = ((opt && opt.getAttribute('data-level')) || 'secondary').toUpperCase();
                } else {
                    tag.textContent = tlLevel.toUpperCase();
                }
            }
            function renderLevelSummaries() {
                ['primary', 'secondary'].forEach(function (lv) {
                    var plan = dayPlan(lv + '_');
                    var el = document.getElementById('gen-lvl-sum-' + lv);
                    if (el) el.textContent = plan.ppd + ' periods · day ends ' + fmt(plan.end);
                });
            }

            function panels() { return Array.prototype.slice.call(root.querySelectorAll('.gen-class-panel')); }
            function pills() { return Array.prototype.slice.call(root.querySelectorAll('.gen-cpill')); }

            function updateCounts() {
                var totalChecked = 0, total = 0;
                panels().forEach(function (g) {
                    var checks = g.querySelectorAll('.tt-subject-check');
                    var checked = g.querySelectorAll('.tt-subject-check:checked').length;
                    var span = g.querySelector('.tt-class-count');
                    if (span) span.textContent = checked + ' of ' + checks.length + ' selected';
                    var pill = root.querySelector('[data-class-pill="' + g.getAttribute('data-class-panel') + '"] .gen-pill-count');
                    if (pill) pill.textContent = checked + '/' + checks.length;
                    totalChecked += checked; total += checks.length;
                });
                var tag = document.getElementById('tt-subject-count');
                if (tag) tag.textContent = totalChecked + ' OF ' + total + ' SELECTED';
                renderSummary(totalChecked, total);
            }

            function scopeCounts() {
                if (scopeInput.value !== 'one' || !classSel || !classSel.value) return null;
                var g = root.querySelector('[data-class-panel="' + classSel.value + '"]');
                if (!g) return null;
                return {
                    checked: g.querySelectorAll('.tt-subject-check:checked').length,
                    total: g.querySelectorAll('.tt-subject-check').length,
                    name: classSel.options[classSel.selectedIndex].text
                };
            }
            function renderSummary(totalChecked, total) {
                var main = document.getElementById('gen-sum-main');
                var sub = document.getElementById('gen-sum-sub');
                var sc = scopeCounts();
                if (scopeInput.value === 'one') {
                    main.textContent = sc ? sc.name + ' · ' + sc.checked + ' of ' + sc.total + ' subjects' : 'One class only · pick a class';
                    sub.textContent = num('periods_per_day', 8) + ' periods per day · ' + (val('days') || '5') + ' days a week';
                } else {
                    main.textContent = 'All classes · ' + totalChecked + ' of ' + total + ' subjects';
                    sub.textContent = 'Primary ' + num('primary_periods_per_day', 6) + ' periods · Secondary ' + num('secondary_periods_per_day', 8) + ' periods';
                }
            }

            function renderPanels() {
                var hint = document.getElementById('gen-subj-hint');
                var oneMode = scopeInput.value === 'one';
                var chosen = oneMode && classSel ? classSel.value : '';
                hint.style.display = (oneMode && !chosen && !filtering) ? '' : 'none';
                panels().forEach(function (g) {
                    var id = g.getAttribute('data-class-panel');
                    var show;
                    if (filtering) {
                        show = g.querySelectorAll('.tt-subject-label:not([style*="none"])').length > 0 &&
                               Array.prototype.some.call(g.querySelectorAll('.tt-subject-label'), function (l) { return l.style.display !== 'none'; });
                    } else if (oneMode) {
                        show = chosen !== '' && id === chosen;
                    } else {
                        show = id === String(openClassId);
                    }
                    g.style.display = show ? '' : 'none';
                });
                pills().forEach(function (pill) {
                    var id = pill.getAttribute('data-class-pill');
                    pill.style.display = (oneMode && chosen !== '' && id !== chosen && !filtering) ? 'none' : '';
                    pill.classList.toggle('open', String(openClassId) === id || (oneMode && id === chosen));
                });
                // Stage pill groups hide entirely when every pill is hidden.
                root.querySelectorAll('.gen-classrow').forEach(function (row) {
                    var any = Array.prototype.some.call(row.querySelectorAll('.gen-cpill'), function (p) { return p.style.display !== 'none'; });
                    row.style.display = any ? '' : 'none';
                    var label = row.previousElementSibling;
                    if (label && label.classList.contains('gen-grouplabel')) label.style.display = any ? '' : 'none';
                });
                updateScopeNote();
            }

            function updateScopeNote() {
                var note = document.getElementById('tt-scope-note');
                if (!note) return;
                if (scopeInput.value === 'one') {
                    var sc = scopeCounts();
                    note.textContent = sc
                        ? 'One class only: only ' + sc.name + ' subjects below will be used.'
                        : 'One class only: pick a class above — only that class\u2019s subjects will be used.';
                } else {
                    note.textContent = 'All classes: every class\u2019s subjects below are used.';
                }
            }

            function applyMode() {
                var one = scopeInput.value === 'one';
                document.querySelectorAll('#gen-seg button').forEach(function (b) {
                    b.classList.toggle('on', b.getAttribute('data-scope') === scopeInput.value);
                });
                document.querySelectorAll('.gen-one-only').forEach(function (el) { el.style.display = one ? '' : 'none'; });
                document.querySelectorAll('.gen-all-only').forEach(function (el) { el.style.display = one ? 'none' : ''; });
                document.getElementById('gen-mode-note').textContent = one
                    ? 'One class only: the fields above are this class\u2019s school day — the level presets are not used.'
                    : 'All classes: each level uses its own preset below — the single-class fields are not used.';
                renderDays();
                renderPanels();
                renderTimeline();
                updateCounts();
            }
            function renderDays() {
                var sat = document.querySelector('#gen-days [data-day="6"]');
                if (sat) sat.classList.toggle('off', (val('days') || '5') !== '6');
            }

            document.querySelectorAll('#gen-seg button').forEach(function (b) {
                b.addEventListener('click', function () { scopeInput.value = b.getAttribute('data-scope'); applyMode(); });
            });
            document.querySelectorAll('.gen-lvl-edit').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var box = document.getElementById('gen-lvl-fields-' + btn.getAttribute('data-level'));
                    var open = box.style.display !== 'none';
                    box.style.display = open ? 'none' : '';
                    btn.textContent = open ? 'Edit' : 'Close';
                });
            });
            document.querySelectorAll('#gen-tl-switch button').forEach(function (b) {
                b.addEventListener('click', function () {
                    tlLevel = b.getAttribute('data-tl');
                    document.querySelectorAll('#gen-tl-switch button').forEach(function (x) { x.classList.toggle('on', x === b); });
                    renderTimeline();
                });
            });
            document.querySelectorAll('.gen-radio input[name="mode"]').forEach(function (r) {
                r.addEventListener('change', function () {
                    document.querySelectorAll('.gen-radio').forEach(function (card) {
                        card.classList.toggle('on', card.querySelector('input').checked);
                    });
                });
            });
            document.querySelectorAll('.gen-radio input[name="mode"]:checked').forEach(function (r) {
                r.closest('.gen-radio').classList.add('on');
            });

            pills().forEach(function (pill) {
                pill.addEventListener('click', function () {
                    openClassId = pill.getAttribute('data-class-pill');
                    if (scopeInput.value === 'one' && classSel) {
                        classSel.value = openClassId;
                        if (typeof getClassSections === 'function') getClassSections(openClassId, '#section_id');
                    }
                    renderPanels(); renderTimeline(); updateCounts();
                });
            });
            if (classSel) classSel.addEventListener('change', function () {
                openClassId = classSel.value || openClassId;
                renderPanels(); renderTimeline(); updateCounts();
            });

            root.addEventListener('change', function (e) {
                if (e.target.classList && e.target.classList.contains('tt-subject-check')) {
                    var label = e.target.closest('.tt-subject-label');
                    if (label) label.classList.toggle('off', !e.target.checked);
                    updateCounts();
                }
            });
            root.addEventListener('click', function (e) {
                var btn = e.target.closest ? e.target.closest('.tt-toggle') : null;
                if (!btn) return;
                var container = btn.dataset.scope === 'stream' ? btn.closest('.tt-stream') : btn.closest('.gen-class-panel');
                if (!container) return;
                var check = btn.dataset.check === '1';
                container.querySelectorAll('.tt-subject-check').forEach(function (c) {
                    c.checked = check;
                    var label = c.closest('.tt-subject-label');
                    if (label) label.classList.toggle('off', !check);
                });
                updateCounts();
            });

            var filter = document.getElementById('tt-subject-filter');
            if (filter) {
                filter.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });
                filter.addEventListener('input', function () {
                    var q = filter.value.trim().toLowerCase();
                    filtering = q !== '';
                    root.querySelectorAll('.tt-subject-label').forEach(function (label) {
                        label.style.display = (!q || label.textContent.toLowerCase().indexOf(q) !== -1) ? '' : 'none';
                    });
                    root.querySelectorAll('.tt-stream').forEach(function (stream) {
                        var visible = Array.prototype.some.call(stream.querySelectorAll('.tt-subject-label'), function (l) { return l.style.display !== 'none'; });
                        stream.style.display = visible ? '' : 'none';
                    });
                    renderPanels();
                });
            }

            // Any field change refreshes the live pieces.
            document.getElementById('gen-form').addEventListener('input', function () {
                renderLevelSummaries(); renderTimeline(); renderDays(); updateCounts();
            });
            var daysSel = document.getElementById('days');
            if (daysSel) daysSel.addEventListener('change', renderDays);

            // Init: open the first class (or the preset class in one-mode).
            var firstPanel = root.querySelector('[data-open-first="1"]') || root.querySelector('.gen-class-panel');
            openClassId = firstPanel ? firstPanel.getAttribute('data-class-panel') : null;
            if (scopeInput.value === 'one' && classSel && classSel.value) openClassId = classSel.value;
            renderLevelSummaries();
            applyMode();
        })();
    </script>
@endsection
