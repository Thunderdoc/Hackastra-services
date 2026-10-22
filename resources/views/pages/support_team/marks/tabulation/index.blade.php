@extends('layouts.master')
@section('page_title', 'Tabulation Sheet')
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            {{-- Workstream CO: card title removed — it repeated the
                 page title verbatim (the common header owns titles). --}}
            {!! Qs::getPanelOptions() !!}
        </div>

        <div class="card-body">
        {{-- Workstream BW: selector fields moved onto the pattern
             grid (pat-fields/pat-field). Same POST to
             marks.tabulation_select, same selects, bindings and
             the getClassSections() cascade; the report card below
             is unchanged. --}}
        <form method="post" action="{{ route('marks.tabulation_select') }}">
                    @csrf
                    <div class="pat-fields">
                        <div class="pat-field">
                            <label for="exam_id">Exam:</label>
                            <select required id="exam_id" name="exam_id" class="form-control select" data-placeholder="Select Exam">
                                @foreach($exams as $exm)
                                    <option {{ ($selected && $exam_id == $exm->id) ? 'selected' : '' }} value="{{ $exm->id }}">{{ $exm->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="pat-field">
                            <label for="my_class_id">Class:</label>
                            <select onchange="getClassSections(this.value)" required id="my_class_id" name="my_class_id" class="form-control select" data-placeholder="Select Class">
                                <option value=""></option>
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
                    </div>

                    <div class="text-right mt-3">
                        <button type="submit" class="btn btn-primary">View Sheet <i class="icon-paperplane ml-2"></i></button>
                    </div>

                </form>
        </div>
    </div>

    {{--if Selction Has Been Made --}}

    @if($selected)
        <div class="card">
            <div class="card-header">
                <h6 class="card-title font-weight-bold">Tabulation Sheet for {{ $my_class->name.' '.$section->name.' - '.$ex->name.' ('.$year.')' }}</h6>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                    <tr>
                        <th class="text-center">#</th>
                        <th>NAMES OF STUDENTS IN CLASS</th>
                       @foreach($subjects as $sub)
                       <th class="text-center" title="{{ $sub->name }}">{{ strtoupper($sub->slug ?: $sub->name) }}</th>
                       @endforeach
                        {{--@if($ex->term == 3)
                        <th>1ST TERM TOTAL</th>
                        <th>2ND TERM TOTAL</th>
                        <th>3RD TERM TOTAL</th>
                        <th style="color: darkred">CUM Total</th>
                        <th style="color: darkblue">CUM Average</th>
                        @endif--}}
                        <th class="text-center text-danger">Total</th>
                        <th class="text-center text-primary">Average</th>
                        <th class="text-center text-success">Position</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($students as $s)
                        <tr>
                            <td class="text-center">{{ $loop->iteration }}</td>
                            <td>{{ $s->user->name }}</td>
                            @foreach($subjects as $sub)
                            <td class="text-center">{{ $marks->where('student_id', $s->user_id)->where('subject_id', $sub->id)->first()->$tex ?? '-' ?: '-' }}</td>
                            @endforeach

                            {{--@if($ex->term == 3)
                                --}}{{--1st term Total--}}{{--
                            <td>{{ Mk::getTermTotal($s->user_id, 1, $year) ?? '-' }}</td>
                            --}}{{--2nd Term Total--}}{{--
                            <td>{{ Mk::getTermTotal($s->user_id, 2, $year) ?? '-' }}</td>
                            --}}{{--3rd Term total--}}{{--
                            <td>{{ Mk::getTermTotal($s->user_id, 3, $year) ?? '-' }}</td>
                            @endif--}}

                            <td class="text-center font-weight-bold text-danger">{{ $exr->where('student_id', $s->user_id)->first()->total ?: '-' }}</td>
                            <td class="text-center font-weight-bold text-primary">{{ $exr->where('student_id', $s->user_id)->first()->ave ?: '-' }}</td>
                            <td class="text-center font-weight-bold text-success">{!! Mk::getSuffix($exr->where('student_id', $s->user_id)->first()->pos) ?: '-' !!}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                </div>
                {{--Print Button--}}
                <div class="text-center mt-4">
                    <a target="_blank" href="{{  route('marks.print_tabulation', [$exam_id, $my_class_id, $section_id]) }}" class="btn btn-danger btn-lg"><i class="icon-printer mr-2"></i> Print Tabulation Sheet</a>
                </div>
            </div>
        </div>
    @endif
@endsection
