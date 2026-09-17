<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | This was a known, documented gap (zurie-backend-implementation-spec.md
    | §18) — without it, cookie-based cross-origin auth between the frontend
    | (zurie.co.tz) and this API (api.zurie.co.tz) fails
    | even with SANCTUM_STATEFUL_DOMAINS set correctly, since that config
    | only governs Sanctum's own auth handling, not the browser's separate
    | CORS check. `paths` is scoped to the API surface only — no reason to
    | CORS-enable /up (health check) or the unauthenticated media-serving
    | route, since neither is ever called cross-origin with credentials.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    /*
    | No wildcard, deliberately — supports_credentials: true below means a
    | wildcard origin is actually rejected by the browser anyway (the Fetch
    | spec forbids combining "*" with credentialed requests), but an
    | explicit allowlist is the point regardless: only origins that should
    | be able to make authenticated requests against this API are listed.
    | CORS_ALLOWED_ORIGINS is comma-separated, same convention as
    | SANCTUM_STATEFUL_DOMAINS — add a new frontend origin there, not here.
    */
    'allowed_origins' => array_filter(explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    /*
    | Required for Sanctum's cookie-based SPA auth to work cross-origin at
    | all — without this, the browser strips the session cookie from every
    | cross-origin request regardless of what SANCTUM_STATEFUL_DOMAINS says.
    */
    'supports_credentials' => true,

];
