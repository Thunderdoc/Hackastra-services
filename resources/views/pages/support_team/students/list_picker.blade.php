@extends('layouts.master')
@section('page_title', 'Student Information')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; <a href="{{ route('students.index') }}">Students</a> &nbsp;/&nbsp; Student Information
@endsection
@section('page_actions')
    @if(Qs::userIsTeamSAWrite())
        <a href="{{ route('students.create') }}" class="btn btn-primary btn-sm"><i class="icon-plus3 mr-1"></i> Add Student</a>
        <a href="{{ route('students.import') }}" class="btn btn-success btn-sm ml-1"><i class="icon-upload mr-1"></i> Import CSV</a>
    @endif
@endsection
@section('content')

    {{-- Owner ruling 2026-10-22: Student Information lands here —
         ONE menu link, then pick a class (and optionally a section)
         in the page, instead of the sidebar expanding into one item
         per class. Submitting redirects to the canonical per-class
         list URL, whose filters/print/CSV/bulk-delete are unchanged.
         Teachers only ever see their assigned classes (TeacherScope),
         exactly as the old per-class submenu scoped them. --}}
    <div class="card pat-form-card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">Choose a Class</h6>
            {!! Qs::getPanelOptions() !!}
        </div>

        <div class="card-body">
            @if($classes->isEmpty())
                <p class="mb-0">No classes are assigned to you yet, so there is no student list to open.</p>
            @else
                <p class="text-muted">Select a class to see its students. You can narrow the list to one section as well — the section can also be changed later on the list itself.</p>

                <form method="get" action="{{ route('students.list') }}">
                    <div class="row">
                        <div class="col-md-5">
                            <div class="form-group">
                                <label for="picker_class">Class: <span class="text-danger">*</span></label>
                                <select name="class_id" id="picker_class" class="form-control" required>
                                    <option value="">Choose a class..</option>
                                    @foreach($classes as $c)
                                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="picker_section">Section:</label>
                                <select name="section_id" id="picker_section" class="form-control">
                                    <option value="">All Sections</option>
                                    @foreach($sections as $s)
                                        <option value="{{ $s->id }}" data-class-id="{{ $s->my_class_id }}">{{ $s->my_class->name.' '.$s->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="col-md-3 d-flex align-items-end">
                            <div class="form-group">
                                <button type="submit" class="btn btn-primary"><i class="icon-list mr-1"></i> View Students</button>
                            </div>
                        </div>
                    </div>
                </form>
            @endif
        </div>
    </div>

@endsection
@section('scripts')
    <script>
        // Keep the section list honest: only the chosen class's
        // sections stay selectable (the server re-checks the pairing
        // on submit, so a stale section can never leak through).
        (function () {
            var classSel = document.getElementById('picker_class');
            var sectionSel = document.getElementById('picker_section');
            if (!classSel || !sectionSel) { return; }
            var options = Array.prototype.slice.call(sectionSel.options);
            function syncSections() {
                var cid = classSel.value;
                options.forEach(function (opt) {
                    if (!opt.value) { return; }
                    var match = opt.getAttribute('data-class-id') === cid;
                    opt.disabled = !match;
                    if (!match && opt.selected) { sectionSel.value = ''; }
                });
            }
            classSel.addEventListener('change', syncSections);
            syncSections();
        })();
    </script>
@endsection
