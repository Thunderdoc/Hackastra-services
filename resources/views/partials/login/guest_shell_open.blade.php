{{-- Guest shell OPEN (Guest Calm Split, 2026-11-07 redesign):
     wraps a guest page in the login design — a calm app backdrop
     and ONE centred card carrying a softened in-card art panel
     plus the page's form, with the school brand at the panel top
     (partials/login/card_brand). The guest layout does not load
     qs.css, so it is linked here (same cache-bust as the login
     view). Pair with guest_shell_close. Presentation only: the
     page's own form markup goes between the two includes,
     unchanged. --}}
<link href="{{ asset('assets/css/qs.css') }}?v={{ file_exists(public_path('assets/css/qs.css')) ? filemtime(public_path('assets/css/qs.css')) : 1 }}" rel="stylesheet" type="text/css">
<style>
    /* Page chrome only (same as auth/login): the calm backdrop
       tone before qs.css paints (pale in light, dark neutral in
       dark) and the guest layout's top/bottom navbars hidden so
       the card owns the screen. */
    body{background:#edf2f9}
    html.hk-dark body{background:#131416}
    body>.navbar{display:none!important}
</style>

<div class="login-a-page">
    @include('partials.login.brand_pill')
    <div class="login-a-card hk-reveal">
        <figure class="login-b-art" aria-hidden="true"></figure>

        {{-- ===== The page's own form, in the card's panel ===== --}}
        <div class="login-a-panel login-a-panel-guest">
            @include('partials.login.card_brand')
