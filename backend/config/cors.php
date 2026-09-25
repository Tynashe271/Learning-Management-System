<?php

// Which websites may call this API from a browser. Set LMS_CORS_ORIGINS to a comma-separated list; by default only the
// frontend (LMS_FRONTEND_URL) is allowed. Never use "*" in production. Authentication is by bearer token, not cookies, so
// credentials are not shared cross-site.
$frontend = parse_url((string) env('LMS_FRONTEND_URL', env('APP_URL', 'http://localhost')));
$default = isset($frontend['scheme'], $frontend['host'])
    ? $frontend['scheme'].'://'.$frontend['host'].(isset($frontend['port']) ? ':'.$frontend['port'] : '')
    : '';

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('LMS_CORS_ORIGINS', $default))))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept', 'X-Requested-With', 'X-Request-Id', 'Idempotency-Key', 'If-None-Match'],
    'exposed_headers' => ['X-Request-Id', 'Retry-After', 'ETag', 'Content-Disposition', 'Idempotent-Replayed'],
    'max_age' => 600,
    'supports_credentials' => false,
];
