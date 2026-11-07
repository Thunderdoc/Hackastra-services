{{--
    PATTERN · setup_checklist (Round 5 P15 — onboarding)
    ---------------------------------------------------------------
    The setup subset of the School Readiness checks, as ordered
    steps on the school Admin's home: School profile, Logo,
    Session, Classes & sections, Teachers, Students, Fee
    structure. Every row is one live Readiness check re-keyed by
    its label — no check logic is duplicated here, so this card
    can never disagree with the Planning readiness page.

    USAGE (the dashboard passes the Readiness result in):

        @include('partials.patterns.setup_checklist', [
            'setup_readiness' => $setup_readiness,
        ])

    When no result is passed, the partial reads Readiness itself
    (same single source). Rendering is the caller's decision:
    HomeController computes the data only for the school Admin,
    and the dashboard includes this partial only while the
    readiness score is under 100 percent.

    Collapsible: the chevron toggles the step list and remembers
    the choice for the browser session (sessionStorage), the
    same try/catch shape the topbar notices use. The toggle
    flips inline display, so it works even before the companion
    styles land. Styles: css-p15 fragment (hk-setup- classes),
    merged into qs.css by the coordinator.
--}}
@php
    $hkSetup = $setup_readiness ?? \App\Support\Readiness::checks();
    $hkSetupByLabel = collect($hkSetup['checks'] ?? [])->keyBy('label');
    $hkSetupSteps = [
        ['title' => 'School profile', 'check' => 'School identity', 'icon' => 'icon-office', 'tone' => 'blue', 'action' => 'Open Settings'],
        ['title' => 'School logo', 'check' => 'School logo uploaded', 'icon' => 'icon-image2', 'tone' => 'purple', 'action' => 'Open Settings'],
        ['title' => 'Session', 'check' => 'Current session set', 'icon' => 'icon-calendar3', 'tone' => 'amber', 'action' => 'Open Settings'],
        ['title' => 'Classes & sections', 'check' => 'Classes & sections', 'icon' => 'icon-grid', 'tone' => 'blue', 'action' => 'Open Classes'],
        ['title' => 'Teachers', 'check' => 'Teacher load within caps', 'icon' => 'icon-user-tie', 'tone' => 'green', 'action' => 'Open Staffing'],
        ['title' => 'Students', 'check' => 'Students enrolled', 'icon' => 'icon-users4', 'tone' => 'blue', 'action' => 'Open Students'],
        ['title' => 'Fee structure', 'check' => 'Fee structure defined', 'icon' => 'icon-coin-dollar', 'tone' => 'green', 'action' => 'Open Fee Structure'],
    ];
    $hkSetupRows = [];
    foreach ($hkSetupSteps as $hkStep) {
        $hkCheck = $hkSetupByLabel[$hkStep['check']] ?? null;
        if ($hkCheck) {
            $hkSetupRows[] = ['step' => $hkStep, 'check' => $hkCheck];
        }
    }
    $hkSetupDone = count(array_filter($hkSetupRows, function ($row) { return $row['check']['status'] === 'ok'; }));
@endphp
@if(count($hkSetupRows))
<section class="dashboard-panel hk-setup-checklist mb-4">
    <div class="dashboard-panel-heading hk-setup-head">
        <div>
            <h5 class="mb-1"><i class="icon-rocket text-blue mr-1"></i> Get your school set up</h5>
            <p class="dashboard-muted mb-0">{{ $hkSetupDone }} of {{ count($hkSetupRows) }} setup steps ready &middot; full checklist on the <a href="{{ route('planning.readiness') }}">School Readiness</a> page</p>
        </div>
        <button type="button" class="btn btn-light btn-sm hk-setup-toggle" aria-expanded="true" aria-label="Collapse setup checklist">
            <i class="icon-chevron-up"></i>
        </button>
    </div>
    <div class="hk-setup-body">
        @foreach($hkSetupRows as $hkRow)
            <div class="hk-setup-row">
                <span class="l17-qi l17-tone-{{ $hkRow['step']['tone'] }} hk-setup-icon"><i class="{{ $hkRow['step']['icon'] }}"></i></span>
                <span class="hk-setup-copy">
                    <span class="hk-setup-title">{{ $hkRow['step']['title'] }}</span>
                    <span class="hk-setup-detail">{{ $hkRow['check']['detail'] }}</span>
                </span>
                <span class="hk-setup-side">
                    @if($hkRow['check']['status'] === 'ok')<span class="badge badge-success">Ready</span>
                    @elseif($hkRow['check']['status'] === 'warn')<span class="badge badge-warning">Attention</span>
                    @else<span class="badge badge-danger">Missing</span>@endif
                    @if(!empty($hkRow['check']['route']))
                        <a class="btn {{ $hkRow['check']['status'] === 'ok' ? 'btn-light' : 'btn-primary' }} btn-sm hk-setup-action" href="{{ route($hkRow['check']['route']) }}">{{ $hkRow['step']['action'] }}</a>
                    @endif
                </span>
            </div>
        @endforeach
    </div>
    <script>
    (function () {
        var root = document.currentScript.closest('.hk-setup-checklist');
        if (!root) { return; }
        var body = root.querySelector('.hk-setup-body');
        var toggle = root.querySelector('.hk-setup-toggle');
        var key = 'hk-setup-checklist-collapsed';
        function apply(collapsed) {
            if (body) { body.style.display = collapsed ? 'none' : ''; }
            if (toggle) {
                toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                toggle.setAttribute('aria-label', collapsed ? 'Expand setup checklist' : 'Collapse setup checklist');
                var icon = toggle.querySelector('i');
                if (icon) { icon.className = collapsed ? 'icon-chevron-down' : 'icon-chevron-up'; }
            }
        }
        var collapsed = false;
        try { collapsed = sessionStorage.getItem(key) === '1'; } catch (e) {}
        apply(collapsed);
        if (toggle) {
            toggle.addEventListener('click', function () {
                collapsed = !(body && body.style.display === 'none');
                apply(collapsed);
                try { sessionStorage.setItem(key, collapsed ? '1' : '0'); } catch (e) {}
            });
        }
    })();
    </script>
</section>
@endif
