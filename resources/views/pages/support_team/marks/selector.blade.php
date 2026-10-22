{{-- Workstream BW: the marks picker rebuilt on the pattern field
     grid (pat-fields/pat-field + pat-actionbar). Same POST to
     marks.selector, same four selects with the same ids, names,
     bindings and the getClassSubjects() cascade — only the layout
     language changed (was Bootstrap col-md-3 form-groups). Included
     by marks/index and marks/manage inside a pat-form-card.
     Loop 84: marks/index includes it with $marks_selector_full,
     which adds label icons + required asterisks, the helper line,
     a Reset button and the gated submit id — all presentation
     only; without the flag the output is the BW original. --}}
<form class="pat-form" method="post" action="{{ route('marks.selector') }}">
    @csrf
    <div class="pat-form-section">
        <div class="pat-fields">
            <div class="pat-field">
                <label for="exam_id">@if(!empty($marks_selector_full))<i class="icon-calendar3 l84-flabel-icon"></i> @endif<span>Exam:</span>@if(!empty($marks_selector_full)) <span class="l84-req" aria-hidden="true">*</span>@endif</label>
                <select required id="exam_id" name="exam_id" data-placeholder="Select Exam" class="form-control select">
                    @foreach($exams as $ex)
                        <option {{ $selected && $exam_id == $ex->id ? 'selected' : '' }} value="{{ $ex->id }}">{{ $ex->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="pat-field">
                <label for="my_class_id">@if(!empty($marks_selector_full))<i class="icon-graduation2 l84-flabel-icon"></i> @endif<span>Class:</span>@if(!empty($marks_selector_full)) <span class="l84-req" aria-hidden="true">*</span>@endif</label>
                <select required onchange="getClassSubjects(this.value)" id="my_class_id" name="my_class_id" class="form-control select">
                    <option value="">Select Class</option>
                    @foreach($my_classes as $c)
                        <option {{ ($selected && $my_class_id == $c->id) ? 'selected' : '' }} value="{{ $c->id }}">{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="pat-field">
                <label for="section_id">@if(!empty($marks_selector_full))<i class="icon-users l84-flabel-icon"></i> @endif<span>Section:</span>@if(!empty($marks_selector_full)) <span class="l84-req" aria-hidden="true">*</span>@endif</label>
                <select required id="section_id" name="section_id" data-placeholder="Select Class First" class="form-control select">
                    @if($selected)
                        @foreach($sections->where('my_class_id', $my_class_id) as $s)
                            <option {{ $section_id == $s->id ? 'selected' : '' }} value="{{ $s->id }}">{{ $s->name }}</option>
                        @endforeach
                    @endif
                </select>
            </div>
            <div class="pat-field">
                <label for="subject_id">@if(!empty($marks_selector_full))<i class="icon-books l84-flabel-icon"></i> @endif<span>Subject:</span>@if(!empty($marks_selector_full)) <span class="l84-req" aria-hidden="true">*</span>@endif</label>
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
    @if(!empty($marks_selector_full))
        {{-- Loop 84: helper line + Reset + gated submit, only in the
             full-chrome rendering (marks/index). marks/manage includes
             this partial without the flag and keeps the plain bar. --}}
        <div class="l84-info-line">
            <i class="icon-info3"></i>
            <span>After selecting exam, class, section and subject, you will be able to view the list of students and enter their marks.</span>
        </div>
    @endif
    <div class="pat-actionbar">
        @if(!empty($marks_selector_full))
            <button type="button" id="l84-reset-btn" class="btn btn-light l84-reset-btn">Reset</button>
            <button type="submit" id="l84-manage-btn" class="btn btn-primary">Manage Marks <i class="icon-paperplane ml-2"></i></button>
        @else
            <button type="submit" class="btn btn-primary">Manage Marks <i class="icon-paperplane ml-2"></i></button>
        @endif
    </div>
</form>
