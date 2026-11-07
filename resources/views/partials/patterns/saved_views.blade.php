{{--
    PATTERN · saved_views (Round 5 Slice 3 — P12)
    ---------------------------------------------------------------
    Compact saved-filter controls for a list's filter bar.
    Usage: include with a list key, e.g. svList set to users.
    Renders only the signed-in user's OWN views for that list:
    a picker that jumps to the apply URL, a delete form for the
    picked view, and a Save-current form that posts the current
    request's whitelisted query params (plus the students list's
    class path parameter) as hidden fields. Server-side, the
    SavedView model whitelist decides what may actually be
    stored — this partial never widens it.
--}}
@php
    $svDef = \App\Models\SavedView::LISTS[$svList] ?? null;
    $svViews = collect();
    $svCurrent = [];
    if ($svDef && Auth::check()) {
        $svViews = \App\Models\SavedView::where('user_id', Auth::id())
            ->where('list_key', $svList)
            ->orderBy('name')
            ->get();
        foreach ($svDef['params'] as $svParam) {
            $svValue = request()->query($svParam);
            if (($svValue === null || $svValue === '') && $svParam === 'class_id') {
                $svValue = request()->route('class_id');
            }
            if ($svValue !== null && $svValue !== '' && is_scalar($svValue)) {
                $svCurrent[$svParam] = $svValue;
            }
        }
    }
@endphp
@if($svDef && Auth::check())
    <div class="sv-bar d-flex flex-wrap align-items-center print-hide" style="gap:6px">
        <span class="text-muted small"><i class="icon-bookmark mr-1"></i>Saved views</span>
        @if($svViews->isNotEmpty())
            <select id="sv-apply-{{ $svList }}" class="form-control form-control-sm" style="width:auto" aria-label="Apply a saved view" onchange="if(this.value){window.location=this.value;}">
                <option value="">Apply a view&hellip;</option>
                @foreach($svViews as $svView)
                    <option value="{{ route('saved_views.apply', $svView->id) }}">{{ $svView->name }}</option>
                @endforeach
            </select>
            <form method="post" id="sv-del-form-{{ $svList }}" action="{{ route('saved_views.destroy', $svViews->first()->id) }}" class="m-0 d-flex align-items-center" style="gap:6px" data-confirm="Delete this saved view? The list and its filters are not affected." data-confirm-title="Delete saved view" data-confirm-action="Delete">
                @csrf
                @method('delete')
                <select id="sv-del-{{ $svList }}" class="form-control form-control-sm" style="width:auto" aria-label="Saved view to delete" onchange="this.form.action=this.options[this.selectedIndex].dataset.destroyUrl;">
                    @foreach($svViews as $svView)
                        <option value="{{ $svView->id }}" data-destroy-url="{{ route('saved_views.destroy', $svView->id) }}">{{ $svView->name }}</option>
                    @endforeach
                </select>
                <button type="submit" class="btn btn-light btn-sm" title="Delete the picked saved view"><i class="icon-trash"></i></button>
            </form>
        @endif
        <form method="post" action="{{ route('saved_views.store') }}" class="m-0 d-flex align-items-center" style="gap:6px">
            @csrf
            <input type="hidden" name="list_key" value="{{ $svList }}">
            @foreach($svCurrent as $svParam => $svValue)
                <input type="hidden" name="{{ $svParam }}" value="{{ $svValue }}">
            @endforeach
            <input type="text" name="name" required maxlength="100" class="form-control form-control-sm" style="width:170px" placeholder="Save current filters as&hellip;" aria-label="Name for the current filters">
            <button type="submit" class="btn btn-light btn-sm"><i class="icon-floppy-disk mr-1"></i>Save view</button>
        </form>
    </div>
@endif
