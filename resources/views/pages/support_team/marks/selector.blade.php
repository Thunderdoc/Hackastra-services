{{-- Workstream BW: the marks picker rebuilt on the pattern field
     grid (pat-fields/pat-field + pat-actionbar). Same POST to
     marks.selector, same four selects with the same ids, names and
     bindings — only the layout language changed (was Bootstrap
     col-md-3 form-groups). Included by marks/index and marks/manage
     inside a pat-form-card.
     Item 4 (owner, 2026-11-01): the cascade is the marks selector's
     OWN feed (getMarkClassSubjects → marks.selector_options), which
     scopes a teacher's sections/subjects to her effective units;
     picking a section refines the subjects via refineMarkSubjects.
     The shared getClassSubjects endpoint is untouched.
     Loop 84: marks/index includes it with $marks_selector_full,
     which adds the helper line, a Reset button and the gated submit
     id — all presentation only; without the flag the output is the
     BW original.
     Loop 115 (reference replica): labels are plain text with a
     required asterisk on BOTH pages (no icons, no colons — "Exam *"),
     the form carries .l115-selector-form so the four fields sit in
     ONE row on wide screens, and the plain-mode submit becomes a
     quiet "Change" button (the manage page's Examination Details
     card has no primary action in the reference; the button must
     stay because changing a select does not navigate by itself).
     Visual parity (Light Luxe sample, 2026-11-01): the labels stay
     plain, and each field's icon now sits INSIDE its select box via
     the l10-sel-wrap / l10-sel-ic pair — same icon classes as the
     old label icons, same fields, ids and cascade. --}}
