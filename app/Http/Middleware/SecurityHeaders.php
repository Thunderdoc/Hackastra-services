<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/*
 * Workstream DG — baseline security headers on every response.
 *
 * Deliberately conservative: these three headers cannot break the app
 * (no CSP — the app relies on inline scripts throughout its Blade
 * templates, so a Content-Security-Policy would need a nonce overhaul
 * and shipping a naive one would break pages; see DG report).
 *
 * - X-Content-Type-Options: nosniff — browsers must honour the declared
 *   content type (stops MIME-sniffing of uploads).
 * - X-Frame-Options: SAMEORIGIN — the app may frame its own pages
 *   (print previews), but no third-party site may embed it
 *   (clickjacking protection).
 * - Referrer-Policy: same-origin — no referrer leaks to third parties
 *   when a user follows an external link.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'same-origin');

        return $response;
    }
}
