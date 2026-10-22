<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\SendsPasswordResetEmails;

class ForgotPasswordController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Password Reset Controller
    |--------------------------------------------------------------------------
    |
    | This controller is responsible for handling password reset emails and
    | includes a trait which assists in sending these notifications from
    | your application to your users. Feel free to explore this trait.
    |
    */

    use SendsPasswordResetEmails;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('guest');
        // Workstream DG: reset-link requests are a classic abuse target
        // (mail bombing + account probing). Cap sends per IP; the
        // password broker additionally throttles repeat tokens per user.
        // Same controller-middleware idiom as VerificationController.
        $this->middleware('throttle:password-email')->only('sendResetLinkEmail');
    }
}
