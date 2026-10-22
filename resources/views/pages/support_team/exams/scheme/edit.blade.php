@extends('layouts.master')
@section('page_title', 'Marking Scheme — ' . $my_class->name)
@section('content')

    <datalist id="scheme-name-list">
        @foreach($preset_names as $pn)
            <option value="{{ $pn }}"></option>
        @endforeach
    </datalist>

    <div class="card">
        <div class="card-header header-elements-inline">
            <span class="text-muted">{{ $ex->name }} · Term {{ $ex->term }} · {{ $ex->year }}</span>
            {!! Qs::getPanelOptions() !!}
        </div>

        <div class="card-body">
            @if($errors->any())
                <div class="alert alert-danger border-0">
                    @foreach($errors->all() as $err)
                        <div><i class="icon-arrow-right5"></i> {{ $err }}</div>
                    @endforeach
                </div>
            @endif

            @if($other_exams->count() > 0)
                {{-- Workstream BW: copy bar moved onto the shared
                     pat-filter-form (was form-inline). Same POST,
                     field and note. The scheme editor forms below
                     are unchanged. --}}
                <form method="post" action="{{ route('exams.scheme.copy', [$ex->id, $my_class->id]) }}" class="pat-filter-form mb-3">
                    @csrf
                    <div class="pat-filter-field">
                        <label for="source_exam_id">Copy schemes for this class from:</label>
                        <select id="source_exam_id" name="source_exam_id" class="form-control" required>
                            <option value="">Select Exam</option>
                            @foreach($other_exams as $oe)
                                <option value="{{ $oe->id }}">{{ $oe->name }} (Term {{ $oe->term }}, {{ $oe->year }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="pat-filter-action">
                        <button type="submit" class="btn btn-light">Copy <i class="icon-copy3 ml-1"></i></button>
                    </div>
                    <div class="pat-filter-action">
                        <span class="text-muted">Subjects that already have a scheme or marks in this exam are skipped.</span>
                    </div>
                </form>
                <hr>
            @endif

            @foreach($subjects as $sub)
              <section class="l76-scheme-block">
                <h6 class="font-weight-bold mt-3">
                    {{ $sub->name }}
                    @if($sub->scheme_configured)
                        <span class="badge badge-primary ml-2">Custom scheme — {{ $sub->scheme_components->count() }} components</span>
                    @else
                        <span class="badge badge-secondary ml-2">Legacy scheme (CA 20 + CA 20 + Exam 60)</span>
                    @endif
                </h6>

                @if($sub->scheme_legacy_scored)
                    <div class="alert alert-warning border-0">
                        Marks have already been entered for {{ $sub->name }} in this exam under the legacy scheme,
                        so a custom scheme can no longer be set for this exam. Configure the scheme on the exam
                        <em>before</em> marks entry begins.
                    </div>
                @else
                    <form method="post" action="{{ route('exams.scheme.update', [$ex->id, $my_class->id, $sub->id]) }}" class="scheme-form">
                        @csrf
                        <div class="l76-scheme-preset">
                            <div>
                                <label>Start from a preset</label>
                                <select class="form-control scheme-preset">
                                    <option value="">— Choose a preset to fill the rows —</option>
                                    @foreach($presets as $key => $preset)
                                        <option value="{{ $key }}">{{ $preset['label'] }}</option>
                                    @endforeach
                                    <option value="custom">Custom (start blank)</option>
                                </select>
                            </div>
                            <div class="l76-scheme-sumline">
                                <span class="scheme-sum badge {{ $sub->scheme_configured ? 'badge-success' : 'badge-secondary' }}">
                                    {{ $sub->scheme_configured ? 'Total: 100 / 100' : 'Legacy scheme in use' }}
                                </span>
                                <span class="text-muted">Component max marks must add up to <strong>exactly 100</strong>.</span>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-sm scheme-table l76-scheme-table">
                                <thead>
                                <tr>
                                    <th style="width:40%">Component Name</th>
                                    <th style="width:20%">Short Code</th>
                                    <th style="width:20%">Max Marks</th>
                                    <th style="width:20%"></th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($sub->scheme_components as $i => $comp)
                                    @php $scored = in_array($comp->id, $sub->scheme_scored); @endphp
                                    <tr class="comp-row">
                                        <td>
                                            <input type="hidden" name="components[{{ $i }}][id]" value="{{ $comp->id }}">
                                            <input type="text" name="components[{{ $i }}][name]" value="{{ $comp->name }}" class="form-control comp-name" list="scheme-name-list" maxlength="60" required>
                                        </td>
                                        <td><input type="text" name="components[{{ $i }}][short_code]" value="{{ $comp->short_code }}" class="form-control comp-code" maxlength="10" required></td>
                                        <td>
                                            <input type="number" name="components[{{ $i }}][max_marks]" value="{{ $comp->max_marks }}" class="form-control comp-max" min="1" max="100" required {{ $scored ? 'readonly' : '' }}>
                                            @if($scored)<small class="text-muted">Has marks — max locked</small>@endif
                                        </td>
                                        <td>
                                            <button type="button" class="btn btn-light btn-sm comp-remove" {{ $scored ? 'disabled title=Component has marks entered' : '' }}>Remove</button>
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="l76-scheme-actions">
                            <button type="button" class="btn btn-light btn-sm comp-add"><i class="icon-plus2"></i> Add Component</button>
                            <button type="submit" class="btn btn-primary btn-sm ml-2">Save Scheme <i class="icon-paperplane ml-1"></i></button>
                            <button type="submit" name="apply_all" value="1" class="btn btn-info btn-sm ml-2">Save + Apply to All Subjects in {{ $my_class->name }}</button>
                            @if($sub->scheme_configured)
                                <button type="submit" name="clear" value="1" class="btn btn-warning btn-sm ml-2" onclick="return confirm('Switch {{ $sub->name }} back to the legacy scheme (CA 20 + CA 20 + Exam 60)?')">Use Legacy Scheme</button>
                            @endif
                        </div>
                    </form>
                @endif
              </section>
            @endforeach

            <a href="{{ route('exams.scheme', $ex->id) }}" class="btn btn-light"><i class="icon-arrow-left5 mr-1"></i> Back to Classes</a>
        </div>
    </div>

@endsection

@section('scripts')
    <script>
        var SCHEME_PRESETS = @json(collect($presets)->map(function ($p) { return $p['components']; }));

        (function () {
            function refreshSum(form) {
                var sum = 0, rows = 0;
                form.find('.comp-max').each(function () {
                    var v = parseInt($(this).val(), 10);
                    if (!isNaN(v)) { sum += v; rows++; }
                });
                var badge = form.find('.scheme-sum');
                if (rows === 0) {
                    badge.attr('class', 'scheme-sum badge badge-secondary').text('Legacy scheme in use');
                } else if (sum === 100) {
                    badge.attr('class', 'scheme-sum badge badge-success').text('Total: 100 / 100 ✓');
                } else {
                    badge.attr('class', 'scheme-sum badge badge-danger').text('Total: ' + sum + ' / 100 — must equal 100');
                }
            }

            function nextIndex(tbody) {
                var max = -1;
                tbody.find('.comp-row').each(function () {
                    var name = $(this).find('.comp-name').attr('name') || '';
                    var m = name.match(/components\[(\d+)\]/);
                    if (m) { max = Math.max(max, parseInt(m[1], 10)); }
                });
                return max + 1;
            }

            function addRow(form, comp) {
                var tbody = form.find('.scheme-table tbody');
                var i = nextIndex(tbody);
                var row = '<tr class="comp-row">' +
                    '<td><input type="hidden" name="components[' + i + '][id]" value="">' +
                    '<input type="text" name="components[' + i + '][name]" class="form-control comp-name" list="scheme-name-list" maxlength="60" required></td>' +
                    '<td><input type="text" name="components[' + i + '][short_code]" class="form-control comp-code" maxlength="10" required></td>' +
                    '<td><input type="number" name="components[' + i + '][max_marks]" class="form-control comp-max" min="1" max="100" required></td>' +
                    '<td><button type="button" class="btn btn-light btn-sm comp-remove">Remove</button></td></tr>';
                var $row = $(row);
                if (comp) {
                    $row.find('.comp-name').val(comp.name);
                    $row.find('.comp-code').val(comp.short_code);
                    $row.find('.comp-max').val(comp.max_marks);
                }
                tbody.append($row);
                refreshSum(form);
            }

            $('.scheme-form').each(function () {
                var form = $(this);
                refreshSum(form);

                form.on('input', '.comp-max', function () { refreshSum(form); });
                form.on('click', '.comp-remove', function () {
                    $(this).closest('.comp-row').remove();
                    refreshSum(form);
                });
                form.find('.comp-add').on('click', function () { addRow(form, null); });

                form.find('.scheme-preset').on('change', function () {
                    var key = $(this).val();
                    if (!key) { return; }
                    form.find('.scheme-table tbody').empty();
                    if (key === 'custom') {
                        addRow(form, null);
                    } else if (SCHEME_PRESETS[key]) {
                        $.each(SCHEME_PRESETS[key], function (_, comp) { addRow(form, comp); });
                        if (key === 'legacy') {
                            form.find('.scheme-sum').attr('class', 'scheme-sum badge badge-secondary')
                                .text('Legacy scheme in use — saving switches this subject back to legacy');
                        }
                    }
                    refreshSum(form);
                    if (key === 'legacy') {
                        form.find('.scheme-sum').attr('class', 'scheme-sum badge badge-secondary')
                            .text('Legacy scheme in use — saving switches this subject back to legacy');
                    }
                });
            });
        })();
    </script>
@endsection
