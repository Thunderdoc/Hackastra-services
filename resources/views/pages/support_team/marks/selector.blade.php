{{-- Workstream BW: the marks picker rebuilt on the pattern field
     grid (pat-fields/pat-field + pat-actionbar). Same POST to
     marks.selector, same four selects with the same ids, names,
     bindings and the getClassSubjects() cascade — only the layout
     language changed (was Bootstrap col-md-3 form-groups). Included
     by marks/index and marks/manage inside a pat-form-card. --}}
<form class="pat-form" method="post" action="{{ route('marks.selector') }}">
    @csrf
    <div class="pat-form-section">
        <div class="pat-fields">
            <div class="pat-field">
                <label for="exam_id">Exam:</label>
                <select required id="exam_id" name="exam_id" data-placeholder="Select Exam" class="form-control select">
                    @foreach($exams as $ex)
                        <option {{ $selected && $exam_id == $ex->id ? 'selected' : '' }} value="{{ $ex->id }}">{{ $ex->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="pat-field">
                <label for="my_class_id">Class:</label>
                <select required onchange="getClassSubjects(this.value)" id="my_class_id" name="my_class_id" class="form-control select">
                    <option value="">Select Class</option>
                    @foreach($my_classes as $c)
                        <option {{ ($selected && $my_class_id == $c->id) ? 'selected' : '' }} value="{{ $c->id }}">{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="pat-field">
                <label for="section_id">Section:</label>
                <select required id="section_id" name="section_id" data-placeholder="Select Class First" class="form-control select">
                    @if($selected)
                        @foreach($sections->where('my_class_id', $my_class_id) as $s)
                            <option {{ $section_id == $s->id ? 'selected' : '' }} value="{{ $s->id }}">{{ $s->name }}</option>
                        @endforeach
                    @endif
                </select>
            </div>
            <div class="pat-field">
                <label for="subject_id">Subject:</label>
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
    <div class="pat-actionbar">
        <button type="submit" class="btn btn-primary">Manage Marks <i class="icon-paperplane ml-2"></i></button>
    </div>
</form>
