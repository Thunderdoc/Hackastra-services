{{-- Loop 75 (owner addendum 2026-10-22): the shared circulars
     browser — summary chips from the viewer's visible set, a
     server-side filter bar, and priority-accented cards. Used by
     the management list and the audience feed alike; $is_manager
     and $can_write carry the role differences (drafts, kebab
     actions and the Create button exist only where they did
     before). Audience scoping happens in the controller query —
     this partial only renders what it is given. --}}
@php
    $statusPills = ['Live' => ['Active', 'l75-pill-active'], 'Scheduled' => ['Scheduled', 'l75-pill-scheduled'], 'Expired' => ['Expired', 'l75-pill-muted'], 'Draft' => ['Draft', 'l75-pill-muted']];
    $anyFilter = collect($filters)->filter(function ($v) { return $v !== '' && $v !== null; })->isNotEmpty();
@endphp

@component('partials.patterns.list_page')
    @slot('subtitle')
        @if($is_manager)
            Every circular from the school office — drafts, scheduled and live.
        @else
            Notices from the school office, addressed to you.
        @endif
    @endslot

    @if($can_write)
        @slot('action')
            <a href="{{ route('circulars.create') }}" class="btn btn-primary"><i class="icon-plus2 mr-1"></i> Create Circular</a>
        @endslot
    @endif

    @slot('stats')
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px">
            <div class="dash-stat"><span class="dash-stat-ic bg-blue"><i class="icon-megaphone"></i></span><span><span class="dash-stat-label">Total Circulars</span><span class="dash-stat-value">{{ $counts['total'] }}</span></span></div>
            <div class="dash-stat"><span class="dash-stat-ic bg-green"><i class="icon-checkmark2"></i></span><span><span class="dash-stat-label">Active</span><span class="dash-stat-value">{{ $counts['active'] }}</span></span></div>
            <div class="dash-stat"><span class="dash-stat-ic bg-violet"><i class="icon-calendar3"></i></span><span><span class="dash-stat-label">Upcoming</span><span class="dash-stat-value">{{ $counts['upcoming'] }}</span></span></div>
            <div class="dash-stat"><span class="dash-stat-ic bg-amber"><i class="icon-history"></i></span><span><span class="dash-stat-label">Expired</span><span class="dash-stat-value">{{ $counts['expired'] }}</span></span></div>
        </div>
    @endslot

    @slot('filters')
        <form method="get" action="{{ route('circulars.index') }}" class="pat-filter-form">
            <div class="pat-filter-field" style="flex:1 1 210px">
                <label for="f-q">Search</label>
                <input type="text" id="f-q" name="q" value="{{ $filters['q'] }}" class="form-control" placeholder="Title, message or author">
            </div>
            <div class="pat-filter-field">
                <label for="f-audience">Audience</label>
                <select id="f-audience" name="audience" class="form-control">
                    <option value="">All Audiences</option>
                    @foreach($audiences as $value => $label)
                        <option value="{{ $value }}" {{ $filters['audience'] === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="pat-filter-field">
                <label for="f-priority">Priority</label>
                <select id="f-priority" name="priority" class="form-control">
                    <option value="">All Priorities</option>
                    @foreach($priorities as $value => $label)
                        <option value="{{ $value }}" {{ $filters['priority'] === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="pat-filter-field">
                <label for="f-status">Status</label>
                <select id="f-status" name="status" class="form-control">
                    <option value="">All Statuses</option>
                    <option value="active" {{ $filters['status'] === 'active' ? 'selected' : '' }}>Active</option>
                    <option value="upcoming" {{ $filters['status'] === 'upcoming' ? 'selected' : '' }}>Upcoming</option>
                    <option value="expired" {{ $filters['status'] === 'expired' ? 'selected' : '' }}>Expired</option>
                    @if($is_manager)
                        <option value="draft" {{ $filters['status'] === 'draft' ? 'selected' : '' }}>Draft</option>
                    @endif
                </select>
            </div>
            <div class="pat-filter-field">
                <label for="f-from">From</label>
                <input type="date" id="f-from" name="from" value="{{ $filters['from'] }}" class="form-control">
            </div>
            <div class="pat-filter-field">
                <label for="f-to">To</label>
                <input type="date" id="f-to" name="to" value="{{ $filters['to'] }}" class="form-control">
            </div>
            <div class="pat-filter-action">
                <button type="submit" class="btn btn-primary">Filter</button>
                <a href="{{ route('circulars.index') }}" class="btn btn-light">Reset</a>
            </div>
        </form>
    @endslot

    <div class="l75-circ-list">
        @forelse($circulars as $circular)
            @php
                $status = $circular->statusLabel();
                [$pillLabel, $pillClass] = $statusPills[$status] ?? [$status, 'l75-pill-muted'];
                // Readers may open Live circulars (the show gate is
                // unchanged); managers open everything they manage.
                $openable = $is_manager || $status === 'Live';
            @endphp
            <article class="circ-item circ-pri-{{ $circular->priority }} l75-circ-card">
                <span class="l75-circ-tile"><i class="icon-megaphone"></i></span>
                <div class="l75-circ-main">
                    <div class="circ-item-top">
                        <h5 class="circ-title">
                            @if($openable)<a href="{{ route('circulars.show', $circular->id) }}">{{ $circular->title }}</a>@else{{ $circular->title }}@endif
                        </h5>
                        <span class="l75-pill {{ $pillClass }}">{{ $pillLabel }}</span>
                        <span class="circ-pri-chip circ-pri-chip-{{ $circular->priority }}">@if($circular->priority === 'urgent')<i class="icon-alarm"></i> @endif{{ $priorities[$circular->priority] ?? $circular->priority }}</span>
                        @if($can_write)
                            <div class="dropdown l75-kebab">
                                <button type="button" class="l75-kebab-btn" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" aria-label="Circular actions"><i class="icon-more2"></i></button>
                                <div class="dropdown-menu dropdown-menu-right">
                                    <a href="{{ route('circulars.edit', $circular->id) }}" class="dropdown-item"><i class="icon-pencil7"></i> Edit</a>
                                    <form method="post" action="{{ route('circulars.toggle_publish', $circular->id) }}">
                                        @csrf @method('patch')
                                        <button type="submit" class="dropdown-item"><i class="icon-eye"></i> {{ $circular->is_published ? 'Unpublish' : 'Publish' }}</button>
                                    </form>
                                    <form method="post" action="{{ route('circulars.destroy', $circular->id) }}">
                                        @csrf @method('delete')
                                        <button type="submit" class="dropdown-item text-danger" data-confirm="Delete this circular?" data-confirm-title="Delete Circular" data-confirm-action="Delete" data-confirm-danger><i class="icon-trash"></i> Delete</button>
                                    </form>
                                </div>
                            </div>
                        @endif
                    </div>
                    <div class="circ-meta">
                        <span class="circ-meta-item"><i class="icon-user"></i>{{ $circular->publisher->name ?? 'School Office' }}</span>
                        <span class="circ-meta-item"><i class="icon-calendar3"></i>{{ $circular->created_at->format('d M Y') }}</span>
                        <span class="circ-aud"><i class="icon-users"></i>For {{ $audiences[$circular->audience] ?? $circular->audience }}</span>
                        @if($status === 'Expired' && $circular->expires_at)
                            <span class="circ-meta-item"><i class="icon-history"></i>Expired on {{ $circular->expires_at->format('d M Y') }}</span>
                        @elseif($status === 'Scheduled' && $circular->starts_at)
                            <span class="circ-meta-item"><i class="icon-history"></i>Starts {{ $circular->starts_at->format('d M Y') }}</span>
                        @elseif($circular->expires_at)
                            <span class="circ-meta-item circ-valid"><i class="icon-history"></i>Valid until {{ $circular->expires_at->format('d M Y') }}</span>
                        @endif
                    </div>
                    <p class="circ-excerpt l75-circ-excerpt">{{ \Illuminate\Support\Str::limit(strip_tags($circular->body), 300) }}</p>
                    @if($openable)
                        <a href="{{ route('circulars.show', $circular->id) }}" class="circ-read">Read Full Circular <i class="icon-arrow-right14"></i></a>
                    @endif
                </div>
            </article>
        @empty
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-megaphone',
                'message' => $anyFilter
                    ? 'No circulars match these filters. Try widening the search, or reset the filters.'
                    : ($is_manager
                        ? 'No circulars yet. Create the first one — it will appear in the Circulars feed of its audience.'
                        : 'No circulars for you right now. New notices from the school office will appear here.'),
            ])
        @endforelse
    </div>
    @if($circulars->hasPages())
        <div class="circ-pager">{{ $circulars->links() }}</div>
    @endif
@endcomponent
