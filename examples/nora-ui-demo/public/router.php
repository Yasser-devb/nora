<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path === '/avatar.svg') {
    return false;
}

require __DIR__ . DIRECTORY_SEPARATOR . 'index.php';
