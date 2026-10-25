@extends('layouts.master')
@section('page_title', 'Admit Cards')
@section('content')

    <div class="card">
        <div class="card-header header-elements-inline">
            {{-- Workstream CO: card title removed — it repeated the
                 page title verbatim (the common header owns titles). --}}
            {!! Qs::getPanelOptions() !!}
        </div>

        <div class="card-body">
            {{-- Workstream BW: selector moved onto the shared
                 pat-filter-form bar (was col-md-* form-groups).
                 Same GET fields, ids and required flags. --}}
            <form method="get" action="{{ route('admit_cards.index') }}" class="pat-filter-form">
                <div class="pat-filter-field">
                    <label for="exam_id">Exam <span class="text-danger">*</span></label>
                    <select id="exam_id" name="exam_id" class="form-control" required>
                        <option value="">Select Exam</option>
                        @foreach($exams as $ex)
                            <option value="{{ $ex->id }}" {{ (int) $exam_id === $ex->id ? 'selected' : '' }}>
                                {{ $ex->name }} (Term {{ $ex->term }}, {{ $ex->year }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-filter-field">
                    <label for="class_id">Class <span class="text-danger">*</span></label>
                    <select id="class_id" name="class_id" class="form-control" onchange="getClassSections(this.value)" required>
                        <option value="">Select Class</option>
                        @foreach($my_classes as $mc)
                            <option value="{{ $mc->id }}" {{ (int) $class_id === $mc->id ? 'selected' : '' }}>{{ $mc->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-filter-field">
                    <label for="section_id">Section</label>
                    <select id="section_id" name="section_id" class="form-control">
                        <option value="">All Sections</option>
                        @foreach($sections as $sec)
                            <option value="{{ $sec->id }}" {{ (int) $section_id === $sec->id ? 'selected' : '' }}>
                                {{ $class_id ? $sec->name : (($sec->my_class->name ?? '') . ' — ' . $sec->name) }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-filter-action">
                    <button type="submit" class="btn btn-primary">Show Students</button>
                </div>
            </form>

            @if($selected)
                <hr>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0">{{ $exam->name }} — {{ $students->count() }} student(s)</h6>
                    @if($students->isNotEmpty())
                        <a target="_blank" class="btn btn-primary"
                           href="{{ route('admit_cards.print', [$exam_id, $class_id]) }}{{ $section_id ? '?section_id='.$section_id : '' }}">
                            <i class="icon-printer mr-2"></i> Print All Cards
                        </a>
                    @endif
                </div>

                @if($students->isEmpty())
                    <div class="lav-empty">
                        <p>No active students found in this class/section.</p>
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                            <tr>
                                <th>S/N</th>
                                <th>Photo</th>
                                <th>Name</th>
                                <th>Admission No</th>
                                <th>Class</th>
                                <th>Section</th>
                                <th class="text-center">Action</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($students as $sr)
                                <tr>
                                    <td>{{ $loop->iteration }}</td>
                                    <td>
                                        @if(Qs::hasRealPhoto($sr->user->photo))<img class="rounded-circle" style="height: 40px; width: 40px;" src="{{ $sr->user->photo }}" alt="photo">@else<span class="hk-ini" style="width:40px;height:40px;font-size:.8rem" aria-hidden="true">{{ Qs::initials($sr->user->name) }}</span>@endif
                                    </td>
                                    <td>{{ $sr->user->name }}</td>
                                    <td>{{ $sr->adm_no }}</td>
                                    <td>{{ $sr->my_class->name }}</td>
                                    <td>{{ $sr->section->name ?? '—' }}</td>
                                    <td class="text-center">
                                        <a target="_blank" class="btn btn-sm btn-outline-primary"
                                           href="{{ route('admit_cards.print', [$exam_id, $class_id]) }}?student={{ $sr->user_id }}">
                                            <i class="icon-printer mr-1"></i> Print Card
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @else
                <div class="lav-empty">
                    <p>Select an exam and a class to see the students whose admit cards you can print.</p>
                </div>
            @endif
        </div>
    </div>

@endsection

@section('scripts')
    <script>
        /* Section cascade: changing the class immediately re-offers
           only that class's sections (same get_class_sections endpoint
           the rest of the app uses), keeping this page's "All Sections"
           choice. The server scopes the list again on submit, so a
           stale cross-class section can never survive either way. */
        (function () {
            var classSel = document.getElementById('class_id');
            var sectionSel = document.getElementById('section_id');
            if (!classSel || !sectionSel) { return; }
            classSel.addEventListener('change', function () {
                var url = '{{ route('get_class_sections', [':id']) }}'.replace(':id', classSel.value || '0');
                sectionSel.innerHTML = '<option value="">All Sections</option>';
                if (!classSel.value) { return; }
                fetch(url, {headers: {'X-Requested-With': 'XMLHttpRequest'}})
                    .then(function (r) { return r.json(); })
                    .then(function (resp) {
                        resp.forEach(function (s) {
                            var opt = document.createElement('option');
                            opt.value = s.id;
                            opt.textContent = s.name;
                            sectionSel.appendChild(opt);
                        });
                    })
                    .catch(function () { /* server-side scoping on submit still applies */ });
            });
        })();
    </script>
@endsection
