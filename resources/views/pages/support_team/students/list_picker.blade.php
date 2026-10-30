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

    {{-- Student search with full filters (owner order 2026-10-30):
         find a student directly — by name, admission number, email
         or phone — narrowed by class, section, gender, house,
         category, status and admission year. The class picker below
         stays for opening a whole class list. --}}
    <div class="card pat-form-card">
        <div class="card-header header-elements-inline">
            <h6 class="card-title">Search Students</h6>
            {!! Qs::getPanelOptions() !!}
        </div>
        <div class="card-body">
            <form method="get" action="{{ route('students.list') }}">
                <input type="hidden" name="search" value="1">
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="search_q">Name, Admission No, Email or Phone</label>
                            <input type="text" name="q" id="search_q" class="form-control" value="{{ $filters['q'] ?? '' }}" placeholder="e.g. Aarav, HA/…, 98765…">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label for="search_class">Class</label>
                            <select name="class_id" id="search_class" class="form-control">
                                <option value="">All Classes</option>
                                @foreach($classes as $c)
                                    <option value="{{ $c->id }}" {{ (int) ($filters['class_id'] ?? 0) === (int) $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label for="search_section">Section</label>
                            <select name="section_id" id="search_section" class="form-control">
                                <option value="">All Sections</option>
                                @foreach($sections as $s)
                                    <option value="{{ $s->id }}" data-class-id="{{ $s->my_class_id }}" {{ (int) ($filters['section_id'] ?? 0) === (int) $s->id ? 'selected' : '' }}>{{ $s->my_class->name.' '.$s->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label for="search_gender">Gender</label>
                            <select name="gender" id="search_gender" class="form-control">
                                <option value="">All</option>
                                @foreach($genders ?? [] as $g)
                                    <option value="{{ $g }}" {{ ($filters['gender'] ?? '') === $g ? 'selected' : '' }}>{{ $g }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label for="search_status">Status</label>
                            <select name="status" id="search_status" class="form-control">
                                <option value="active" {{ ($filters['status'] ?? 'active') === 'active' ? 'selected' : '' }}>Active</option>
                                <option value="graduated" {{ ($filters['status'] ?? '') === 'graduated' ? 'selected' : '' }}>Graduated / Left</option>
                                <option value="all" {{ ($filters['status'] ?? '') === 'all' ? 'selected' : '' }}>All</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-2">
                        <div class="form-group">
                            <label for="search_house">House</label>
                            <select name="house" id="search_house" class="form-control">
                                <option value="">All Houses</option>
                                @foreach($houses ?? [] as $h)
                                    <option value="{{ $h }}" {{ ($filters['house'] ?? '') === $h ? 'selected' : '' }}>{{ $h }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label for="search_category">Category</label>
                            <select name="category" id="search_category" class="form-control">
                                <option value="">All Categories</option>
                                @foreach($categories ?? [] as $cat)
                                    <option value="{{ $cat }}" {{ ($filters['category'] ?? '') === $cat ? 'selected' : '' }}>{{ $cat }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label for="search_year">Admission Year</label>
                            <select name="year_admitted" id="search_year" class="form-control">
                                <option value="">All Years</option>
                                @foreach($years ?? [] as $y)
                                    <option value="{{ $y }}" {{ ($filters['year_admitted'] ?? '') === (string) $y ? 'selected' : '' }}>{{ $y }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <div class="form-group">
                            <button type="submit" class="btn btn-primary"><i class="icon-search4 mr-1"></i> Search</button>
                            @if(!empty($searched))
                                <a href="{{ route('students.list') }}" class="btn btn-light ml-1">Clear</a>
                            @endif
                        </div>
                    </div>
                </div>
            </form>

            @if(!empty($searched))
                <hr>
                <p class="text-muted mb-2"><strong>{{ $results_total }}</strong> student{{ $results_total === 1 ? '' : 's' }} found
                    @if($results_total > $results->count()) — showing the first {{ $results->count() }}, please narrow the filters @endif
                </p>
                @if($results->isEmpty())
                    <p class="mb-0">No student matches these filters. Try widening the search.</p>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead><tr>
                                <th>S/N</th><th>Student</th><th>Adm No</th><th>Class</th><th>Section</th>
                                <th>Gender</th><th>House</th><th>Category</th><th>Phone</th><th>Admitted</th><th></th>
                            </tr></thead>
                            <tbody>
                                @foreach($results as $i => $sr)
                                    <tr>
                                        <td>{{ $i + 1 }}</td>
                                        <td><a href="{{ route('students.show', Qs::hash($sr->id)) }}">{{ optional($sr->user)->name }}</a></td>
                                        <td>{{ $sr->adm_no }}</td>
                                        <td>{{ optional($sr->my_class)->name }}</td>
                                        <td>{{ optional($sr->section)->name }}</td>
                                        <td>{{ optional($sr->user)->gender ?? '—' }}</td>
                                        <td>{{ $sr->house ?? '—' }}</td>
                                        <td>{{ $sr->category ?? '—' }}</td>
                                        <td>{{ optional($sr->user)->phone ?? '—' }}</td>
                                        <td>{{ $sr->year_admitted ?? '—' }}</td>
                                        <td><a href="{{ route('students.show', Qs::hash($sr->id)) }}" class="btn btn-light btn-sm"><i class="icon-eye mr-1"></i> View</a></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @endif
        </div>
    </div>

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

        // Same cascade for the Search Students form (2026-10-30):
        // the section list follows the search class; a submitted
        // section is restored on load when it fits the class.
        (function () {
            var $classSel = jQuery('#search_class');
            var $sectionSel = jQuery('#search_section');
            if (!$classSel.length || !$sectionSel.length) { return; }
            var all = $sectionSel.find('option').map(function () {
                var $o = jQuery(this);
                return { value: $o.val(), text: $o.text(), cid: $o.attr('data-class-id') };
            }).get();
            var submitted = $sectionSel.val();
            function syncSections(keep) {
                var cid = $classSel.val();
                $sectionSel.empty();
                all.forEach(function (o) {
                    if (!o.value || !cid || o.cid === cid) {
                        $sectionSel.append(jQuery('<option>', { value: o.value, text: o.text, 'data-class-id': o.cid || '' }));
                    }
                });
                $sectionSel.val(keep ? submitted : '');
                if ($sectionSel.val() === null) { $sectionSel.val(''); }
                $sectionSel.trigger('change.select2');
            }
            $classSel.on('change', function () { syncSections(false); });
            syncSections(true);
        })();
    </script>
@endsection
