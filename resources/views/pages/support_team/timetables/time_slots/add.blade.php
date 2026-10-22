<div class="alert alert-info">
    <span>You can add new time slots, or choose to use the existing time slots of another timetable. <strong>NB:</strong> Using existing time slots resets the current timetable.</span>
</div>

{{-- Workstream BW: both forms' fields moved onto the pattern grid
     (pat-fields/pat-field). The two ttx-inner-card shells, form
     actions/classes (incl. the ajax-store data-reload of
     #time_slots_table), all six time selects and the existing-slots
     select are unchanged; each Start/End time stays one compound
     field (hour/minute/meridian selects in a row). --}}
<div class="row">
    <div class="col-md-6">
        <div class="card ttx-inner-card">
            <div class="card-header header-elements-inline">
                <h6 class="font-weight-bold card-title">Add Time Slots</h6>
            </div>

            <div class="card-body">
                <form data-reload="#time_slots_table" class="ajax-store" method="post" action="{{ route('ts.store') }}">
                    @csrf
                    <input name="ttr_id" value="{{ $ttr->id }}" type="hidden">

                    <div class="pat-fields">
                        {{--TIME BEGIN--}}
                        <div class="pat-field pat-field-wide">
                            <label>Start Time <span class="text-danger">*</span></label>
                            <div class="row">
                                <div class="col-4">
                                    <select data-placeholder="Hour" required class="select-search form-control" name="hour_from" id="hour_from">
                                        <option value=""></option>
                                        @for($t=1; $t<=12; $t++)
                                            <option {{ old('hour_from') == $t ? 'selected' : '' }} value="{{ $t }}">{{ $t}}</option>
                                        @endfor
                                    </select>
                                </div>
                                <div class="col-4">
                                    <select data-placeholder="Minute" required class="select-search form-control" name="min_from" id="min_from">
                                        <option value=""></option>
                                        <option value="00">00</option>
                                        <option value="05">05</option>
                                        @for($t=10; $t<=55; $t+=5)
                                            <option {{ old('min_from') == $t ? 'selected' : '' }} value="{{ $t }}">{{ $t}}</option>
                                        @endfor
                                    </select>
                                </div>
                                <div class="col-4">
                                    <select data-placeholder="Meridian" required class="select form-control" name="meridian_from" id="meridian_from">
                                        <option value=""></option>
                                        <option {{ old('meridian_from') == 'AM' ? 'selected' : '' }} value="AM">AM</option>
                                        <option {{ old('meridian_from') == 'PM' ? 'selected' : '' }} value="PM">PM</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        {{--TIME END--}}
                        <div class="pat-field pat-field-wide">
                            <label>End Time <span class="text-danger">*</span></label>
                            <div class="row">
                                <div class="col-4">
                                    <select data-placeholder="Hour" required class="select-search form-control" name="hour_to" id="hour_to">
                                        <option value=""></option>
                                        @for($t=1; $t<=12; $t++)
                                            <option {{ old('hour_to') == $t ? 'selected' : '' }} value="{{ $t }}">{{ $t}}</option>
                                        @endfor
                                    </select>
                                </div>
                                <div class="col-4">
                                    <select data-placeholder="Minute" required class="select-search form-control" name="min_to" id="min_to">
                                        <option value=""></option>
                                        <option value="00">00</option>
                                        <option value="05">05</option>
                                        @for($t=10; $t<=55; $t+=5)
                                            <option {{ old('min_to') == $t ? 'selected' : '' }} value="{{ $t }}">{{ $t}}</option>
                                        @endfor
                                    </select>
                                </div>
                                <div class="col-4">
                                    <select data-placeholder="Meridian" required class="select form-control" name="meridian_to" id="meridian_to">
                                        <option value=""></option>
                                        <option {{ old('meridian_to') == 'AM' ? 'selected' : '' }} value="AM">AM</option>
                                        <option {{ old('meridian_to') == 'PM' ? 'selected' : '' }} value="PM">PM</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="text-right mt-3">
                        <button type="submit" class="btn btn-primary">Submit form <i class="icon-paperplane ml-2"></i></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card ttx-inner-card">
            <div class="card-header header-elements-inline">
                <h6 class="font-weight-bold card-title">Use Existing Time Slots</h6>
            </div>

            <div class="card-body">
                <form method="post" action="{{ route('ts.use', $ttr->id) }}">
                    @csrf

                    <div class="pat-fields">
                        <div class="pat-field pat-field-wide">
                            <label for="ttr_id">Select Existing Time Slots <span class="text-danger">*</span></label>
                            <select id="ttr_id" data-placeholder="Select..." required class="select-search form-control" name="ttr_id">
                                <option value=""></option>
                                @foreach($ts_existing as $ttr_ts)
                                    <option value="{{ $ttr_ts->id }}">{{ $ttr_ts->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="text-right mt-3">
                        <button type="submit" class="btn btn-success">Submit form <i class="icon-paperplane ml-2"></i></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
