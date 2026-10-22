<div class="tab-pane fade" id="edit-subs">
    {{--If TimeTables Exist--}}
    @if($tts->count())
        <div class="l7-table-scroll">
            <table class="table ttx-table">
                <thead>
                <tr>
                    <th>{{ $ttr->exam_id ? 'Exam Date' : 'Day' }}</th>
                    <th>Time Slot</th>
                    <th>Subject</th>
                    <th>Teacher</th>
                    <th style="width:170px">Action</th>
                </tr>
                </thead>
                <tbody>
                @foreach($tts as $tt)
                    <tr>
                        <td><strong>{{ ($tt->exam_date ? date('D, d/m/Y', strtotime($tt->exam_date)) : $tt->day) }}</strong></td>
                        <td>{{ $tt->time_slot->full }}</td>
                        <td>{{ $tt->subject->name }}</td>
                        <td>{{ optional(optional($tt->subject)->teacher)->name ?: '—' }}</td>
                        <td>
                            <div class="ttx-actions">
                                <button type="button" class="btn btn-light btn-sm ttx-edit-btn"
                                        data-update="{{ route('tt.update', $tt->id) }}"
                                        data-day="{{ $tt->day }}"
                                        data-exam="{{ $tt->exam_date }}"
                                        data-subject="{{ $tt->subject_id }}"
                                        data-ts="{{ $tt->ts_id }}">
                                    <i class="icon-pencil mr-1"></i> Edit
                                </button>
                                <a onclick="confirmDelete(this.id)" href="#" id="{{ $tt->id }}" title="DELETE" class="btn btn-light btn-sm text-danger"><i class="icon-trash mr-1"></i> Delete</a>
                                <form method="post" id="item-delete-{{ $tt->id }}" action="{{ route('tt.delete', $tt->id) }}" class="hidden">@csrf @method('delete')</form>
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        {{-- Shared edit form (one for the whole table; populated per row by
             the script in manage.blade.php). Fields, names, method and the
             per-record update endpoint are the legacy form's, unchanged. --}}
        <div class="modal fade" id="ttx-edit-modal" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title font-weight-bold">Edit Timetable Entry</h6>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    </div>
                    <form id="ttx-edit-form" method="post" action="">
                        @csrf @method('PUT')
                        <input name="ttr_id" value="{{ $ttr->id }}" type="hidden">
                        <div class="modal-body">
                            {{-- Workstream BW: modal fields moved onto the
                                 pattern grid (pat-fields/pat-field). Field
                                 names are unchanged — the manage page's
                                 script still populates this form by name. --}}
                            <div class="pat-fields">
                                @if($ttr->exam_id)
                                    {{--EXAM DATE--}}
                                    <div class="pat-field pat-field-wide">
                                        <label>Exam Date <span class="text-danger">*</span></label>
                                        <input autocomplete="off" name="exam_date" required type="text" class="form-control date-pick" placeholder="Select Date...">
                                    </div>
                                @else
                                    {{--DAY--}}
                                    <div class="pat-field pat-field-wide">
                                        <label>Day <span class="text-danger">*</span></label>
                                        <select name="day" required class="form-control">
                                            @foreach(Qs::getDaysOfTheWeek() as $dw)
                                                <option value="{{ $dw }}">{{ $dw }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                @endif

                                {{--SUBJECT--}}
                                <div class="pat-field pat-field-wide">
                                    <label>Subject <span class="text-danger">*</span></label>
                                    <select required class="form-control" name="subject_id">
                                        @foreach($subjects as $sub)
                                            <option value="{{ $sub->id }}">{{ $sub->name }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                {{--TIME SLOT--}}
                                <div class="pat-field pat-field-wide">
                                    <label>Time Slot <span class="text-danger">*</span></label>
                                    <select data-placeholder="Select Time..." required class="form-control" name="ts_id">
                                        <option value=""></option>
                                        @foreach($time_slots as $tms)
                                            <option value="{{ $tms->id }}">{{ $tms->full }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Submit Form <i class="icon-paperplane ml-2"></i></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @else
        <div class="alert alert-info text-center">There are NO Records to Display. Add Subjects To The TimeTable Record
            & Refresh the page
        </div>
    @endif
</div>
