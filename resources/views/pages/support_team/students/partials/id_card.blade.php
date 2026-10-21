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
@endphp
<div class="idc-card">
    <div class="idc-head">
        <img src="{{ Qs::getLogo() }}" alt="" class="idc-logo">
        <div class="idc-head-text">
            <div class="idc-school">{{ Qs::getSystemName() }}</div>
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
