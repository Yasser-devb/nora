<?php

declare(strict_types=1);

namespace Nora\Database;

use Closure;
use InvalidArgumentException;
use Nora\Logging\LoggerInterface;
use RuntimeException;

final class QueryDiagnostics
{
    private readonly Closure $clock;

    /**
     * The clock must return monotonic nanoseconds, such as hrtime(true).
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly int $slowQueryThresholdNanoseconds,
        ?callable $clock = null,
    ) {
        if ($slowQueryThresholdNanoseconds < 0) {
            throw new InvalidArgumentException('The slow-query threshold cannot be negative.');
        }

        $this->clock = Closure::fromCallable($clock ?? static fn (): int => hrtime(true));
    }

    public function start(): int
    {
        $startedAt = ($this->clock)();
        if (!is_int($startedAt) || $startedAt < 0) {
            throw new RuntimeException('The query diagnostics clock returned an invalid timestamp.');
        }

        return $startedAt;
    }

    public function record(string $sql, int $startedAt): void
    {
        $finishedAt = ($this->clock)();
        if (!is_int($finishedAt) || $finishedAt < $startedAt) {
            throw new RuntimeException('The query diagnostics clock did not advance monotonically.');
        }

        $duration = $finishedAt - $startedAt;
        if ($duration < $this->slowQueryThresholdNanoseconds) {
            return;
        }

        preg_match('/\A\s*([A-Za-z]+)/', $sql, $match);
        $operation = strtoupper($match[1] ?? '');
        if (!in_array($operation, ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'ALTER', 'DROP'], true)) {
            $operation = 'OTHER';
        }

        $this->logger->log('warning', 'Slow database query.', [
            'operation' => $operation,
            'sql_sha256' => hash('sha256', $sql),
            'sql_length' => strlen($sql),
            'duration_nanoseconds' => $duration,
        ]);
    }
}
