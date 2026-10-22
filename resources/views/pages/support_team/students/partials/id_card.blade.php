{{--
    Student ID card (front face) — Loop 27.
    Expects $sr: a StudentRecord with user, my_class and section loaded.
    Every value comes from real records: school identity from Settings
    (Qs::getSystemName / getLogo / getCurrentSession), the rest from the
    student record and its user. DOB (users.dob) prints only when set.
--}}
@php
    $idcUser = $sr->user;
    $idcInitial = strtoupper(substr($idcUser->name ?? '', 0, 1));
    $idcDob = null;
    if (!empty($idcUser->dob)) {
        try { $idcDob = \Carbon\Carbon::parse($idcUser->dob)->format('d M Y'); }
        catch (\Exception $e) { $idcDob = $idcUser->dob; }
    }
    // Branding (Workstream BK): the card carries the SCHOOL's identity
    // only. Qs::getLogo() must NOT be used here — when no logo is
    // uploaded it silently falls back to the admin theme's asset
    // (global_assets/images/logo_light.png, the "LIMITLESS" wordmark).
    // Read the raw setting instead: a real uploaded logo prints; when
    // none exists, a monogram tile is built from the school's initials.
    $idcLogo = Qs::getSetting('logo');
    $idcSchool = (string) Qs::getSystemName();
    $idcMonogram = '';
    foreach (preg_split('/\s+/', trim($idcSchool)) as $idcWord) {
        if ($idcWord !== '') {
            $idcMonogram .= strtoupper(substr($idcWord, 0, 1));
            if (strlen($idcMonogram) >= 2) { break; }
        }
    }
    if ($idcMonogram === '') { $idcMonogram = 'S'; }
@endphp
<div class="idc-card">
    {{-- Watermark (Document design v2): the school monogram, faint,
         behind the card body — the background-artwork touch real PVC
         cards carry. Styles in qs.css Loop 68. --}}
    <span class="idc-watermark" aria-hidden="true">{{ $idcMonogram }}</span>
    <div class="idc-head">
        @if($idcLogo)
            <img src="{{ $idcLogo }}" alt="" class="idc-logo">
        @else
            <span class="idc-monogram" aria-hidden="true">{{ $idcMonogram }}</span>
        @endif
        <div class="idc-head-text">
            <div class="idc-school">{{ $idcSchool }}</div>
            <div class="idc-head-sub">Student Identity Card</div>
        </div>
    </div>
    <div class="idc-body">
        <div class="idc-photo" aria-hidden="true"><span class="idc-initial">{{ $idcInitial }}</span><img src="{{ $idcUser->photo }}" alt="" onerror="this.remove()"></div>
        <div class="idc-info">
            <div class="idc-name">{{ $idcUser->name }}</div>
            <div class="idc-row"><span class="idc-label">Class</span><span class="idc-value">{{ $sr->my_class->name }}@if($sr->section) &middot; {{ $sr->section->name }}@endif</span></div>
            <div class="idc-row"><span class="idc-label">Adm No</span><span class="idc-value">{{ $sr->adm_no ?: '—' }}</span></div>
            @if($idcDob)
                <div class="idc-row"><span class="idc-label">DOB</span><span class="idc-value">{{ $idcDob }}</span></div>
            @endif
        </div>
    </div>
    <div class="idc-foot">Valid for session <strong>{{ Qs::getCurrentSession() }}</strong></div>
</div>
