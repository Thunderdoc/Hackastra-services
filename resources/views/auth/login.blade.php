@extends('layouts.login_master')

@section('content')
{{-- Login Design A (Workstream Z) — split screen, per the approved reference.
     All component styles live in qs.css, section "Loop 16 — Login Design A".
     The guest layout does not load qs.css, so it is linked here — the previous
     version of this view likewise carried its own <style> block. --}}
<link href="{{ asset('assets/css/qs.css') }}?v={{ file_exists(public_path('assets/css/qs.css')) ? filemtime(public_path('assets/css/qs.css')) : 1 }}" rel="stylesheet" type="text/css">
<style>
    /* Page chrome only: paint the viewport navy and hide the guest layout's
       top/bottom navbars so the split card owns the screen, as in the reference. */
    body{background:#081c30}
    body>.navbar{display:none!important}
</style>

<div class="login-a-page">
    <div class="login-a-card">

        {{-- ===== Left: brand panel ===== --}}
        <div class="login-a-brand">
            <svg class="login-a-brand-art" viewBox="0 0 560 640" preserveAspectRatio="xMidYMid slice" aria-hidden="true" focusable="false">
                <defs>
                    <radialGradient id="login-a-glow" cx="50%" cy="50%" r="50%">
                        <stop offset="0%" stop-color="#2e6de6" stop-opacity=".5"/>
                        <stop offset="100%" stop-color="#2e6de6" stop-opacity="0"/>
                    </radialGradient>
                    <pattern id="login-a-dots" width="18" height="18" patternUnits="userSpaceOnUse">
                        <circle cx="2.5" cy="2.5" r="2.5" fill="#5b84c4"/>
                    </pattern>
                </defs>
                <circle cx="512" cy="566" r="250" fill="url(#login-a-glow)"/>
                <circle cx="30" cy="46" r="180" fill="url(#login-a-glow)" opacity=".55"/>
                <rect x="352" y="6" width="196" height="112" fill="url(#login-a-dots)" opacity=".4"/>
                <g fill="none" stroke="#4d78b8" stroke-width="1.4">
                    <polygon points="452,50 481.4,67 481.4,101 452,118 422.6,101 422.6,67" opacity=".75"/>
                    <polygon points="392,130 409.3,140 409.3,160 392,170 374.7,160 374.7,140" opacity=".5"/>
                    <polygon points="486,170 508.5,183 508.5,209 486,222 463.5,209 463.5,183" opacity=".6"/>
                    <polygon points="70,496 108.1,518 108.1,562 70,584 31.9,562 31.9,518" opacity=".45"/>
                    <polygon points="120,452 133.9,460 133.9,476 120,484 106.1,476 106.1,460" opacity=".55"/>
                    <polygon points="330,540 345.6,549 345.6,567 330,576 314.4,567 314.4,549" opacity=".4"/>
                </g>
                <g stroke="#3d6db4" stroke-width="1" opacity=".35">
                    <path d="M-20 648 L236 392" fill="none"/>
                    <path d="M-20 600 L188 392" fill="none"/>
                </g>
                <g fill="#7fb0ff">
                    <circle cx="120" cy="238" r="2.4" opacity=".7"/>
                    <circle cx="486" cy="330" r="2.4" opacity=".6"/>
                    <circle cx="66" cy="356" r="2" opacity=".5"/>
                    <circle cx="520" cy="120" r="2" opacity=".55"/>
                </g>
            </svg>
            <div class="login-a-brand-inner">
                <h1 class="login-a-brand-title">Welcome to {{ Qs::getSystemName() ?: 'School Management System' }}</h1>
                <p class="login-a-brand-tag">Manage students, attendance, fees and academics in one place.</p>
                <ul class="login-a-brand-list">
                    <li>
                        <span class="login-a-check"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="11" fill="#2e6de6"/><path d="M7.2 12.6l3.1 3.1 6.5-6.9" fill="none" stroke="#fff" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                        Student enrollment &amp; profiles
                    </li>
                    <li>
                        <span class="login-a-check"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="11" fill="#2e6de6"/><path d="M7.2 12.6l3.1 3.1 6.5-6.9" fill="none" stroke="#fff" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                        Attendance tracking &amp; reporting
                    </li>
                    <li>
                        <span class="login-a-check"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="11" fill="#2e6de6"/><path d="M7.2 12.6l3.1 3.1 6.5-6.9" fill="none" stroke="#fff" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                        Fees, billing &amp; academic records
                    </li>
                </ul>
            </div>
        </div>

        {{-- ===== Right: sign-in panel ===== --}}
        <div class="login-a-panel">
            <div class="login-a-brandmark">
                @if(Qs::getSetting('logo'))
                    <img src="{{ Qs::getLogo() }}" alt="{{ Qs::getSystemName() ?: 'School logo' }}" class="login-a-logo">
                @else
                    <span class="login-a-mark" aria-hidden="true">
                        <svg viewBox="0 0 48 48" focusable="false">
                            <defs>
                                <linearGradient id="login-a-lg" x1="0" y1="0" x2="1" y2="1">
                                    <stop offset="0" stop-color="#3478f6"/>
                                    <stop offset="1" stop-color="#2867dc"/>
                                </linearGradient>
                            </defs>
                            <path d="M24 2.8 42 13.1v21.8L24 45.2 6 34.9V13.1z" fill="url(#login-a-lg)"/>
                            <path d="M24 12.4l3.4 7 7.7 1.1-5.6 5.4 1.4 7.6L24 29.9l-6.9 3.6 1.4-7.6-5.6-5.4 7.7-1.1z" fill="#fff"/>
                        </svg>
                    </span>
                    <span class="login-a-wordmark">{{ Qs::getAppCode() }}</span>
                @endif
            </div>

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
                    <input id="identity" type="text" class="login-a-input{{ $errors->has('identity') ? ' login-a-input-invalid' : '' }}" name="identity" value="{{ old('identity') }}" placeholder="Login ID or Email" autocomplete="username" autofocus>
                    @error('identity')
                        <span class="login-a-error" role="alert">{{ $message }}</span>
                    @enderror
                </div>

                <div class="login-a-field">
                    <label for="password" class="login-a-label">{{ __('Password') }}</label>
                    <div class="login-a-password">
                        <input id="password" required name="password" type="password" class="login-a-input login-a-input-password{{ $errors->has('password') ? ' login-a-input-invalid' : '' }}" placeholder="{{ __('Password') }}" autocomplete="current-password">
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

            <p class="login-a-powered">Powered by Hackastra</p>
        </div>
    </div>

    <p class="login-a-legal">
        &copy; {{ date('Y') }} {{ Qs::getSystemName() ?: 'School Management System' }}
        <span class="login-a-legal-sep">&middot;</span>
        <a href="{{ route('privacy_policy') }}" target="_blank">Privacy Policy</a>
        <span class="login-a-legal-sep">&middot;</span>
        <a href="{{ route('terms_of_use') }}" target="_blank">Terms of Use</a>
        <span class="login-a-legal-sep">&middot;</span>
        Current session: {{ Qs::getCurrentSession() ?: '—' }}
    </p>
</div>

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
