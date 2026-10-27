<?php

declare(strict_types=1);

$baseUrl = getenv('APP_BASE_URL');

return [
    'app' => [
        'environment' => 'development',
        'debug' => true,
        'base_url' => $baseUrl === false ? '' : rtrim($baseUrl, '/'),
    ],
];
