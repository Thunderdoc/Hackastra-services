@extends('layouts.login_master')

@section('content')
<style>
    .hackstra-login{min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f4f7fb;padding:24px}
    .hackstra-login-card{background:#fff;border:1px solid #e4eaf1;border-radius:16px;box-shadow:0 24px 60px rgba(16,38,59,.14);display:flex;max-width:880px;overflow:hidden;width:100%}
    .hackstra-login-brand{background:linear-gradient(rgba(13,32,52,.84),rgba(13,32,52,.94)),url('{{ asset('assets/img/ai/login-campus.jpg') }}') center/cover no-repeat #10263b;color:#fff;display:flex;flex-direction:column;justify-content:center;padding:44px 40px;width:42%}
    .hackstra-login-brand .brand-mark{align-items:center;background:#3478f6;border-radius:12px;display:flex;font-size:22px;height:52px;justify-content:center;margin-bottom:22px;width:52px}
    .hackstra-login-brand h3{color:#fff;font-weight:700;letter-spacing:.02em;margin:0 0 6px;text-transform:uppercase}
    .hackstra-login-brand p{color:rgba(255,255,255,.68);font-size:.85rem;line-height:1.6}
    .hackstra-login-brand ul{list-style:none;margin:22px 0 0;padding:0}
    .hackstra-login-brand li{color:rgba(255,255,255,.86);font-size:.82rem;margin-bottom:12px}
    .hackstra-login-brand li i{color:#7fb0ff;margin-right:9px}
    .hackstra-login-form{flex:1;padding:44px 40px}
    .hackstra-login-form h5{color:#172536;font-size:1.25rem;font-weight:700;margin:0 0 4px}
    .hackstra-login-form .sub{color:#718096;font-size:.83rem;margin-bottom:24px}
    .hackstra-login-form .form-control{border:1px solid #dce3ec;border-radius:9px;height:46px}
    .hackstra-login-form .form-control:focus{border-color:#3478f6;box-shadow:0 0 0 3px rgba(52,120,246,.14)}
    .hackstra-login-form label{color:#44546a;font-size:.78rem;font-weight:600}
    .hackstra-login-btn{background:#3478f6;border:0;border-radius:9px;box-shadow:0 8px 20px rgba(52,120,246,.25);color:#fff;font-weight:700;height:48px;width:100%}
    .hackstra-login-btn:hover{background:#2867dc;color:#fff}
    .hackstra-login .alert{border:0;border-radius:10px}
    @media (max-width:767px){.hackstra-login-brand{display:none}.hackstra-login-form{padding:32px 24px}}
    @media (max-width:480px){.hackstra-login{padding:12px}.hackstra-login-card{border-radius:14px}.hackstra-login-form{padding:26px 18px}.hackstra-login-form h5{font-size:1.12rem}}
</style>
<div class="hackstra-login">
    <div class="hackstra-login-card">
        <div class="hackstra-login-brand">
            <span class="brand-mark"><i class="icon-graduation2"></i></span>
            <h3>Hackstra</h3>
            <p>{{ Qs::getSystemName() ?: 'School Management System' }}<br>Students, staff, marks, fees and reports — one calm workspace for the whole school.</p>
            <ul>
                <li><i class="icon-checkmark3"></i> Role-based access for every account type</li>
                <li><i class="icon-checkmark3"></i> Marksheets, payments &amp; receipts in one place</li>
                <li><i class="icon-checkmark3"></i> Current session: {{ Qs::getCurrentSession() ?: '—' }}</li>
            </ul>
        </div>
        <form class="hackstra-login-form" method="post" action="{{ route('login') }}">
            @csrf
            <h5>Welcome back</h5>
            <p class="sub">Login to your account with your Login ID or email.</p>

            @if ($errors->any())
                <div class="alert alert-danger alert-dismissible">
                    <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
                    <strong>Oops!</strong>
                    @foreach($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            <div class="form-group">
                <label for="identity">Login ID or Email</label>
                <input id="identity" type="text" class="form-control" name="identity" value="{{ old('identity') }}" placeholder="Login ID or Email" autocomplete="username" autofocus>
            </div>

            <div class="form-group">
                <label for="password">{{ __('Password') }}</label>
                <input id="password" required name="password" type="password" class="form-control" placeholder="{{ __('Password') }}" autocomplete="current-password">
            </div>

            <div class="form-group d-flex align-items-center">
                <div class="form-check mb-0">
                    <label class="form-check-label">
                        <input type="checkbox" name="remember" class="form-input-styled" {{ old('remember') ? 'checked' : '' }} data-fouc>
                        Remember me
                    </label>
                </div>
                <a href="{{ route('password.request') }}" class="ml-auto">Forgot password?</a>
            </div>

            <div class="form-group mb-0">
                <button type="submit" class="btn hackstra-login-btn">Sign in <i class="icon-circle-right2 ml-2"></i></button>
            </div>
        </form>
    </div>
</div>
@endsection
