@extends('layouts.master')
@section('page_title', 'My Extra Classes')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; My Extra Classes
@endsection
@section('content')
    {{-- Item 13 (Light Luxe): slim header, three tiles, period cards
         grouped by day. The substitution data and access rules are
         exactly as before — confirmed rows only carry actions, and
         Take Attendance shows for today's confirmed periods only,
         wired to the same route. (There is no per-period Class
         Materials button on this page today; covered materials are
         reached through the Study Materials index, as before.) --}}
    <div class="l10-head">
        <span class="l10-head-tile"><i class="icon-users"></i></span>
        <div class="l10-head-text">
            <h5>My Extra Classes</h5>
            <p>Periods you cover for a colleague on leave — a confirmed period carries its attendance and class access for that period and date only.</p>
        </div>
        <span class="l10-chip"><i class="icon-calendar3"></i>Session {{ Qs::getCurrentSession() }}</span>
    </div>

    @php
        $todayRows = $upcoming->get($today) ?? collect();
        $weekEnd = \Illuminate\Support\Carbon::parse($today)->endOfWeek()->toDateString();
        $weekCount = 0;
        foreach ($upcoming as $dStr => $g) { if ($dStr <= $weekEnd) { $weekCount += $g->count(); } }
        $plannedCount = 0;
        foreach ($planned as $g) { $plannedCount += $g->count(); }

        $allRows = collect();
        foreach ($upcoming as $g) { $allRows = $allRows->concat($g); }
        foreach ($planned as $g) { $allRows = $allRows->concat($g); }
        $allRows = $allRows->concat($past);
        $ttrIds = $allRows->map(function ($r) { return optional(optional($r->time_table)->tt_record)->id; })->filter()->unique();
        $slotGrid = $ttrIds->isEmpty() ? collect() : \App\Models\TimeSlot::whereIn('ttr_id', $ttrIds)->orderBy('timestamp_from')->get()->groupBy('ttr_id');
        $periodOf = function ($row) use ($slotGrid) {
            $tt = $row->time_table;
            if (!$tt || !$tt->time_slot || !$tt->tt_record) { return null; }
            $grid = $slotGrid->get($tt->tt_record->id);
            if (!$grid) { return null; }
            $idx = $grid->search(function ($s) use ($tt) { return (int) $s->id === (int) $tt->time_slot->id; });
            return $idx === false ? null : $idx + 1;
        };
        $initialsOf = function ($name) {
            $parts = preg_split('/\s+/', trim((string) $name));
            return strtoupper(substr($parts[0] ?? '', 0, 1) . (count($parts) > 1 ? substr(end($parts), 0, 1) : ''));
        };
    @endphp

    <div class="l13-tiles">
        <div class="l10-tile">
            <span class="l10-tile-ic l10-tone-blue"><i class="icon-clock3"></i></span>
            <span class="l10-tile-body"><span class="l10-tile-n">{{ $todayRows->count() }}</span><span class="l10-tile-l">Periods Today</span></span>
        </div>
        <div class="l10-tile">
            <span class="l10-tile-ic l10-tone-violet"><i class="icon-calendar3"></i></span>
            <span class="l10-tile-body"><span class="l10-tile-n">{{ $weekCount }}</span><span class="l10-tile-l">This Week</span></span>
        </div>
        <div class="l10-tile">
            <span class="l10-tile-ic l10-tone-green"><i class="icon-checkmark3"></i></span>
            <span class="l10-tile-body"><span class="l10-tile-n">{{ $plannedCount ? $plannedCount . ' planned' : 'All confirmed' }}</span><span class="l10-tile-l">{{ $plannedCount ? 'Goes live when the leave is approved' : 'Nothing pending' }}</span></span>
        </div>
    </div>

    @if($upcoming->isEmpty() && $planned->isEmpty() && $past->isEmpty())
        <div class="l13-empty">
            <span class="l13-empty-ic"><i class="icon-bell2"></i></span>
            <p>No extra classes right now. When a colleague's leave makes you the substitute, the period will appear here and your bell will ring.</p>
        </div>
    @endif

    @foreach($upcoming as $dateStr => $rows)
        <h6 class="l13-day">@if($dateStr === $today) Today — @endif{{ \Illuminate\Support\Carbon::parse($dateStr)->format('l, d M Y') }} <span class="badge badge-success">{{ $rows->count() }} period(s)</span></h6>
        @foreach($rows as $row)
            @php
                $slot = optional(optional($row->time_table)->time_slot);
                $periodNo = $periodOf($row);
                $absentName = optional($row->absent_teacher)->name;
            @endphp
            <div class="l13-card">
                <div class="l13-rail">
                    @if($periodNo) <span class="l13-pn">Period {{ $periodNo }}</span> @endif
                    <span class="l13-pt">{{ $slot->time_from ?: '—' }}</span>
                    <span class="l13-pt2">to {{ $slot->time_to ?: '—' }}</span>
                </div>
                <div class="l13-body">
                    <div class="l13-row1">
                        <span class="l13-class">{{ optional(optional(optional($row->time_table)->tt_record)->my_class)->name ?: '—' }}@if(optional(optional($row->time_table)->tt_record)->section) ({{ $row->time_table->tt_record->section->name }}) @else (All sections) @endif</span>
                        <span class="l12-subj">{{ optional(optional($row->time_table)->subject)->name ?: '—' }}</span>
                        <span class="l13-conf"><i></i>Confirmed</span>
                    </div>
                    <div class="l13-for">
                        <span class="l13-fav">{{ $initialsOf($absentName) }}</span>
                        <span>Covering for <b>{{ $absentName ?: '—' }}</b> — on leave{{ $dateStr === $today ? ' today' : '' }}</span>
                    </div>
                    @if($dateStr === $today)
                        <div class="l13-actions">
                            <a href="{{ route('attendance.periods.index', ['date' => $dateStr]) }}" class="btn btn-sm btn-primary">Take Attendance</a>
                        </div>
                    @endif
                </div>
            </div>
        @endforeach
    @endforeach

    @if($planned->isNotEmpty())
        <h6 class="l13-day">Planned for you <span class="badge badge-warning">Goes live when the leave is approved</span></h6>
        @foreach($planned as $dateStr => $rows)
            @foreach($rows as $row)
                @php
                    $slot = optional(optional($row->time_table)->time_slot);
                    $periodNo = $periodOf($row);
                    $absentName = optional($row->absent_teacher)->name;
                @endphp
                <div class="l13-card">
                    <div class="l13-rail">
                        @if($periodNo) <span class="l13-pn">Period {{ $periodNo }}</span> @endif
                        <span class="l13-pt">{{ $slot->time_from ?: '—' }}</span>
                        <span class="l13-pt2">to {{ $slot->time_to ?: '—' }}</span>
                    </div>
                    <div class="l13-body">
                        <div class="l13-row1">
                            <span class="l13-class">{{ optional(optional(optional($row->time_table)->tt_record)->my_class)->name ?: '—' }}@if(optional(optional($row->time_table)->tt_record)->section) ({{ $row->time_table->tt_record->section->name }}) @else (All sections) @endif</span>
                            <span class="l12-subj">{{ optional(optional($row->time_table)->subject)->name ?: '—' }}</span>
                            <span class="l13-plan"><i></i>Planned</span>
                            <span class="l13-date">{{ \Illuminate\Support\Carbon::parse($dateStr)->format('D, d M Y') }}</span>
                        </div>
                        <div class="l13-for">
                            <span class="l13-fav">{{ $initialsOf($absentName) }}</span>
                            <span>Covering for <b>{{ $absentName ?: '—' }}</b> — on leave</span>
                        </div>
                    </div>
                </div>
            @endforeach
        @endforeach
    @endif

    @if($past->isNotEmpty())
        <h6 class="l13-day">Recent cover</h6>
        @foreach($past as $row)
            @php
                $slot = optional(optional($row->time_table)->time_slot);
                $periodNo = $periodOf($row);
                $absentName = optional($row->absent_teacher)->name;
            @endphp
            <div class="l13-card l13-card-past">
                <div class="l13-rail">
                    @if($periodNo) <span class="l13-pn">Period {{ $periodNo }}</span> @endif
                    <span class="l13-pt">{{ $slot->time_from ?: '—' }}</span>
                    <span class="l13-pt2">to {{ $slot->time_to ?: '—' }}</span>
                </div>
                <div class="l13-body">
                    <div class="l13-row1">
                        <span class="l13-class">{{ optional(optional(optional($row->time_table)->tt_record)->my_class)->name ?: '—' }}@if(optional(optional($row->time_table)->tt_record)->section) ({{ $row->time_table->tt_record->section->name }}) @else (All sections) @endif</span>
                        <span class="l12-subj">{{ optional(optional($row->time_table)->subject)->name ?: '—' }}</span>
                        <span class="l13-date">{{ $row->date ? $row->date->format('D, d M Y') : '—' }}</span>
                    </div>
                    <div class="l13-for">
                        <span class="l13-fav">{{ $initialsOf($absentName) }}</span>
                        <span>Covering for <b>{{ $absentName ?: '—' }}</b> — on leave</span>
                    </div>
                </div>
            </div>
        @endforeach
    @endif
@endsection
