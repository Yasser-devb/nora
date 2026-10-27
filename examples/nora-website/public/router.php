<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$root = realpath(__DIR__);
$file = is_string($path) ? realpath(__DIR__ . $path) : false;
$insidePublic = $root !== false
    && $file !== false
    && is_file($file)
    && (DIRECTORY_SEPARATOR === '\\'
        ? str_starts_with(strtolower($file), strtolower($root . DIRECTORY_SEPARATOR))
        : str_starts_with($file, $root . DIRECTORY_SEPARATOR));

if ($insidePublic) {
    return false;
}

require __DIR__ . '/index.php';
