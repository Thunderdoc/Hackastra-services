<div class="tab-pane fade" id="add-sub">
    <form class="ajax-store" method="post" action="{{ route('tt.store') }}">
        @csrf <input name="ttr_id" value="{{ $ttr->id }}" type="hidden">

        <div class="row">
            @if($ttr->exam_id)
                {{--EXAM DATE--}}
                <div class="col-md-4">
                    <div class="form-group">
                        <label class="font-weight-semibold">Exam Date <span class="text-danger">*</span></label>
                        <input autocomplete="off" name="exam_date" value="{{ old('exam_date') }}" required type="text" class="form-control date-pick" placeholder="Select Date...">
                    </div>
                </div>
            @else
                {{--DAY--}}
                <div class="col-md-4">
                    <div class="form-group">
                        <label for="day" class="font-weight-semibold">Day <span class="text-danger">*</span></label>
                        <select id="day" name="day" required class="form-control select" data-placeholder="Select Day...">
                            <option value=""></option>
                            @foreach(Qs::getDaysOfTheWeek() as $dw)
                                <option {{ old('day') == $dw ? 'selected' : '' }} value="{{ $dw }}">{{ $dw }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            @endif

            {{--SUBJECT--}}
            <div class="col-md-4">
                <div class="form-group">
                    <label for="subject_id" class="font-weight-semibold">Subject <span class="text-danger">*</span></label>
                    <select required data-placeholder="Select Subject" class="form-control select-search" name="subject_id" id="subject_id">
                        <option value=""></option>
                        @foreach($subjects as $sub)
                            <option {{ old('subject_id') == $sub->id ? 'selected' : '' }} value="{{ $sub->id }}">{{ $sub->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            {{--TIME SLOT--}}
            <div class="col-md-4">
                <div class="form-group">
                    <label for="ts_id" class="font-weight-semibold">Time Slot <span class="text-danger">*</span></label>
                    <select data-placeholder="Select Time..." required class="select form-control" name="ts_id" id="ts_id">
                        <option value=""></option>
                        @foreach($time_slots as $tms)
                            <option {{ old('ts_id') == $tms->full ? 'selected' : '' }} value="{{ $tms->id }}">{{ $tms->full}}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        <div class="text-right">
            <button type="submit" class="btn btn-primary">Submit form <i class="icon-paperplane ml-2"></i></button>
        </div>
    </form>
</div>