<form class="pat-form l115-selector-form" method="post" action="{{ route('marks.selector') }}">
    @csrf
    <div class="pat-form-section">
        <div class="pat-fields">
            <div class="pat-field">
                <label for="exam_id"><span>Exam</span> <span class="l84-req" aria-hidden="true">*</span></label>
                <div class="l10-sel-wrap"><span class="l10-sel-ic"><i class="icon-calendar3"></i></span>
                <select required id="exam_id" name="exam_id" data-placeholder="Select Exam" class="form-control select">
                    @foreach($exams as $ex)
                        {{-- Wave 8: data-scope drives the class filter
                             (filterExamsByClass in custom_js): 0 =
                             global exam, otherwise its own class. --}}
                        <option data-scope="{{ $ex->my_class_id }}" {{ $selected && $exam_id == $ex->id ? 'selected' : '' }} value="{{ $ex->id }}">{{ $ex->name }} ({{ $ex->periodLabel() }})</option>
                    @endforeach
                </select>
                </div>
            </div>
            <div class="pat-field">
                <label for="my_class_id"><span>Class</span> <span class="l84-req" aria-hidden="true">*</span></label>
                <div class="l10-sel-wrap"><span class="l10-sel-ic"><i class="icon-home4"></i></span>
                <select required onchange="getMarkClassSubjects(this.value); filterExamsByClass(this.value)" id="my_class_id" name="my_class_id" class="form-control select">
                    <option value="">Select Class</option>
                    @foreach($my_classes as $c)
                        <option {{ ($selected && $my_class_id == $c->id) ? 'selected' : '' }} value="{{ $c->id }}">{{ $c->name }}</option>
                    @endforeach
                </select>
                </div>
            </div>
            <div class="pat-field">
                <label for="section_id"><span>Section</span> <span class="l84-req" aria-hidden="true">*</span></label>
                <div class="l10-sel-wrap"><span class="l10-sel-ic"><i class="icon-users"></i></span>
                <select required onchange="refineMarkSubjects()" id="section_id" name="section_id" data-placeholder="Select Class First" class="form-control select">
                    @if($selected)
                        @foreach($sections->where('my_class_id', $my_class_id) as $s)
                            <option {{ $section_id == $s->id ? 'selected' : '' }} value="{{ $s->id }}">{{ $s->name }}</option>
                        @endforeach
                    @endif
                </select>
                </div>
            </div>
            <div class="pat-field">
                <label for="subject_id"><span>Subject</span> <span class="l84-req" aria-hidden="true">*</span></label>
                <div class="l10-sel-wrap"><span class="l10-sel-ic"><i class="icon-books"></i></span>
                <select required id="subject_id" name="subject_id" data-placeholder="Select Class First" class="form-control select-search">
                    @if($selected)
                        @foreach($subjects->where('my_class_id', $my_class_id) as $s)
                            <option {{ $subject_id == $s->id ? 'selected' : '' }} value="{{ $s->id }}">{{ $s->name }}</option>
                        @endforeach
                    @endif
                </select>
                </div>
            </div>
        </div>
    </div>
    @if(!empty($marks_selector_full))
        {{-- Item 10 (Light Luxe): the four stat tiles. They show REAL
             data only, filled by the marks/index script from the
             entry-state response once a full unit is chosen; before
             that every value is the neutral placeholder. --}}
        <div class="l10-tiles" id="l10-tiles">
            <div class="l10-tile">
                <span class="l10-tile-ic l10-tone-blue"><i class="icon-users"></i></span>
                <span class="l10-tile-body"><span class="l10-tile-n" id="l10-t-students">&mdash;</span><span class="l10-tile-l" id="l10-t-students-l">Students</span></span>
            </div>
            <div class="l10-tile">
                <span class="l10-tile-ic l10-tone-violet"><i class="icon-books"></i></span>
                <span class="l10-tile-body"><span class="l10-tile-n" id="l10-t-subject">&mdash;</span><span class="l10-tile-l">Subject</span></span>
            </div>
            <div class="l10-tile">
                <span class="l10-tile-ic l10-tone-green"><i class="icon-stats-bars2"></i></span>
                <span class="l10-tile-body"><span class="l10-tile-n" id="l10-t-max">&mdash;</span><span class="l10-tile-l" id="l10-t-max-l">Max marks</span></span>
            </div>
            <div class="l10-tile">
                <span class="l10-tile-ic l10-tone-amber"><i class="icon-lock2"></i></span>
                <span class="l10-tile-body"><span class="l10-tile-n" id="l10-t-entry">&mdash;</span><span class="l10-tile-l" id="l10-t-entry-l">Entry state</span></span>
            </div>
        </div>
    @endif
    <div class="pat-actionbar l10-actionbar">
        @if(!empty($marks_selector_full))
            {{-- The entry-state reason line lives here now (item 10):
                 pulse + text on the left, Reset + Manage Marks on the
                 right. #l84-entry-note keeps its id and role; the
                 marks/index script fills it for every state. --}}
            <span class="l10-status"><span class="l10-pulse" id="l10-pulse"></span><span id="l84-entry-note" role="status">Select exam, class, section and subject — the entry state will show here.</span></span>
            <span class="l10-sp"></span>
            <button type="button" id="l84-reset-btn" class="btn btn-light l84-reset-btn">Reset</button>
            <button type="submit" id="l84-manage-btn" class="btn btn-primary l10-manage-btn" disabled><span id="l84-manage-btn-label">Not Enabled</span> <i class="icon-paperplane ml-2"></i></button>
        @else
            {{-- Beauty Batch 3 (audit B8): the plain (manage-page)
                 action bar held a lone Change button in an otherwise
                 empty full-width strip. It now names the current unit
                 on the left, so the bar reads as a summary with its
                 action instead of a dead band. Presentation only —
                 the form, its fields and the submit are unchanged. --}}
            @php
                $lb3Unit = collect([
                    optional($exams->firstWhere('id', $exam_id ?? null))->name,
                    optional($my_classes->firstWhere('id', $my_class_id ?? null))->name,
                    !empty($selected) && !empty($section_id) ? 'Section ' . optional($sections->firstWhere('id', $section_id))->name : null,
                    !empty($selected) && !empty($subject_id) ? optional($subjects->firstWhere('id', $subject_id))->name : null,
                ])->filter()->implode(' · ');
            @endphp
            @if($lb3Unit !== '')
                <span class="lb3-unit">{{ $lb3Unit }}</span>
            @endif
            <button type="submit" class="btn btn-light l115-change-btn">Change</button>
        @endif
    </div>
</form>
