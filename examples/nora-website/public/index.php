<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Nora\Core\ApplicationBootstrap;
use Nora\Http\Response;
use NoraWebsite\Site\PageRenderer;

$root = dirname(__DIR__);
$baseUrl = getenv('APP_BASE_URL');
$pages = new PageRenderer(
    $root . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'views',
    $baseUrl === false ? '' : rtrim($baseUrl, '/'),
);
$contentSecurityPolicy = "default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; script-src 'self'; style-src 'self'; connect-src 'self'";
$errorResponseFactory = static fn (int $status): Response => new Response(
    $pages->errorPage($status),
    $status,
    [
        'Content-Type' => 'text/html; charset=utf-8',
        'Content-Security-Policy' => $contentSecurityPolicy,
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
    ],
);

(new ApplicationBootstrap(errorResponseFactory: $errorResponseFactory))->boot($root)->run();
