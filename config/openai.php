<?php

return [
    'api_key' => env('DEEPSEEK_API_KEY'),
    'organization' => null,
    'project' => null,
    'base_uri' => rtrim((string) env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com'), '/').'/v1',
    'request_timeout' => 30,
];
