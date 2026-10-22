<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * Indicates whether the XSRF-TOKEN cookie should be set on the response.
     *
     * @var bool
     */
    protected $addHttpCookie = true;

    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array
     */
    protected $except = [
        // Razorpay webhook (Workstream CU): Razorpay's servers cannot
        // carry a CSRF token; authenticity comes from the webhook
        // signature verified in GatewayWebhookController.
        'webhooks/razorpay',
    ];
}
