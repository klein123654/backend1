<?php

$frontendOrigins = array_values(array_unique(array_filter(array_map(
    static fn (string $origin): string => trim($origin),
    preg_split('/\s*,\s*/', (string) env('FRONTEND_URL', 'http://localhost:5173'))
))));

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        ...$frontendOrigins,
        'https://frontend-1cf.pages.dev',
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'https://localhost:5173',
        'https://127.0.0.1:5173',
    ],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
