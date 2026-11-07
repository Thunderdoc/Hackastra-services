<?php

/*
 * F1 (Round 4) — WhatsApp Cloud API deployment knobs.
 *
 * School-facing credentials live in the settings table
 * (whatsapp_* keys, edited from Settings → WhatsApp) — never here.
 * This file holds deployment-level behaviour only, mirroring
 * config/gateway.php + config/ai.php:
 *
 *  - mock: WHATSAPP_MOCK=1 forces the mock transport even when
 *    credentials are saved. Mock ALSO engages automatically when
 *    no access token / phone-number-id is saved yet, or the token
 *    starts with test_ (WhatsAppGateway::mock) — the feature must
 *    be provable end-to-end before any Meta account exists.
 *  - graph_version: the Meta Graph API version the provider calls;
 *    a config knob, never hard-coded in the provider.
 *  - daily_cap: maximum sent|mocked messages per day (app-side cap
 *    on top of Meta's own messaging tiers).
 *  - inline_drain_limit: how many queued rows a trigger sends
 *    inline before leaving the rest queued for whatsapp:drain.
 *  - default_country_code: used by WhatsAppPhone when a parent's
 *    number is a bare 10-digit local number.
 */

return [

    'mock' => (bool) env('WHATSAPP_MOCK', false),

    'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v21.0'),

    'timeout' => (int) env('WHATSAPP_TIMEOUT', 10),

    'daily_cap' => (int) env('WHATSAPP_DAILY_CAP', 1000),

    'inline_drain_limit' => 25,

    'default_country_code' => '91',

];
