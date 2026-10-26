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
        // Section cascade (batch fix 2026-10-25): the old version
        // toggled option.disabled behind a native change listener —
        // but the site-wide Select2 notifies via jQuery trigger,
        // which never reaches vanilla listeners, so after picking a
        // class every section stayed disabled and clicks did nothing
        // (proven by CDP repro). This rebuilds the section options
        // from a cached clone on every class change (the wave-8
        // exam-filter precedent) and notifies Select2, so the
        // dropdown shows ONLY the chosen class's sections — no dead
        // options at all. With no class chosen, only "All Sections".
        (function () {
            var $classSel = jQuery('#picker_class');
            var $sectionSel = jQuery('#picker_section');
            if (!$classSel.length || !$sectionSel.length) { return; }
            var all = $sectionSel.find('option').map(function () {
                var $o = jQuery(this);
                return { value: $o.val(), text: $o.text(), cid: $o.attr('data-class-id') };
            }).get();
            function syncSections() {
                var cid = $classSel.val();
                $sectionSel.empty();
                all.forEach(function (o) {
                    if (!o.value || o.cid === cid) {
                        $sectionSel.append(jQuery('<option>', { value: o.value, text: o.text, 'data-class-id': o.cid || '' }));
                    }
                });
                $sectionSel.val('');
                $sectionSel.trigger('change.select2');
            }
            $classSel.on('change', syncSections);
            syncSections();
        })();
    </script>
@endsection
