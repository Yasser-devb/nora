<?php

declare(strict_types=1);

$password = getenv('MYSQL_ROOT_PASSWORD');
if ($password === false || $password === '') {
    throw new RuntimeException('MYSQL_ROOT_PASSWORD is required.');
}

$sqlFile = __DIR__ . '/../mysql-init/01-nora-test-user.sql';
$sql = file_get_contents($sqlFile);
if ($sql === false) {
    throw new RuntimeException(sprintf('Unable to read database setup SQL: %s', $sqlFile));
}

$pdo = new PDO(
    'mysql:host=127.0.0.1;port=3306;dbname=nora_test;charset=utf8mb4',
    'root',
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
    $pdo->exec($statement);
}
