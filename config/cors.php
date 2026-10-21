<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Stock Laravel 9 configuration, added at the 8→9 upgrade hop: the app
    | previously relied on the fruitcake/laravel-cors package defaults via
    | its merged package config. Making the framework defaults explicit
    | keeps behaviour identical now that CORS is handled by
    | Illuminate\Http\Middleware\HandleCors.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
