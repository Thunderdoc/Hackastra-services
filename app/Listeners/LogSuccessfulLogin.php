<?php

namespace App\Listeners;

use App\Support\Audit;
use Illuminate\Auth\Events\Login;

class LogSuccessfulLogin
{
    public function handle(Login $event)
    {
        Audit::log('auth.login', $event->user, 'User signed in');
    }
}
