{{-- Guest shell OPEN (Guest Sample C, 2026-11-06): wraps a guest
     page in the owner-approved login design — the campus
     illustration covers the whole page at full colour and ONE
     floating card carries the page's form, with the school brand
     at the card top (partials/login/card_brand). There is no brand
     panel. The guest layout does not load qs.css, so it is linked
     here (same cache-bust as the login view). Pair with
     guest_shell_close. Presentation only: the page's own form
     markup goes between the two includes, unchanged. --}}
<link href="{{ asset('assets/css/qs.css') }}?v={{ file_exists(public_path('assets/css/qs.css')) ? filemtime(public_path('assets/css/qs.css')) : 1 }}" rel="stylesheet" type="text/css">
<style>
    /* Page chrome only (same as auth/login): a soft tone behind
       the campus art while it loads (cream in light, navy in dark)
       and the guest layout's top/bottom navbars hidden so the
       floating card owns the screen. */
    body{background:#f3ead9}
    html.hk-dark body{background:#0b2239}
    body>.navbar{display:none!important}
</style>

<div class="login-a-page">
    <div class="login-a-art" aria-hidden="true"></div>
    @include('partials.login.brand_pill')
    <div class="login-a-card hk-reveal">

        {{-- ===== The page's own form, in the floating card ===== --}}
        <div class="login-a-panel login-a-panel-guest">
            @include('partials.login.card_brand')
