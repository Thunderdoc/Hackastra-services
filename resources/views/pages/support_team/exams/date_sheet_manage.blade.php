@extends('layouts.master')
@section('page_title', 'Exam Date Sheet')
@section('content')

    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">Exam Date Sheet</h6>
            <div class="header-elements">
                <a href="{{ route('exam_dates.generate.form') }}" class="btn btn-success btn-sm"><i class="icon-magic-wand mr-1"></i> Auto-Generate Date Sheet</a>
                <a href="{{ route('exam_duties.index') }}" class="btn btn-light btn-sm"><i class="icon-eye mr-1"></i> Invigilation Duties</a>
            </div>
            {!! Qs::getPanelOptions() !!}
        </div>

        <div class="card-body">
            <div class="alert alert-info border-0">
                <span>Set the date of every subject's paper for an exam, class by class. Students see the sheet for
                their own class, parents for their children's classes, and teachers for the classes they teach —
                exactly as saved here. A subject left without a date shows as <strong>“Not announced”</strong>;
                dates are never guessed. To remove a date, clear it and save.</span>
            </div>

            {{-- Exam + class picker (GET, same pattern as Admit Cards) --}}
            <form method="get" action="{{ route('exam_dates.manage') }}">
                <div class="pat-fields">
                    <div class="pat-field">
                        <label for="exam_id">Exam <span class="text-danger">*</span></label>
                        <select id="exam_id" name="exam_id" required class="form-control" onchange="this.form.submit()">
                            <option value="">Select Exam</option>
                            @foreach($exams as $ex)
                                <option value="{{ $ex->id }}" {{ (int) $exam_id === $ex->id ? 'selected' : '' }}>
                                    {{ $ex->name }} — Term {{ $ex->term }} ({{ $ex->year }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="pat-field">
                        <label for="class_id">Class <span class="text-danger">*</span></label>
                        <select id="class_id" name="class_id" required class="form-control" onchange="this.form.submit()">
                            <option value="">Select Class</option>
                            @foreach($my_classes as $mc)
                                <option value="{{ $mc->id }}" {{ (int) $class_id === $mc->id ? 'selected' : '' }}>{{ $mc->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </form>

            @if($selected)
                <form method="post" action="{{ route('exam_dates.save') }}" class="mt-4">
                    @csrf
                    <input type="hidden" name="exam_id" value="{{ $exam->id }}">
                    <input type="hidden" name="class_id" value="{{ $my_class_sel->id }}">

                    <h6 class="mb-2">{{ $exam->name }} — {{ $my_class_sel->name }}
                        <span class="text-muted font-size-sm">(Term {{ $exam->term }} &middot; {{ $exam->year }})</span>
                    </h6>

                    <div class="table-responsive">
                        <table class="table l103-table">
                            <thead>
                            <tr>
                                <th>Subject</th>
                                <th style="width: 190px;">Exam Date</th>
                                <th style="width: 140px;">Start Time</th>
                                <th>Note <span class="text-muted font-weight-normal">(venue, instructions…)</span></th>
                                <th style="width: 130px;">Status</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($subjects as $s)
                                <tr>
                                    <td class="font-weight-semibold">{{ $s->name }}</td>
                                    <td>
                                        <input type="date" class="form-control"
                                               name="dates[{{ $s->id }}][exam_date]"
                                               value="{{ $s->date_entry ? $s->date_entry->exam_date : '' }}">
                                    </td>
                                    <td>
                                        <input type="time" class="form-control"
                                               name="dates[{{ $s->id }}][start_time]"
                                               value="{{ ($s->date_entry && $s->date_entry->start_time) ? substr($s->date_entry->start_time, 0, 5) : '' }}">
                                    </td>
                                    <td>
                                        <input type="text" class="form-control" maxlength="100"
                                               name="dates[{{ $s->id }}][note]"
                                               value="{{ $s->date_entry ? $s->date_entry->note : '' }}"
                                               placeholder="Optional">
                                    </td>
                                    <td>
                                        @if($s->date_entry)
                                            <span class="badge badge-success">Date set</span>
                                        @else
                                            <span class="badge badge-secondary">Not announced</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="text-right mt-3">
                        <a href="{{ route('exam_dates.show', ['exam_id' => $exam->id, 'class_id' => $my_class_sel->id]) }}" class="btn btn-light">
                            <i class="icon-eye mr-1"></i> Preview Sheet
                        </a>
                        <button type="submit" class="btn btn-primary">Save Date Sheet <i class="icon-paperplane ml-2"></i></button>
                    </div>
                </form>
            @elseif($exams->isEmpty())
                <div class="alert alert-warning border-0 mt-3">
                    <span>No exams have been created for the current session ({{ Qs::getCurrentSession() }}) yet.
                    Create the exam first in <a href="{{ route('exams.index') }}" class="alert-link">Exam List</a>, then set its dates here.</span>
                </div>
            @else
                <p class="text-muted mt-3 mb-0">Pick an exam and a class above to set its subject dates.</p>
            @endif
        </div>
    </div>

@endsection
