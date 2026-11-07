@extends('layouts.master')
@section('page_title', 'Exam Pattern')
@section('content')
    {{-- Exam Pattern (Round 3 R1): the school's assessment periods
         for the current session, on the shared list/form patterns
         like exams/index. Periods are managed for the current
         session only; other sessions are reached by switching the
         session in Settings, exactly like exams. --}}
    @component('partials.patterns.list_page')
        @slot('title')
            <i class="icon-books mr-2"></i> Exam Pattern — {{ $year }}
        @endslot
        @slot('subtitle')
            The periods exams belong to in session {{ $year }}: terms are reported on report cards, unit tests feed their term's Internal marks, standalone periods stand on their own.
        @endslot
        @slot('action')
            <a href="{{ route('exams.index') }}" class="btn btn-light"><i class="icon-arrow-left5 mr-1"></i> Back to Exams</a>
            @if(Qs::userIsTeamSAWrite())
            <a href="#period-form" class="btn btn-primary"><i class="icon-plus2 mr-2"></i> Add Period</a>
            @endif
        @endslot

        @if($unlinked_count > 0)
            <div class="alert alert-warning border-0">
                <strong>{{ $unlinked_count }} exam(s)</strong> in this session are not linked to a period yet (for example, exams added by a data import).
                @if(Qs::userIsTeamSAWrite())
                <form method="post" action="{{ route('exams.periods.link_unlinked') }}" style="display:inline-block; margin-left:8px;">
                    @csrf
                    <button type="submit" class="btn btn-warning btn-sm">Link unlinked exams</button>
                </form>
                @endif
            </div>
        @endif

        @if($periods->count())
            <table class="table l108-stack">
                <thead>
                <tr>
                    <th>S/N</th>
                    <th>Name</th>
                    <th>Kind</th>
                    <th>Feeds into</th>
                    <th>Counts toward annual</th>
                    <th>Order</th>
                    <th>Exams</th>
                    <th>Active</th>
                    @if(Qs::userIsTeamSAWrite())
                    <th>Tools</th>
                    @endif
                </tr>
                </thead>
                <tbody>
                @foreach($periods as $p)
                    <tr>
                        <td data-label="S/N">{{ $loop->iteration }}</td>
                        <td data-label="Name">{{ $p->name }}</td>
                        <td data-label="Kind">
                            @if($p->kind === 'term')
                                <span class="badge badge-primary">Term</span>
                            @elseif($p->kind === 'unit')
                                <span class="badge badge-info">Unit Test</span>
                            @else
                                <span class="badge badge-secondary">Standalone</span>
                            @endif
                        </td>
                        <td data-label="Feeds into">{{ $p->kind === 'unit' ? ($p->feedsPeriod->name ?? '—') : '—' }}</td>
                        <td data-label="Counts toward annual">{{ $p->counts_toward_annual ? 'Yes' : 'No' }}</td>
                        <td data-label="Order">
                            {{ $p->sort_order }}
                            @if(Qs::userIsTeamSAWrite())
                            <form method="post" action="{{ route('exams.periods.move', $p->id) }}" style="display:inline-block;">
                                @csrf
                                <input type="hidden" name="direction" value="up">
                                <button type="submit" class="btn btn-light btn-sm" title="Move up" @if($loop->first) disabled @endif>&#9650;</button>
                            </form>
                            <form method="post" action="{{ route('exams.periods.move', $p->id) }}" style="display:inline-block;">
                                @csrf
                                <input type="hidden" name="direction" value="down">
                                <button type="submit" class="btn btn-light btn-sm" title="Move down" @if($loop->last) disabled @endif>&#9660;</button>
                            </form>
                            @endif
                        </td>
                        <td data-label="Exams">{{ $p->exams_count }}</td>
                        <td data-label="Active">
                            @if($p->is_active)
                                <span class="badge badge-success">Active</span>
                            @else
                                <span class="badge badge-secondary">Inactive</span>
                            @endif
                            @if(Qs::userIsTeamSAWrite())
                            <form method="post" action="{{ route('exams.periods.deactivate', $p->id) }}" style="display:inline-block; margin-left:4px;">
                                @csrf
                                <button type="submit" class="btn btn-light btn-sm">{{ $p->is_active ? 'Deactivate' : 'Activate' }}</button>
                            </form>
                            @endif
                        </td>
                        @if(Qs::userIsTeamSAWrite())
                        <td data-label="Tools">
                            <div class="l108-tools">
                                <a href="{{ route('exams.periods.index', ['edit' => $p->id]) }}#period-form" class="btn btn-primary btn-sm"><i class="icon-pencil mr-1"></i> Edit</a>
                                @if($p->exams_count === 0)
                                <button type="button" id="{{ $p->id }}" data-item-name="{{ $p->name }}" onclick="confirmDelete(this.id, this.dataset.itemName)" class="btn btn-danger btn-sm"><i class="icon-trash mr-1"></i> Delete</button>
                                <form method="post" id="item-delete-{{ $p->id }}" action="{{ route('exams.periods.destroy', $p->id) }}" class="hidden">@csrf @method('delete')</form>
                                @endif
                            </div>
                        </td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table>
        @else
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-books',
                'message' => 'No periods yet for ' . $year . '. Apply a preset below, or add periods one by one.',
            ])
        @endif
    @endcomponent

    @if(Qs::userIsTeamSAWrite() && $periods->isEmpty())
    <div class="card" style="margin-top:14px;">
        <div class="card-header">
            <h6 class="card-title"><i class="icon-magic-wand mr-2"></i>Apply a preset</h6>
        </div>
        <div class="card-body">
            <form method="post" action="{{ route('exams.periods.apply_preset') }}">
                @csrf
                <div class="row">
                    <div class="col-md-3">
                        <label class="form-check">
                            <input class="form-check-input" type="radio" name="preset" value="terms3" checked>
                            <span class="form-check-label"><strong>Term 1 / 2 / 3</strong><br><span class="text-muted">Three terms — the classic pattern.</span></span>
                        </label>
                    </div>
                    <div class="col-md-3">
                        <label class="form-check">
                            <input class="form-check-input" type="radio" name="preset" value="unit_terms">
                            <span class="form-check-label"><strong>Unit Tests + Terms</strong><br><span class="text-muted">Unit Test 1 feeds Term 1, Unit Test 2 feeds Term 2, Unit Test 3 feeds Term 3.</span></span>
                        </label>
                    </div>
                    <div class="col-md-3">
                        <label class="form-check">
                            <input class="form-check-input" type="radio" name="preset" value="two_terms">
                            <span class="form-check-label"><strong>Two Terms</strong><br><span class="text-muted">Term 1 and Term 2 only.</span></span>
                        </label>
                    </div>
                    <div class="col-md-3">
                        <label class="form-check">
                            <input class="form-check-input" type="radio" name="preset" value="blank">
                            <span class="form-check-label"><strong>Blank</strong><br><span class="text-muted">Start empty and add periods one by one.</span></span>
                        </label>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary mt-2">Apply preset</button>
                <span class="text-muted ml-2">A preset can be applied only while the session has no periods — it never overwrites an existing pattern.</span>
            </form>
        </div>
    </div>
    @endif

    @if(Qs::userIsTeamSAWrite() && $periods->isNotEmpty())
    <div class="card" style="margin-top:14px;">
        <div class="card-header">
            <h6 class="card-title"><i class="icon-copy3 mr-2"></i>Copy pattern to another session</h6>
        </div>
        <div class="card-body">
            <form method="post" action="{{ route('exams.periods.copy_to_session') }}" class="pat-filter-form">
                @csrf
                <div class="pat-filter-field">
                    <label for="target_year">Target session</label>
                    <input id="target_year" name="target_year" type="text" class="form-control" value="{{ $next_year }}" required maxlength="40">
                </div>
                <div class="pat-filter-action">
                    <button type="submit" class="btn btn-light">Copy pattern <i class="icon-copy3 ml-1"></i></button>
                </div>
                <div class="pat-filter-action">
                    <span class="text-muted">Clones the active periods (unit feeds are re-pointed at the copies). Refused if the target session already has periods.</span>
                </div>
            </form>
        </div>
    </div>
    @endif

    @if(Qs::userIsTeamSAWrite())
    <div id="period-form">
        @component('partials.patterns.form_page', [
            'form_action' => $editing ? route('exams.periods.update', $editing->id) : route('exams.periods.store'),
            'form_method' => $editing ? 'PUT' : 'POST',
            'submit_label' => $editing ? 'Update period' : 'Add period',
        ])
            @slot('title')
                <i class="icon-plus2 mr-2"></i> {{ $editing ? 'Edit Period — ' . $editing->name : 'Add Period' }}
            @endslot

            @php $locked = $editing && $editing->exams_count > 0; @endphp
            @if($locked)
                <div class="alert alert-info border-0">
                    This period already has exams, so its <strong>kind</strong>, <strong>feeds-into</strong> and <strong>legacy term</strong> are locked — renaming and reordering stay free, and neither ever changes marks already entered.
                </div>
            @endif

            <div class="pat-form-section">
                <div class="pat-fields">
                    <div class="pat-field">
                        <label for="p-name">Name <span class="text-danger">*</span></label>
                        <input id="p-name" name="name" value="{{ old('name', $editing ? $editing->name : '') }}" required type="text" maxlength="80" class="form-control" placeholder="e.g. Unit Test 1">
                    </div>
                    <div class="pat-field">
                        <label for="p-kind">Kind</label>
                        <select id="p-kind" name="kind" class="form-control" @if($locked) disabled @endif>
                            @foreach(['term' => 'Term', 'unit' => 'Unit Test', 'standalone' => 'Standalone'] as $kv => $kl)
                                <option value="{{ $kv }}" {{ old('kind', $editing ? $editing->kind : 'term') === $kv ? 'selected' : '' }}>{{ $kl }}</option>
                            @endforeach
                        </select>
                        @if($locked)
                            <input type="hidden" name="kind" value="{{ $editing->kind }}">
                        @endif
                    </div>
                    <div class="pat-field" id="p-feeds-wrap">
                        <label for="p-feeds">Feeds into (unit tests only)</label>
                        <select id="p-feeds" name="feeds_period_id" class="form-control" @if($locked) disabled @endif>
                            <option value="">Select term period</option>
                            @foreach($term_periods as $tp)
                                @if(!$editing || $tp->id !== $editing->id)
                                <option value="{{ $tp->id }}" {{ (string) old('feeds_period_id', $editing ? $editing->feeds_period_id : '') === (string) $tp->id ? 'selected' : '' }}>{{ $tp->name }}</option>
                                @endif
                            @endforeach
                        </select>
                        @if($locked && $editing && $editing->feeds_period_id)
                            <input type="hidden" name="feeds_period_id" value="{{ $editing->feeds_period_id }}">
                        @endif
                    </div>
                    <div class="pat-field" id="p-legacy-wrap">
                        <label for="p-legacy">Legacy term (terms only)</label>
                        <select id="p-legacy" name="legacy_term" class="form-control" @if($locked) disabled @endif>
                            <option value="">None</option>
                            @foreach([1, 2, 3] as $lt)
                                <option value="{{ $lt }}" {{ (string) old('legacy_term', $editing ? $editing->legacy_term : '') === (string) $lt ? 'selected' : '' }}>{{ $lt }}</option>
                            @endforeach
                        </select>
                        @if($locked && $editing && $editing->legacy_term)
                            <input type="hidden" name="legacy_term" value="{{ $editing->legacy_term }}">
                        @endif
                        <small class="text-muted">Terms 1/2/3 of the classic pattern carry their number; a term without one can be created now, and its marks entry opens in a later update.</small>
                    </div>
                    <div class="pat-field">
                        <label for="p-sort">Order</label>
                        <input id="p-sort" name="sort_order" type="number" min="0" max="65535" class="form-control" value="{{ old('sort_order', $editing ? $editing->sort_order : '') }}" placeholder="Auto (next free slot)">
                    </div>
                    <div class="pat-field">
                        <label>Counts toward annual</label>
                        <div class="form-check">
                            <input type="hidden" name="counts_toward_annual" value="0">
                            <input class="form-check-input" id="p-counts" type="checkbox" name="counts_toward_annual" value="1" {{ old('counts_toward_annual', $editing ? $editing->counts_toward_annual : true) ? 'checked' : '' }}>
                            <label class="form-check-label" for="p-counts">Include this period in the annual report card</label>
                        </div>
                        <small class="text-muted">Unit tests never count (their marks already reach the annual card inside their term's Internal marks). Terms always count.</small>
                    </div>
                    <div class="pat-field">
                        <label>Active</label>
                        <div class="form-check">
                            <input type="hidden" name="is_active" value="0">
                            <input class="form-check-input" id="p-active" type="checkbox" name="is_active" value="1" {{ old('is_active', $editing ? $editing->is_active : true) ? 'checked' : '' }}>
                            <label class="form-check-label" for="p-active">Active (appears in the exam picker)</label>
                        </div>
                    </div>
                </div>
            </div>
            <script>
                (function () {
                    var kind = document.getElementById('p-kind');
                    var feedsWrap = document.getElementById('p-feeds-wrap');
                    var legacyWrap = document.getElementById('p-legacy-wrap');
                    var counts = document.getElementById('p-counts');
                    if (!kind) { return; }
                    function sync() {
                        var isUnit = kind.value === 'unit';
                        var isTerm = kind.value === 'term';
                        feedsWrap.style.display = isUnit ? '' : 'none';
                        legacyWrap.style.display = isTerm ? '' : 'none';
                        if (counts) {
                            counts.disabled = isUnit || isTerm;
                            if (isUnit) { counts.checked = false; }
                            if (isTerm) { counts.checked = true; }
                        }
                    }
                    kind.addEventListener('change', sync);
                    sync();
                })();
            </script>
        @endcomponent
    </div>
    @endif

@endsection
