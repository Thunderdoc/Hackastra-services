@extends('layouts.master')
@section('page_title', 'Select Student Marksheet')
@section('content')
    {{-- Batch 1A: rebuilt on the shared list_page pattern (Loop 28).
         Same form contract as before — POST marks.bulk_select, the
         Class select drives the Section select via the global
         getClassSections() AJAX helper, and the results table keeps
         its DataTable classes/initialisation. --}}
    @component('partials.patterns.list_page')
        @slot('title')
            <i class="icon-books mr-2"></i> Select Student Marksheet
        @endslot
        @slot('subtitle')
            Choose a class and section to list its students, then open a student's marksheet.
        @endslot

        @slot('filters')
            <form method="post" action="{{ route('marks.bulk_select') }}" class="pat-filter-form">
                @csrf
                <div class="pat-filter-field">
                    <label for="my_class_id">Class:</label>
                    <select required onchange="getClassSections(this.value)" id="my_class_id" name="my_class_id" class="form-control select">
                        <option value="">Select Class</option>
                        @foreach($my_classes as $c)
                            <option {{ ($selected && $my_class_id == $c->id) ? 'selected' : '' }} value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="pat-filter-field">
                    <label for="section_id">Section:</label>
                    <select required id="section_id" name="section_id" data-placeholder="Select Class First" class="form-control select">
                        @if($selected)
                            @foreach($sections as $s)
                                <option {{ ($section_id == $s->id) ? 'selected' : '' }} value="{{ $s->id }}">{{ $s->name }}</option>
                            @endforeach
                        @endif
                    </select>
                </div>

                <div class="pat-filter-action">
                    <button type="submit" class="btn btn-primary">View Marksheets <i class="icon-paperplane ml-2"></i></button>
                </div>
            </form>
        @endslot

        @if($selected)
            <table class="table datatable-button-html5-columns">
                <thead>
                <tr>
                    <th>S/N</th>
                    <th>Photo</th>
                    <th>Name</th>
                    <th>ADM_No</th>
                    <th>Action</th>
                </tr>
                </thead>
                <tbody>
                @foreach($students as $s)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td><img class="rounded-circle" style="height: 40px; width: 40px;" src="{{ $s->user->photo }}" alt="photo"></td>
                        <td>{{ $s->user->name }}</td>
                        <td>{{ $s->adm_no }}</td>
                        <td><a class="btn btn-danger" href="{{ route('marks.year_select', Qs::hash($s->user_id)) }}">View Marksheet</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @else
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-books',
                'message' => 'No class selected yet. Pick a class and section above, then choose View Marksheets to list its students.',
            ])
        @endif
    @endcomponent
@endsection
