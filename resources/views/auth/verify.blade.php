@extends('layouts.login_master')

{{-- Loop 108: was 100% stock Laravel scaffolding on layouts.app.
     Now on login_master with the shared guest card. Content and
     the resend link are identical — presentation only. --}}
@section('content')
    <div class="page-content login-cover">
        <div class="content-wrapper">
            <div class="content d-flex justify-content-center align-items-center">

                <div class="login-form">
                    <div class="card mb-0">
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <h5 class="mb-0">{{ __('Verify Your Email Address') }}</h5>
                            </div>

                            @if (session('resent'))
                                <div class="alert alert-success" role="alert">
                                    {{ __('A fresh verification link has been sent to your email address.') }}
                                </div>
                            @endif

                            {{ __('Before proceeding, please check your email for a verification link.') }}
                            {{ __('If you did not receive the email') }}, <a href="{{ route('verification.resend') }}">{{ __('click here to request another') }}</a>.
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
@endsection
