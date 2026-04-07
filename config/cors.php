<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Configure allowed origins for production security.
    | In development, CORS_ALLOWED_ORIGINS can be set to "*".
    | In production, set explicit domains:
    |   CORS_ALLOWED_ORIGINS="https://app.muhasebi.com,https://muhasebi.com"
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_filter(explode(',', env('CORS_ALLOWED_ORIGINS', ''))),

    'allowed_origins_patterns' => array_filter([
        // Allow all subdomains of muhasebi.com in production
        env('CORS_ALLOWED_PATTERN', ''),
    ]),

    'allowed_headers' => [
        'Content-Type',
        'Accept',
        'Authorization',
        'X-Requested-With',
        'X-Tenant',
        'X-Request-Id',
        'Accept-Language',
    ],

    'exposed_headers' => [
        'X-API-Version',
        'X-Request-Id',
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining',
        'X-API-Deprecation',
        'Sunset',
        'ETag',
    ],

    'max_age' => 7200, // 2 hours — reduces preflight requests

    'supports_credentials' => (bool) env('CORS_SUPPORTS_CREDENTIALS', false),

];
