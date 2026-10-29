{{-- Batch 114 (Loop 86): live totals / percentage / grade mirror the
     server's own rules exactly — total = MarkScheme::subjectTotal
     (NULL while nothing is entered; blanks count 0 once anything is),
     grade = MarkRepo::getGrade's band lookup (the class type's scale
     first, the global scale as fallback, no grade under a total of
     1). The bands are passed to the script below as JSON so the grid
     can never drift from the configured scale. Loop 76's input chrome
     (form-control + .l76-mark-input, .l76-marks-table, .l76-actionbar)
     is carried over unchanged.
     Loop 115 (reference replica, presentation only): inputs gain a
     visual "/ max" suffix (a sibling span, never part of the value),
     TOTAL / PERCENTAGE values move into pill spans — the JS hooks
     stay the same single .l86-total / .l86-pct element per row — and
     the summary + actions form one footer strip. The script below
     is byte-identical to Loop 86. --}}
@php
    /* Marks lifecycle (owner order 2026-10-29): a locked unit
       (before its exam date, or after submission) renders its
       inputs and save controls disabled — the server refuses the
       save regardless; this is the honest visual state. */
    $l86Locked = !empty($lifecycle['locked']);
    $l86BandsPrimary = \App\Models\Grade::where('class_type_id', $class_type->id)->orderBy('mark_from')->get();
    $l86BandsFallback = \App\Models\Grade::whereNull('class_type_id')->orderBy('mark_from')->get();
    $l86PickBand = function($bands, $total){ foreach($bands as $b){ if($total >= $b->mark_from && $total <= $b->mark_to) return $b->name; } return null; };
    $l86GradeFor = function($total) use ($l86PickBand, $l86BandsPrimary, $l86BandsFallback){
        if($total === null || $total < 1) return null;
        return $l86PickBand($l86BandsPrimary, $total) ?: $l86PickBand($l86BandsFallback, $total);
    };
    $l86TotalById = [];
    foreach($marks as $mk0){ $l86TotalById[$mk0->id] = \App\Support\MarkScheme::subjectTotal($mk0); }
    $l86Entered = array_filter($l86TotalById, function($t){ return $t !== null; });
    $l86Avg = count($l86Entered) ? round(array_sum($l86Entered) / count($l86Entered), 1) : null;
    $l86High = count($l86Entered) ? max($l86Entered) : null;
    $l86Low = count($l86Entered) ? min($l86Entered) : null;
    $l86BandMap = function($g){ return ['name' => $g->name, 'from' => (int) $g->mark_from, 'to' => (int) $g->mark_to]; };
    $l86BandsJson = json_encode([
        'primary' => $l86BandsPrimary->map($l86BandMap)->values(),
        'fallback' => $l86BandsFallback->map($l86BandMap)->values(),
    ]);
@endphp
{{-- Back-button doctrine (owner, 2026-10-29): back returns to
     the section page the user came from — here the marks manage
     page for this exact exam/class/section/subject — never
     straight to the dashboard. --}}
<div class="mb-3">
    <a href="{{ route('marks.manage', [$exam_id, $my_class_id, $section_id, $subject_id]) }}" class="btn btn-light btn-sm"><i class="icon-arrow-left7 mr-1"></i> Back to Marks</a>
