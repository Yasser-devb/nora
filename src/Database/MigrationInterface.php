<?php

declare(strict_types=1);

namespace Nora\Database;

use PDO;

interface MigrationInterface
{
    public function up(PDO $pdo): void;

    public function down(PDO $pdo): void;
}
