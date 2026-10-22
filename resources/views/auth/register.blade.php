@extends('layouts.login_master')

{{-- Loop 108: was 100% stock Laravel scaffolding on layouts.app
     (the only two pages not on the Hackstra guest design). Now on
     login_master with the same login-form card as the password
     recovery page. Every field, name, route and error binding is
     identical — presentation only. --}}
@section('content')
    <div class="page-content login-cover">
        <div class="content-wrapper">
            <div class="content d-flex justify-content-center align-items-center">

                <form class="login-form" method="POST" action="{{ route('register') }}">
                    @csrf
                    <div class="card mb-0">
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <h5 class="mb-0">{{ __('Register') }}</h5>
                                <span class="d-block text-muted">Create your account</span>
                            </div>

                            <div class="form-group">
                                <label for="name">{{ __('Name') }}</label>
                                <input id="name" type="text" class="form-control{{ $errors->has('name') ? ' is-invalid' : '' }}" name="name" value="{{ old('name') }}" required autofocus>
                                @if ($errors->has('name'))
                                    <span class="invalid-feedback" role="alert">
                                        <strong>{{ $errors->first('name') }}</strong>
                                    </span>
                                @endif
                            </div>

                            <div class="form-group">
                                <label for="email">{{ __('E-Mail Address') }}</label>
                                <input id="email" type="email" class="form-control{{ $errors->has('email') ? ' is-invalid' : '' }}" name="email" value="{{ old('email') }}" required>
                                @if ($errors->has('email'))
                                    <span class="invalid-feedback" role="alert">
                                        <strong>{{ $errors->first('email') }}</strong>
                                    </span>
                                @endif
                            </div>

                            <div class="form-group">
                                <label for="password">{{ __('Password') }}</label>
                                <input id="password" type="password" class="form-control{{ $errors->has('password') ? ' is-invalid' : '' }}" name="password" required>
                                @if ($errors->has('password'))
                                    <span class="invalid-feedback" role="alert">
                                        <strong>{{ $errors->first('password') }}</strong>
                                    </span>
                                @endif
                            </div>

                            <div class="form-group">
                                <label for="password-confirm">{{ __('Confirm Password') }}</label>
                                <input id="password-confirm" type="password" class="form-control" name="password_confirmation" required>
                            </div>

                            <button type="submit" class="btn bg-blue btn-block">
                                {{ __('Register') }}
                            </button>
                        </div>
                    </div>
                </form>

            </div>
        </div>
    </div>
@endsection
