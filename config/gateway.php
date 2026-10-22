<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Payment gateway — mock mode (Workstream CU)
    |--------------------------------------------------------------------------
    |
    | The live Razorpay keys live in the school Settings (entered by the
    | Admin in Settings → Online Fee Payments), never in .env or code.
    |
    | Mock mode exists so the whole flow — order, checkout, signature
    | verification, recording, webhook — can be exercised without real
    | money. It is ON when GATEWAY_MOCK=true in .env, or automatically
    | when the configured key id is a Razorpay TEST key (rzp_test_…).
    | In mock mode the checkout is simulated locally, but the callback
    | is still signed with the real HMAC-SHA256 scheme and verified by
    | the exact production code path — only the money is fake.
    |
    */

    'mock' => env('GATEWAY_MOCK', false),

    // Fallback signing secret used ONLY in mock mode when the school has
    // not saved a key secret yet, so a fresh demo can run end-to-end.
    'mock_secret' => env('GATEWAY_MOCK_SECRET', 'mock_secret_for_local_demo_only'),

];
