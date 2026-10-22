<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Login Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles authenticating users for the application and
    | redirecting them to your home screen. The controller uses a trait
    | to conveniently provide its functionality to your applications.
    |
    */

    use AuthenticatesUsers;

    /**
     * Where to redirect users after login.
     *
     * @var string
     */
    protected $redirectTo = '/home';

    /**
     * Create a new controller instance.
     *
     * @return void$field
     */
    public function __construct()
    {
        $this->middleware('guest')->except('logout');
    }

    /*
     *  Login with Username or Email
     * */
    public function username()
    {
        $identity = request()->identity;
        $field = filter_var($identity, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
        request()->merge([$field => $identity]);
        return $field;
    }

    /*
     * Account lifecycle gate (Workstream DF): credentials are valid
     * but a suspended/terminated account must not get a session.
     * The login form reads its errors from the 'identity' key.
     * (Sessions opened BEFORE the status change are ended by the
     * EnsureActiveAccount middleware on the next request.)
     */
    protected function authenticated(Request $request, $user)
    {
        $status = $user->getAttribute('status');

        if ($status && $status !== 'active') {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $message = $status === 'terminated'
                ? 'This account has been terminated. Please contact the platform administrator.'
                : 'This account has been suspended. Please contact the platform administrator.';

            throw ValidationException::withMessages(['identity' => $message]);
        }
    }
}
