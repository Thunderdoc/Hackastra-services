@extends('layouts.master')
@section('page_title', 'Tabulation Sheet')
@section('page_breadcrumb')
    Marks <span class="crumb-sep">&rsaquo;</span> Tabulation Sheet
@endsection
@section('content')
  <div class="qs-tab">
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
            {{-- Workstream DA: results header — the class/exam context
                 stays (it is data, not a title echo), and the print
                 action moves up here as the app's standard small
                 light button (same route, same target as the old
                 oversized red block, which is retired). --}}
            <div class="card-header header-elements-inline">
                <h6 class="qs-tab-context mb-0">Tabulation Sheet for {{ $my_class->name.' '.$section->name.' - '.$ex->name.' ('.$year.')' }}</h6>
                <div class="header-elements">
                    <a target="_blank" href="{{ route('marks.print_tabulation', [$exam_id, $my_class_id, $section_id]) }}" class="btn btn-light btn-sm qs-tab-print"><i class="icon-printer mr-1"></i> Print Tabulation Sheet</a>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                <table class="table qs-tab-table">
                    <thead>
                    <tr>
                        <th class="qs-tab-sn">#</th>
                        <th>NAMES OF STUDENTS IN CLASS</th>
                       @foreach($subjects as $sub)
                       <th class="qs-tab-mark" title="{{ $sub->name }}">{{ strtoupper($sub->slug ?: $sub->name) }}</th>
                       @endforeach
                        <th class="qs-tab-total qs-tab-total-h">Total</th>
                        <th class="qs-tab-ave">Average</th>
                        <th class="qs-tab-pos">Position</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($students as $s)
                        @php $tabRec = $exr->where('student_id', $s->user_id)->first(); $tabPos = $tabRec->pos ?? null; @endphp
                        <tr>
                            <td class="qs-tab-sn">{{ $loop->iteration }}</td>
                            <td class="qs-tab-name">{{ $s->user->name }}</td>
                            @foreach($subjects as $sub)
                            <td class="qs-tab-mark">{{ $marks->where('student_id', $s->user_id)->where('subject_id', $sub->id)->first()->$tex ?? '-' ?: '-' }}</td>
                            @endforeach

                            <td class="qs-tab-total">{{ $tabRec->total ?: '-' }}</td>
                            <td class="qs-tab-ave">{{ $tabRec->ave ?: '-' }}</td>
                            <td class="qs-tab-pos">@if($tabPos)<span class="qs-tab-pos-chip qs-tab-pos-{{ $tabPos <= 3 ? $tabPos : 'n' }}">{!! Mk::getSuffix($tabPos) !!}</span>@else - @endif</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    @endif
  </div>
@endsection
