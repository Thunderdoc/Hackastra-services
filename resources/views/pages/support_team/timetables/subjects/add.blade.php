<div class="tab-pane fade" id="add-sub">
    {{-- Workstream BW: fields moved onto the pattern grid
         (pat-fields/pat-field). Same .ajax-store POST to tt.store
         with the hidden ttr_id, same fields and bindings; the tab
         pane shell is unchanged. --}}
    <form class="ajax-store" method="post" action="{{ route('tt.store') }}">
        @csrf <input name="ttr_id" value="{{ $ttr->id }}" type="hidden">

        <div class="pat-fields">
            @if($ttr->exam_id)
                {{--EXAM DATE--}}
                <div class="pat-field">
                    <label for="exam_date">Exam Date <span class="text-danger">*</span></label>
                    <input id="exam_date" autocomplete="off" name="exam_date" value="{{ old('exam_date') }}" required type="text" class="form-control date-pick" placeholder="Select Date...">
                </div>
            @else
                {{--DAY--}}
                <div class="pat-field">
                    <label for="day">Day <span class="text-danger">*</span></label>
                    <select id="day" name="day" required class="form-control select" data-placeholder="Select Day...">
                        <option value=""></option>
                        @foreach(Qs::getDaysOfTheWeek() as $dw)
                            <option {{ old('day') == $dw ? 'selected' : '' }} value="{{ $dw }}">{{ $dw }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            {{--SUBJECT--}}
            <div class="pat-field">
                <label for="subject_id">Subject <span class="text-danger">*</span></label>
                <select required data-placeholder="Select Subject" class="form-control select-search" name="subject_id" id="subject_id">
                    <option value=""></option>
                    @foreach($subjects as $sub)
                        <option {{ old('subject_id') == $sub->id ? 'selected' : '' }} value="{{ $sub->id }}">{{ $sub->name }}</option>
                    @endforeach
                </select>
            </div>

            {{--TIME SLOT--}}
            <div class="pat-field">
                <label for="ts_id">Time Slot <span class="text-danger">*</span></label>
                <select data-placeholder="Select Time..." required class="select form-control" name="ts_id" id="ts_id">
                    <option value=""></option>
                    @foreach($time_slots as $tms)
                        <option {{ old('ts_id') == $tms->full ? 'selected' : '' }} value="{{ $tms->id }}">{{ $tms->full}}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="text-right mt-3">
            <button type="submit" class="btn btn-primary">Submit form <i class="icon-paperplane ml-2"></i></button>
        </div>
    </form>
</div>
