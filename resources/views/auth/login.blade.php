@extends('layouts.login_master')

@section('content')
{{-- Login — Guest Calm Split (2026-11-07 redesign): Sample C
     (full-page campus art + floating card) was rejected by the
     owner, so the page is now a calm app backdrop and ONE centred
     card carries a softened in-card art panel plus the sign-in
     form. All component styles live in qs.css (the "Guest Calm
     Split" append). The guest layout does not load qs.css, so it
     is linked here. Form, fields and POST unchanged. --}}
<link href="{{ asset('assets/css/qs.css') }}?v={{ file_exists(public_path('assets/css/qs.css')) ? filemtime(public_path('assets/css/qs.css')) : 1 }}" rel="stylesheet" type="text/css">
<style>
    /* Page chrome only: the calm backdrop tone before qs.css
       paints (pale in light, dark neutral in dark) and the guest
       layout's top/bottom navbars hidden so the page owns the
       screen. */
    body{background:#edf2f9}
    html.hk-dark body{background:#131416}
    body>.navbar{display:none!important}
</style>

<div class="login-a-page">
    @include('partials.login.brand_pill')
    <div class="login-a-card hk-reveal">
        <figure class="login-b-art" aria-hidden="true"></figure>

        {{-- ===== The sign-in panel ===== --}}
        <div class="login-a-panel">
            @include('partials.login.card_brand')

            <h2 class="login-a-title">Welcome back</h2>
            <p class="login-a-sub">Sign in to your account</p>

            <form method="POST" action="{{ route('login') }}" class="login-a-form">
                @csrf

                @if ($errors->any())
                    <div class="alert alert-danger alert-dismissible login-a-alert" role="alert">
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                        <strong>Oops!</strong>
                        @foreach($errors->all() as $error)
                            <div>{{ $error }}</div>
                        @endforeach
                    </div>
                @endif

                <div class="login-a-field">
                    <label for="identity" class="login-a-label">Email address</label>
                    <div class="login-a-control">
                        <span class="login-a-field-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        </span>
                        <input id="identity" type="text" class="login-a-input login-a-input-icon{{ $errors->has('identity') ? ' login-a-input-invalid' : '' }}" name="identity" value="{{ old('identity') }}" placeholder="Login ID or Email" autocomplete="username" autofocus>
                    </div>
                    @error('identity')
                        <span class="login-a-error" role="alert">{{ $message }}</span>
                    @enderror
                </div>

                <div class="login-a-field">
                    <label for="password" class="login-a-label">{{ __('Password') }}</label>
                    <div class="login-a-password">
                        <span class="login-a-field-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        </span>
                        <input id="password" required name="password" type="password" class="login-a-input login-a-input-icon login-a-input-password{{ $errors->has('password') ? ' login-a-input-invalid' : '' }}" placeholder="{{ __('Password') }}" autocomplete="current-password">
                        <button type="button" id="login-a-toggle" class="login-a-eye" aria-label="Show password" aria-pressed="false">
                            <svg class="login-a-eye-show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg class="login-a-eye-hide" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                        </button>
                    </div>
                    @error('password')
                        <span class="login-a-error" role="alert">{{ $message }}</span>
                    @enderror
                </div>

                <div class="login-a-row">
                    <div class="form-check login-a-remember">
                        <label class="form-check-label">
                            <input type="checkbox" name="remember" class="form-input-styled" {{ old('remember') ? 'checked' : '' }} data-fouc>
                            Remember me
                        </label>
                    </div>
                    <a href="{{ route('password.request') }}" class="login-a-forgot">Forgot password?</a>
                </div>

                <button type="submit" class="login-a-btn">Sign In</button>
            </form>

    @include('partials.login.guest_shell_close')

<script>
    (function () {
        var toggle = document.getElementById('login-a-toggle');
        var password = document.getElementById('password');
        if (!toggle || !password) { return; }
        toggle.addEventListener('click', function () {
            var show = password.type === 'password';
            password.type = show ? 'text' : 'password';
            toggle.classList.toggle('login-a-eye-on', show);
            toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
            toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            password.focus();
        });
    })();
</script>
@endsection
