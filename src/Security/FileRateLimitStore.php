<?php

declare(strict_types=1);

namespace Nora\Security;

use JsonException;
use RuntimeException;

final class FileRateLimitStore implements RateLimitStoreInterface
{
    private readonly string $directory;

    public function __construct(string $directory)
    {
        if ($directory === '') {
            throw new RateLimitStorageException('A rate-limit storage directory is required.');
        }

        $linkCheckPath = rtrim($directory, '/\\');
        if ($linkCheckPath !== '' && is_link($linkCheckPath)) {
            throw new RateLimitStorageException('The rate-limit storage path must not be a symbolic link.');
        }
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RateLimitStorageException('Unable to create the rate-limit storage directory.');
        }
        if (is_link($directory) || !is_dir($directory)) {
            throw new RateLimitStorageException('The rate-limit storage path must be a real directory.');
        }

        $realDirectory = realpath($directory);
        if ($realDirectory === false) {
            throw new RateLimitStorageException('Unable to resolve the rate-limit storage directory.');
        }
        if (DIRECTORY_SEPARATOR !== '\\' && !@chmod($realDirectory, 0700)) {
            throw new RateLimitStorageException('Unable to restrict rate-limit directory permissions.');
        }

        $this->directory = $realDirectory;
    }

    public function consume(
        string $bucketHash,
        int $limit,
        int $windowStart,
        int $resetAt,
    ): int {
        $this->validateBucket($bucketHash);
        if ($limit < 1 || $windowStart < 0 || $resetAt <= $windowStart) {
            throw new RateLimitStorageException('The rate-limit store received invalid window data.');
        }

        return $this->withLock(function () use ($bucketHash, $limit, $windowStart, $resetAt): int {
            $path = $this->bucketPath($bucketHash);
            $state = $this->readState($path);

            if ($state !== null && $state['window_start'] > $windowStart) {
                throw new RateLimitStorageException('The rate-limit clock moved backwards.');
            }
            if (
                $state === null
                || $state['window_start'] !== $windowStart
                || $state['reset_at'] !== $resetAt
            ) {
                $count = 1;
            } else {
                $count = $state['count'] >= $limit + 1
                    ? $limit + 1
                    : $state['count'] + 1;
            }

            $this->writeState($path, [
                'window_start' => $windowStart,
                'reset_at' => $resetAt,
                'count' => $count,
            ]);

            return $count;
        });
    }

    public function cleanup(int $now): int
    {
        if ($now < 0) {
            throw new RateLimitStorageException('The cleanup timestamp is invalid.');
        }

        return $this->withLock(function () use ($now): int {
            $removed = 0;
            $paths = glob($this->directory . DIRECTORY_SEPARATOR . 'bucket-*.json');
            if ($paths === false) {
                throw new RateLimitStorageException('Unable to inspect rate-limit bucket files.');
            }

            foreach ($paths as $path) {
                $name = basename($path);
                if (
                    preg_match('/\Abucket-[a-f0-9]{64}\.json\z/D', $name) !== 1
                    || is_link($path)
                    || !is_file($path)
                ) {
                    continue;
                }

                $state = $this->readState($path);
                if ($state !== null && $state['reset_at'] <= $now) {
                    if (!@unlink($path)) {
                        throw new RateLimitStorageException('Unable to remove an expired rate-limit bucket.');
                    }
                    $removed++;
                }
            }

            $temporaryPaths = glob($this->directory . DIRECTORY_SEPARATOR . '.nora-rate-*.tmp');
            if ($temporaryPaths === false) {
                throw new RateLimitStorageException('Unable to inspect rate-limit temporary files.');
            }
            foreach ($temporaryPaths as $path) {
                if (
                    preg_match('/\A\.nora-rate-[a-f0-9]{32}\.tmp\z/D', basename($path)) !== 1
                    || is_link($path)
                    || !is_file($path)
                ) {
                    continue;
                }
                $modifiedAt = @filemtime($path);
                if ($modifiedAt !== false && $modifiedAt < $now - 86_400 && !@unlink($path)) {
                    throw new RateLimitStorageException('Unable to remove an abandoned rate-limit temporary file.');
                }
            }

            return $removed;
        });
    }

    /**
     * @param callable(): int $operation
     */
    private function withLock(callable $operation): int
    {
        $lockPath = $this->directory . DIRECTORY_SEPARATOR . 'limiter.lock';
        if (is_link($lockPath)) {
            throw new RateLimitStorageException('The rate-limit lock path must not be a symbolic link.');
        }

        $lock = @fopen($lockPath, 'c+b');
        if ($lock === false) {
            throw new RateLimitStorageException('Unable to open the rate-limit storage lock.');
        }

        if (DIRECTORY_SEPARATOR !== '\\' && !@chmod($lockPath, 0600)) {
            fclose($lock);
            throw new RateLimitStorageException('Unable to restrict rate-limit lock permissions.');
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RateLimitStorageException('Unable to lock rate-limit storage.');
            }

            return $operation();
        } catch (RateLimitStorageException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            throw new RateLimitStorageException('Rate-limit storage operation failed.', 0, $exception);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @return array{window_start: int, reset_at: int, count: int}|null
     */
    private function readState(string $path): ?array
    {
        if (is_link($path)) {
            throw new RateLimitStorageException('A rate-limit bucket path must not be a symbolic link.');
        }
        if (!file_exists($path)) {
            return null;
        }
        if (!is_file($path)) {
            throw new RateLimitStorageException('A rate-limit bucket path is not a regular file.');
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new RateLimitStorageException('Unable to read a rate-limit bucket.');
        }

        try {
            $state = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RateLimitStorageException('A rate-limit bucket is corrupt.', 0, $exception);
        }

        if (
            !is_array($state)
            || !isset($state['window_start'], $state['reset_at'], $state['count'])
            || !is_int($state['window_start'])
            || !is_int($state['reset_at'])
            || !is_int($state['count'])
            || $state['window_start'] < 0
            || $state['reset_at'] <= $state['window_start']
            || $state['count'] < 1
        ) {
            throw new RateLimitStorageException('A rate-limit bucket has an invalid structure.');
        }

        return [
            'window_start' => $state['window_start'],
            'reset_at' => $state['reset_at'],
            'count' => $state['count'],
        ];
    }

    /**
     * @param array{window_start: int, reset_at: int, count: int} $state
     */
    private function writeState(string $path, array $state): void
    {
        try {
            $contents = json_encode($state, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RateLimitStorageException('Unable to encode a rate-limit bucket.', 0, $exception);
        }

        $temporaryPath = $this->directory
            . DIRECTORY_SEPARATOR
            . '.nora-rate-'
            . bin2hex(random_bytes(16))
            . '.tmp';
        $stream = @fopen($temporaryPath, 'x+b');
        if ($stream === false) {
            throw new RateLimitStorageException('Unable to create a temporary rate-limit bucket.');
        }

        $renamed = false;
        try {
            if (DIRECTORY_SEPARATOR !== '\\' && !@chmod($temporaryPath, 0600)) {
                throw new RateLimitStorageException('Unable to restrict rate-limit bucket permissions.');
            }
            $written = 0;
            $length = strlen($contents);
            while ($written < $length) {
                $count = fwrite($stream, substr($contents, $written));
                if ($count === false || $count === 0) {
                    throw new RateLimitStorageException('Unable to write a rate-limit bucket.');
                }
                $written += $count;
            }
            if (!fflush($stream)) {
                throw new RateLimitStorageException('Unable to flush a rate-limit bucket.');
            }
            if (!fclose($stream)) {
                $stream = null;
                throw new RateLimitStorageException('Unable to close a rate-limit bucket.');
            }
            $stream = null;

            if (is_link($path) || !@rename($temporaryPath, $path)) {
                throw new RateLimitStorageException('Unable to replace a rate-limit bucket.');
            }
            $renamed = true;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (!$renamed && file_exists($temporaryPath) && !@unlink($temporaryPath)) {
                throw new RateLimitStorageException('Unable to remove a temporary rate-limit bucket.');
            }
        }
    }

    private function bucketPath(string $bucketHash): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . 'bucket-' . $bucketHash . '.json';
    }

    private function validateBucket(string $bucketHash): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $bucketHash) !== 1) {
            throw new RateLimitStorageException('The rate-limit bucket identifier is invalid.');
        }
    }
}
