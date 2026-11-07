@extends('layouts.master')
@section('page_title', 'My Slot Preferences')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; My Slot Preferences
@endsection
@section('content')
    {{-- My Slot Preferences (owner ask 2026-11-06, gap G2): the
         first slot-choice surface a teacher has ever had. The
         slots offered live inside the frame of HER effective
         sections (shown as chips — the controller scopes them via
         SectionTeacher::unitSectionIdsByClass, so no other
         teacher's section can appear here). A preference is a
         (weekday, period) request the generator tries to honour
         as a soft tie-break; the copy below says plainly that it
         is not a guarantee. A teacher with no sections sees the
         empty state and no grid. --}}
    <style>
        .sp-chips{display:flex;flex-wrap:wrap;gap:8px}
        .sp-chip{display:inline-flex;align-items:center;gap:6px;background:#eef4ff;color:#2e5ea8;border:1px solid #d7e5fb;border-radius:999px;padding:5px 12px;font-size:12.5px;font-weight:600}
        .sp-chip i{color:#2e6be6}
        .sp-grid th,.sp-grid td{text-align:center;vertical-align:middle}
        .sp-grid th .sp-time{display:block;font-weight:400;font-size:11px;color:#7a8a9e}
        .sp-grid td.sp-day{text-align:left;font-weight:600;white-space:nowrap}
        .sp-cell{display:block;margin:0;cursor:pointer}
        .sp-cell .sp-in{position:absolute;opacity:0;pointer-events:none}
        .sp-cell .sp-box{display:block;border:1px solid #dbe4ef;border-radius:8px;padding:9px 4px;font-size:12px;font-weight:600;color:#7a8a9e;background:#fbfcfe;transition:all .15s ease}
        .sp-cell:hover .sp-box{border-color:#2e6be6;color:#2e6be6}
        .sp-cell .sp-in:checked + .sp-box{background:#2e6be6;border-color:#2e6be6;color:#fff;box-shadow:0 4px 10px rgba(46,107,230,.25)}
        .sp-cell .sp-in:focus + .sp-box{outline:2px solid rgba(46,107,230,.5);outline-offset:1px}
        /* Beauty Batch 1 (2026-11-07, audit G2): dark parity —
           the 40 Pick boxes and the section chips were light-only
           surfaces on the dark page. The selected state keeps the
           app blue (its rule outranks these), and hover is scoped
           to unselected boxes so a chosen slot never un-blues. */
        html.hk-dark .sp-chip{background:rgba(52,120,246,.14);color:#9ec2ff;border-color:rgba(52,120,246,.35)}
        html.hk-dark .sp-chip i{color:#7db3ff}
        html.hk-dark .sp-grid th .sp-time{color:#8f9299}
        html.hk-dark .sp-cell .sp-box{background:#1e1f21;border-color:#3e4044;color:#a5a8ad}
        html.hk-dark .sp-cell:hover .sp-in:not(:checked) + .sp-box{border-color:#3478f6;color:#9ec2ff}
    </style>

    <div class="l10-head">
        <span class="l10-head-tile"><i class="icon-watch"></i></span>
        <div class="l10-head-text">
            {{-- Beauty Batch 3 (audit B1): no h5 here — the page
                 header owns the "My Slot Preferences" title; this
                 band keeps the guidance line. --}}
            <p>Pick the periods you would most like to teach. When the timetable is generated, your periods are placed in these slots first whenever everything else is equal.</p>
        </div>
        <span class="l10-chip"><i class="icon-calendar3"></i>Session {{ Qs::getCurrentSession() }}</span>
    </div>

    @if(empty($sections))
        <div class="l13-empty">
            <span class="l13-empty-ic"><i class="icon-watch"></i></span>
            <p>You have no sections yet, so there are no slots to choose from. When the admin allots you a subject in a section, this page will offer the week's slots for those sections.</p>
        </div>
    @else
        <div class="card">
            <div class="card-body">
                <h6 class="mb-1"><i class="icon-graduation2 mr-1"></i> Your sections</h6>
                <p class="text-muted mb-2">These are the sections you teach in. Your slot choices apply to the periods you teach in them.</p>
                <div class="sp-chips">
                    @foreach($sections as $chip)
                        <span class="sp-chip"><i class="icon-checkmark-circle2"></i>{{ $chip['label'] }}</span>
                    @endforeach
                </div>
            </div>
        </div>

        <form method="POST" action="{{ route('tt.slot_preferences.update') }}">
            @csrf
            <div class="card">
                <div class="card-body">
                    <h6 class="mb-1"><i class="icon-calendar5 mr-1"></i> Preferred slots</h6>
                    <p class="text-muted mb-2">
                        Tick up to {{ $max }} slots. <strong>These are requests, not guarantees:</strong>
                        the generator tries to honour them when class targets, other teachers, caps and
                        clashes leave a free choice — a preference never overrides a clash or a cap,
                        so a ticked slot can still go to another subject when the rules require it.
                    </p>
                    <div class="table-responsive">
                        <table class="table sp-grid">
                            <thead>
                                <tr>
                                    <th style="text-align:left">Day</th>
                                    @foreach($slots as $slot)
                                        <th>Period {{ $slot['period'] }}<span class="sp-time">{{ $slot['label'] }}</span></th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($days as $day)
                                    <tr>
                                        <td class="sp-day">{{ $day }}</td>
                                        @foreach($slots as $slot)
                                            @php $selKey = $day . '|' . $slot['period']; $isSel = isset($selected[$selKey]); @endphp
                                            <td>
                                                <label class="sp-cell">
                                                    <input type="checkbox" class="sp-in" name="slots[]" value="{{ $selKey }}"{{ $isSel ? ' checked' : '' }}>
                                                    <span class="sp-box">{{ $isSel ? 'Preferred' : 'Pick' }}</span>
                                                </label>
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="d-flex align-items-center justify-content-between flex-wrap">
                        <span class="text-muted"><span id="spCount">{{ count($selected) }}</span> of {{ $max }} slots chosen</span>
                        <button type="submit" class="btn btn-primary"><i class="icon-checkmark3 mr-1"></i> Save Preferences</button>
                    </div>
                </div>
            </div>
        </form>
        <script>
            (function () {
                var boxes = document.querySelectorAll('.sp-in'), out = document.getElementById('spCount');
                if (!out) { return; }
                var update = function () {
                    var n = 0;
                    boxes.forEach(function (b) { if (b.checked) { n++; } });
                    out.textContent = n;
                };
                boxes.forEach(function (b) { b.addEventListener('change', update); });
            })();
        </script>
    @endif
@endsection