</div>
<form class="ajax-update" action="{{ route('marks.update', [$exam_id, $my_class_id, $section_id, $subject_id]) }}" method="post">
    @csrf @method('put')

    @if($scheme['configured'])
        {{-- Batch M2: configured scheme — one input per component,
             headed by the component's name and its own max marks. --}}
        <div class="alert alert-info border-0">
            <span>This subject uses a custom marking scheme:
                <strong>{{ collect($scheme['components'])->map(function($c){ return $c['name'].' ('.$c['max_marks'].')'; })->implode(' + ') }} = {{ $scheme['max_total'] }}</strong>.
                Leave a cell blank if the score has not been entered; enter 0 for a real zero.</span>
        </div>
        <div class="table-responsive">
        <table class="table table-striped l76-marks-table">
            <thead>
            <tr>
                <th>S/N</th>
                <th>STUDENT NAME</th>
                <th>ADM_NO</th>
                @foreach($scheme['components'] as $comp)
                    <th>{{ strtoupper($comp['name']) }} ({{ $comp['max_marks'] }})</th>
                @endforeach
                <th>TOTAL ({{ $scheme['max_total'] }})</th>
                <th>PERCENTAGE</th>
                <th>GRADE</th>
                <th>ACTIONS</th>
            </tr>
            </thead>
            <tbody>
            @foreach($marks->sortBy('user.name') as $mk)
                @php
                    $l86Total = $l86TotalById[$mk->id];
                    $l86Pct = ($l86Total === null || !$scheme['max_total']) ? null : round($l86Total / $scheme['max_total'] * 100, 1);
                    $l86Grade = $l86GradeFor($l86Total);
                @endphp
                <tr class="l86-row" data-search="{{ strtolower((optional($mk->user)->name ?? '—').' '.(optional(optional($mk->user)->student_record)->adm_no ?? '—')) }}">
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ optional($mk->user)->name ?? '—' }} </td>
                    <td>{{ optional(optional($mk->user)->student_record)->adm_no ?? '—' }}</td>
                    @foreach($scheme['components'] as $comp)
                        <td><span class="l115-mwrap"><input title="{{ $comp['name'] }}" min="0" max="{{ $comp['max_marks'] }}" class="form-control text-center l76-mark-input" name="comp_{{ $mk->id }}_{{ $comp['id'] }}" value="{{ \App\Support\MarkScheme::scoreFor($mk, $comp) }}" type="number"{{ $l86Locked ? ' disabled' : '' }}><span class="l115-mmax" aria-hidden="true">/ {{ $comp['max_marks'] }}</span></span></td>
                    @endforeach
                    <td><span class="l86-total l115-pill-total">{{ $l86Total ?? '—' }}</span></td>
                    <td><span class="l86-pct l115-pill-pct">{{ $l86Pct === null ? '—' : number_format($l86Pct, 1).'%' }}</span></td>
                    <td class="l86-grade">@if($l86Grade)<span class="l86-grade-pill" data-grade="{{ $l86Grade }}">{{ $l86Grade }}</span>@else — @endif</td>
                    <td class="l86-actions">
                        <div class="dropdown">
                            <button type="button" class="l86-kebab-btn" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" aria-label="Student actions"><i class="icon-more2"></i></button>
                            <div class="dropdown-menu dropdown-menu-right">
                                <a class="dropdown-item" href="{{ route('marks.show', [\App\Helpers\Qs::hash($mk->student_id), $mk->year]) }}"><i class="icon-table2"></i> View Marksheet</a>
                            </div>
                        </div>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        </div>
    @else
        {{-- Legacy scheme — the grid exactly as it has always been
             (CA 20 + CA 20 + Exam 60 over t1/t2/exm). One change, from
             the Batch M2 audit: min is 0, not 1 — a real zero must be
             enterable, and a blank cell means "not entered". --}}
        <div class="table-responsive">
        <table class="table table-striped l76-marks-table">
            <thead>
            <tr>
                <th>S/N</th>
                <th>STUDENT NAME</th>
                <th>ADM_NO</th>
                <th>1ST CA (20)</th>
                <th>2ND CA (20)</th>
                <th>EXAM (60)</th>
                <th>TOTAL ({{ $scheme['max_total'] }})</th>
                <th>PERCENTAGE</th>
                <th>GRADE</th>
                <th>ACTIONS</th>
            </tr>
            </thead>
            <tbody>
            @foreach($marks->sortBy('user.name') as $mk)
                @php
                    $l86Total = $l86TotalById[$mk->id];
                    $l86Pct = ($l86Total === null || !$scheme['max_total']) ? null : round($l86Total / $scheme['max_total'] * 100, 1);
                    $l86Grade = $l86GradeFor($l86Total);
                @endphp
                <tr class="l86-row" data-search="{{ strtolower((optional($mk->user)->name ?? '—').' '.(optional(optional($mk->user)->student_record)->adm_no ?? '—')) }}">
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ optional($mk->user)->name ?? '—' }} </td>
                    <td>{{ optional(optional($mk->user)->student_record)->adm_no ?? '—' }}</td>

    {{--                CA AND EXAMS --}}
                    <td><span class="l115-mwrap"><input title="1ST CA" min="0" max="20" class="form-control text-center l76-mark-input" name="t1_{{ $mk->id }}" value="{{ $mk->t1 }}" type="number"{{ $l86Locked ? ' disabled' : '' }}><span class="l115-mmax" aria-hidden="true">/ 20</span></span></td>
                    <td><span class="l115-mwrap"><input title="2ND CA" min="0" max="20" class="form-control text-center l76-mark-input" name="t2_{{ $mk->id }}" value="{{ $mk->t2 }}" type="number"{{ $l86Locked ? ' disabled' : '' }}><span class="l115-mmax" aria-hidden="true">/ 20</span></span></td>
                    <td><span class="l115-mwrap"><input title="EXAM" min="0" max="60" class="form-control text-center l76-mark-input" name="exm_{{ $mk->id }}" value="{{ $mk->exm }}" type="number"{{ $l86Locked ? ' disabled' : '' }}><span class="l115-mmax" aria-hidden="true">/ 60</span></span></td>

                    <td><span class="l86-total l115-pill-total">{{ $l86Total ?? '—' }}</span></td>
                    <td><span class="l86-pct l115-pill-pct">{{ $l86Pct === null ? '—' : number_format($l86Pct, 1).'%' }}</span></td>
                    <td class="l86-grade">@if($l86Grade)<span class="l86-grade-pill" data-grade="{{ $l86Grade }}">{{ $l86Grade }}</span>@else — @endif</td>
                    <td class="l86-actions">
                        <div class="dropdown">
                            <button type="button" class="l86-kebab-btn" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" aria-label="Student actions"><i class="icon-more2"></i></button>
                            <div class="dropdown-menu dropdown-menu-right">
                                <a class="dropdown-item" href="{{ route('marks.show', [\App\Helpers\Qs::hash($mk->student_id), $mk->year]) }}"><i class="icon-table2"></i> View Marksheet</a>
                            </div>
                        </div>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        </div>
    @endif

    {{-- Batch 114 (Loop 86): live summary strip — computed from the
         grid's current values (students with nothing entered yet are
         excluded, exactly like the server's totals). Loop 115: the
         strip and the action bar are ONE slim footer strip inside
         the grid card, as in the reference — stats left, actions
         right. All JS hooks (.l86-avg/.l86-high/.l86-low, #l86-dirty,
         .l86-reset) are unchanged. --}}
    <div class="l86-nomatch" id="l86-nomatch" hidden>No student matches your search.</div>

    <div class="l76-actionbar l115-actionbar">
        <div class="l86-summary l115-summary">
            <div class="l86-stat">
                <span class="l86-stat-ic l86-stat-ic-users"><i class="icon-users"></i></span>
                <div><strong>{{ $marks->count() }}</strong><span>Total Students</span></div>
            </div>
            <div class="l86-stat">
                <span class="l86-stat-ic l86-stat-ic-avg"><i class="icon-stats-bars2"></i></span>
                <div><strong class="l86-avg">{{ $l86Avg === null ? '—' : number_format($l86Avg, 1) }}</strong><span>Subject Average</span></div>
            </div>
            <div class="l86-stat">
                <span class="l86-stat-ic l86-stat-ic-high"><i class="icon-arrow-up5"></i></span>
                <div><strong class="l86-high">{{ $l86High ?? '—' }}</strong><span>Highest Marks</span></div>
            </div>
            <div class="l86-stat">
                <span class="l86-stat-ic l86-stat-ic-low"><i class="icon-arrow-down5"></i></span>
                <div><strong class="l86-low">{{ $l86Low ?? '—' }}</strong><span>Lowest Marks</span></div>
            </div>
        </div>
        <span class="l86-dirty-hint" id="l86-dirty" hidden><i class="icon-pencil7"></i> Unsaved changes</span>
        <button type="button" class="btn btn-light l86-reset"{{ $l86Locked ? ' disabled' : '' }}><i class="icon-reset"></i> Reset</button>
        <button type="submit" class="btn btn-primary"{{ $l86Locked ? ' disabled' : '' }}>Update Marks <i class="icon-paperplane ml-2"></i></button>
    </div>
