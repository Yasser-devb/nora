<?php

declare(strict_types=1);

namespace Nora\Logging;

use InvalidArgumentException;
use RuntimeException;

final class StructuredLogger implements LoggerInterface
{
    private const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical'];

    /** @var resource */
    private mixed $stream;

    private readonly int $minimumLevel;

    /**
     * @param resource $stream
     */
    public function __construct(mixed $stream, string $minimumLevel = 'debug')
    {
        if (!is_resource($stream)) {
            throw new InvalidArgumentException('Logger output must be a writable stream resource.');
        }

        $minimumLevel = strtolower($minimumLevel);
        $level = array_search($minimumLevel, self::LEVELS, true);
        if ($level === false) {
            throw new InvalidArgumentException('Unsupported minimum log level "' . $minimumLevel . '".');
        }

        $this->stream = $stream;
        $this->minimumLevel = $level;
    }

    public static function toErrorLog(string $minimumLevel = 'info'): self
    {
        $stream = fopen('php://stderr', 'ab');
        if ($stream === false) {
            throw new RuntimeException('Unable to open the error log stream.');
        }

        return new self($stream, $minimumLevel);
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $level = strtolower($level);
        if (!in_array($level, self::LEVELS, true)) {
            throw new InvalidArgumentException('Unsupported log level "' . $level . '".');
        }
        if (array_search($level, self::LEVELS, true) < $this->minimumLevel) {
            return;
        }

        $record = [
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'level' => $level,
            'message' => $this->redactString($message),
            'context' => $this->redact($context),
        ];
        $json = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        $written = fwrite($this->stream, $json);
        if ($written === false || $written !== strlen($json)) {
            throw new RuntimeException('Unable to write the complete structured log record.');
        }
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function redact(array $context): array
    {
        $redacted = [];
        foreach ($context as $key => $value) {
            if (preg_match('/password|secret|token|authorization|cookie|api[_-]?key/i', (string) $key) === 1) {
                $redacted[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $redacted[$key] = $this->redact($value);
            } elseif (is_string($value)) {
                $redacted[$key] = $this->redactString($value);
            } elseif (is_object($value) || is_resource($value)) {
                $redacted[$key] = '[OMITTED]';
            } else {
                $redacted[$key] = $value;
            }
        }

        return $redacted;
    }

    private function redactString(string $value): string
    {
        $value = preg_replace(
            '/((?:password|secret|token|authorization|cookie|api[_-]?key)\s*[=:]\s*)(?:"[^"]*"|\'[^\']*\'|[^\s,;]+)/i',
            '$1[REDACTED]',
            $value,
        );
        if ($value === null) {
            throw new RuntimeException('Unable to redact structured log data.');
        }

        $value = preg_replace('/Bearer\s+[A-Za-z0-9._~+\/=-]+/i', 'Bearer [REDACTED]', $value);
        if ($value === null) {
            throw new RuntimeException('Unable to redact structured log data.');
        }

        return $value;
    }
}
