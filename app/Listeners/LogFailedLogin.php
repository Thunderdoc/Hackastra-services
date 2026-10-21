<?php

namespace App\Listeners;

use App\Support\Audit;
use Illuminate\Auth\Events\Failed;

class LogFailedLogin
{
    public function handle(Failed $event)
    {
        // Record the attempt for the platform security view. Store ONLY
        // the attempted identity (in the description, by design) — the
        // event also carries the submitted password in its credentials,
        // which must NEVER reach the audit log.
        $credentials = is_array($event->credentials) ? $event->credentials : [];
        $identity = $credentials['identity'] ?? $credentials['email'] ?? $credentials['username'] ?? null;
        $identity = is_string($identity) && trim($identity) !== '' ? trim($identity) : '(no identity given)';

        // Subject: the account that was targeted, when the identity
        // matched a real user; null for unknown identities.
        Audit::log('auth.failed', $event->user, $identity);
    }
}