</form>

<script>
(function(){
    var form = document.querySelector('form.ajax-update');
    if(!form) return;
    var MAX_TOTAL = {{ (int) $scheme['max_total'] }};
    var BANDS = {!! $l86BandsJson !!};
    function gradeFor(total){
        if(total === null || total < 1) return null;
        var sets = [BANDS.primary, BANDS.fallback];
        for(var s = 0; s < sets.length; s++){
            for(var i = 0; i < sets[s].length; i++){
                if(total >= sets[s][i].from && total <= sets[s][i].to) return sets[s][i].name;
            }
        }
        return null;
    }
    function rowTotal(row){
        var any = false, total = 0;
        row.querySelectorAll('input[type="number"]').forEach(function(inp){
            if(inp.value !== ''){ any = true; total += parseInt(inp.value, 10) || 0; }
            inp.classList.toggle('l86-dirty', inp.value !== inp.defaultValue);
        });
        return any ? total : null;
    }
    function renderRow(row){
        var total = rowTotal(row);
        var tCell = row.querySelector('.l86-total'), pCell = row.querySelector('.l86-pct'), gCell = row.querySelector('.l86-grade');
        if(tCell) tCell.textContent = total === null ? '—' : total;
        if(pCell) pCell.textContent = (total === null || !MAX_TOTAL) ? '—' : (Math.round(total / MAX_TOTAL * 1000) / 10).toFixed(1) + '%';
        if(gCell){
            var g = gradeFor(total);
            gCell.innerHTML = g ? '<span class="l86-grade-pill" data-grade="' + g + '">' + g + '</span>' : '—';
        }
        return total;
    }
    function renderSummary(){
        var totals = [];
        form.querySelectorAll('tr.l86-row').forEach(function(row){
            var t = rowTotal(row);
            if(t !== null) totals.push(t);
        });
        var avg = form.querySelector('.l86-avg'), high = form.querySelector('.l86-high'), low = form.querySelector('.l86-low');
        if(avg) avg.textContent = totals.length ? (Math.round(totals.reduce(function(a, b){ return a + b; }, 0) / totals.length * 10) / 10).toFixed(1) : '—';
        if(high) high.textContent = totals.length ? Math.max.apply(null, totals) : '—';
        if(low) low.textContent = totals.length ? Math.min.apply(null, totals) : '—';
        var hint = document.getElementById('l86-dirty');
        if(hint) hint.hidden = !form.querySelector('input.l86-dirty');
    }
    function renderAll(){
        form.querySelectorAll('tr.l86-row').forEach(renderRow);
        renderSummary();
    }
    form.addEventListener('input', function(e){
        var row = e.target.closest ? e.target.closest('tr.l86-row') : null;
        if(row) renderRow(row);
        renderSummary();
    });
    var resetBtn = form.querySelector('.l86-reset');
    if(resetBtn) resetBtn.addEventListener('click', function(){ form.reset(); renderAll(); });
    var search = document.getElementById('l86-search');
    if(search) search.addEventListener('input', function(){
        var q = search.value.trim().toLowerCase(), visible = 0;
        form.querySelectorAll('tr.l86-row').forEach(function(row){
            var show = !q || (row.getAttribute('data-search') || '').indexOf(q) !== -1;
            row.style.display = show ? '' : 'none';
            if(show) visible++;
        });
        var nm = document.getElementById('l86-nomatch');
        if(nm) nm.hidden = visible !== 0;
    });
    renderAll();
})();
</script>
