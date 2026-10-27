<?php

declare(strict_types=1);

namespace Nora\Config;

use RuntimeException;

final class EnvironmentLoader
{
    /**
     * Load KEY=value entries without replacing existing process variables.
     * No interpolation or command execution is performed.
     *
     * @return array<string, string> Effective values from the file or process.
     */
    public function load(string $path, bool $overrideExisting = false): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Environment file is missing or unreadable: ' . $path);
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException('Unable to read environment file: ' . $path);
        }

        $values = [];
        foreach ($lines as $offset => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = substr($line, 7);
            }

            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/D', $line, $matches) !== 1) {
                throw new ConfigException(
                    'Invalid environment assignment on line ' . ($offset + 1) . '.',
                );
            }

            $name = $matches[1];
            $value = $this->parseValue($matches[2], $offset + 1);
            $existing = getenv($name);

            if (!$overrideExisting && $existing !== false) {
                $values[$name] = $existing;
                continue;
            }

            if (putenv($name . '=' . $value) === false) {
                throw new RuntimeException('Unable to set environment variable "' . $name . '".');
            }
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
            $values[$name] = $value;
        }

        return $values;
    }

    private function parseValue(string $raw, int $line): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        $quote = $raw[0];
        if ($quote === '"' || $quote === "'") {
            $end = strrpos($raw, $quote);
            if ($end === 0 || $end === false) {
                throw new ConfigException('Unclosed quoted value on line ' . $line . '.');
            }

            $trailing = trim(substr($raw, $end + 1));
            if ($trailing !== '' && !str_starts_with($trailing, '#')) {
                throw new ConfigException('Unexpected characters after quoted value on line ' . $line . '.');
            }

            return substr($raw, 1, $end - 1);
        }

        $comment = strpos($raw, ' #');
        if ($comment !== false) {
            $raw = rtrim(substr($raw, 0, $comment));
        }

        return $raw;
    }
}
