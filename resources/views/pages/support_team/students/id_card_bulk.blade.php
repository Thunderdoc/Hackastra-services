@extends('layouts.master')
@section('page_title', 'Bulk ID Cards')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; <a href="{{ route('students.index') }}">Students</a> &nbsp;/&nbsp; Bulk ID Cards
@endsection
@section('content')

    {{-- Wave 2 feature 4: whole-class/section ID card sheet. Pick a
         class (+ optional section); the admission-ordered roster
         previews below and downloads as one PDF. Class/section travel
         as query params, so the PDF link below just carries them on.
         Teachers only see their assigned classes in the dropdown. --}}

    <div class="card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">Bulk ID Cards</h6>
            {!! Qs::getPanelOptions() !!}
        </div>

        <div class="card-body">
            @if($classes->isEmpty())
                <p class="mb-0">No classes are assigned to you yet, so there is no roster to print.</p>
            @else
                <p class="text-muted">Select a class to preview its students. Narrow to one section if needed, then download the whole sheet as one PDF — one card per student.</p>

                <form method="get" action="{{ route('students.id_cards.bulk') }}">
                    <div class="row">
                        <div class="col-12 col-md-5">
                            <div class="form-group">
                                <label for="bulk_class">Class: <span class="text-danger">*</span></label>
                                <select name="class_id" id="bulk_class" class="form-control" required>
                                    <option value="">Choose a class..</option>
                                    @foreach($classes as $c)
                                        <option value="{{ $c->id }}" @if($my_class && $my_class->id == $c->id) selected @endif>{{ $c->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="col-12 col-md-4">
                            <div class="form-group">
                                <label for="bulk_section">Section:</label>
                                <select name="section_id" id="bulk_section" class="form-control">
                                    <option value="">All Sections</option>
                                    @foreach($sections as $s)
                                        <option value="{{ $s->id }}" data-class-id="{{ $s->my_class_id }}" @if($section && $section->id == $s->id) selected @endif>{{ $s->my_class->name.' '.$s->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="col-12 col-md-3 d-flex align-items-end">
                            <div class="form-group">
                                <button type="submit" class="btn btn-primary"><i class="icon-list mr-1"></i> Preview Roster</button>
                            </div>
                        </div>
                    </div>
                </form>
            @endif
        </div>
    </div>

    @if($my_class)
        <div class="card">
            <div class="card-header header-elements-inline">
                <h6 class="card-title">{{ $my_class->name }}@if($section) &middot; Section {{ $section->name }}@endif &middot; {{ $students->count() }} students</h6>
                <div class="header-elements">
                    <a href="{{ route('students.id_cards.bulk.pdf', array_filter(['class_id' => $my_class->id, 'section_id' => $section ? $section->id : null])) }}" class="btn btn-success btn-sm"><i class="icon-printer mr-1"></i> Download ID Cards (PDF)</a>
                </div>
            </div>

            <div class="card-body p-0">
                @if($students->isEmpty())
                    <p class="p-3 mb-0">No students found for this class and section.</p>
                @else
                    <div class="table-responsive">
                        <table class="table table-striped mb-0">
                            <thead>
                                <tr><th>Student</th><th>Adm No</th><th>Section</th></tr>
                            </thead>
                            <tbody>
                                @foreach($students as $sr)
                                    <tr>
                                        <td style="word-break: break-word;">{{ optional($sr->user)->name }}</td>
                                        <td>{{ $sr->adm_no ?: '—' }}</td>
                                        <td>{{ optional($sr->section)->name ?: '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    @endif

@endsection
@section('scripts')
    <script>
        // Section cascade: rebuild section options from a cached clone
        // on every class change (same pattern as the student list
        // picker — the site-wide Select2 notifies via jQuery trigger,
        // which vanilla change listeners never see).
        (function () {
            var classSel = document.getElementById('bulk_class');
            var sectionSel = document.getElementById('bulk_section');
            if (!classSel || !sectionSel) { return; }
            var allOptions = Array.prototype.slice.call(sectionSel.options);
            function cascade() {
                var picked = classSel.value;
                sectionSel.innerHTML = '';
                allOptions.forEach(function (opt) {
                    if (!opt.value || !picked || opt.getAttribute('data-class-id') === picked) {
                        sectionSel.appendChild(opt.cloneNode(true));
                    }
                });
                if (window.jQuery) { jQuery(sectionSel).trigger('change'); }
            }
            classSel.addEventListener('change', cascade);
            if (window.jQuery) { jQuery(classSel).on('change', cascade); }
            if (classSel.value) { cascade(); }
        })();
    </script>
@endsection
