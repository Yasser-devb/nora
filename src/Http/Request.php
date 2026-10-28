<?php

declare(strict_types=1);

namespace Nora\Http;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class Request
{
    public const DEFAULT_MAX_BODY_BYTES = 1_048_576;

    /**
     * @param array<string, string|string[]> $headers
     * @param array<string, mixed>|null $query
     * @param array<string, mixed> $files PHP upload entries, normally $_FILES
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $headers = [],
        private readonly string $body = '',
        private readonly ?array $query = null,
        private readonly array $files = [],
    ) {
        if ($method === '' || preg_match('/^[A-Za-z]+$/D', $method) !== 1) {
            throw new InvalidArgumentException('The HTTP method is invalid.');
        }

        if (
            $path === ''
            || $path[0] !== '/'
            || str_contains($path, "\0")
            || str_contains($path, '?')
            || str_contains($path, '#')
        ) {
            throw new InvalidArgumentException('The request path must be an absolute URL path.');
        }
    }

    public static function fromUri(
        string $method,
        string $uri,
        array $headers = [],
        string $body = '',
        array $files = [],
    ): self {
        $parts = parse_url($uri);

        if ($parts === false) {
            throw new InvalidArgumentException('The request URI is invalid.');
        }

        $path = is_array($parts) ? ($parts['path'] ?? '/') : '/';
        $queryString = is_array($parts) ? ($parts['query'] ?? null) : null;
        $query = [];

        if ($path === '') {
            $path = '/';
        }

        if (is_string($queryString)) {
            parse_str($queryString, $query);
        }

        return new self(strtoupper($method), $path, $headers, $body, $query, $files);
    }

    /**
     * Create a request from PHP's server variables.
     *
     * Read PHP's request-body stream with an enforced byte limit.
     *
     * @param array<string, mixed>|null $server
     * @param array<string, string|string[]>|null $headers
     * @param int $maxBodyBytes Maximum body size; defaults to 1 MiB.
     */
    public static function fromGlobals(
        ?array $server = null,
        ?array $headers = null,
        ?string $body = null,
        int $maxBodyBytes = self::DEFAULT_MAX_BODY_BYTES,
        ?array $files = null,
    ): self {
        $server ??= $_SERVER;
        if ($maxBodyBytes < 0 || $maxBodyBytes === PHP_INT_MAX) {
            throw new InvalidArgumentException('The maximum request body size is invalid.');
        }
        $method = $server['REQUEST_METHOD'] ?? null;
        $uri = $server['REQUEST_URI'] ?? null;

        if (!is_string($method) || !is_string($uri)) {
            throw new RuntimeException('The PHP request method or URI is unavailable.');
        }
        if (array_key_exists('CONTENT_LENGTH', $server) && !is_string($server['CONTENT_LENGTH'])) {
            throw new HttpException(400, 'The request content length is invalid.');
        }

        if ($headers === null) {
            $headers = [];
            foreach ($server as $key => $value) {
                if (!is_string($key) || !is_string($value) || !str_starts_with($key, 'HTTP_')) {
                    continue;
                }

                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$name] = $value;
            }

            foreach (['CONTENT_TYPE', 'CONTENT_LENGTH'] as $key) {
                if (isset($server[$key]) && is_string($server[$key])) {
                    $name = str_replace('_', '-', ucwords(strtolower($key), '_'));
                    $headers[$name] = $server[$key];
                }
            }
        } elseif (array_key_exists('CONTENT_LENGTH', $server)) {
            $hasContentLength = false;
            foreach ($headers as $name => $value) {
                if (!is_string($name) || strcasecmp($name, 'Content-Length') !== 0) {
                    continue;
                }
                if (
                    $hasContentLength
                    || !is_string($value)
                    || trim($value, " \t") !== trim($server['CONTENT_LENGTH'], " \t")
                ) {
                    throw new HttpException(400, 'The request content length is invalid.');
                }
                $hasContentLength = true;
            }
            if (!$hasContentLength) {
                $headers['Content-Length'] = $server['CONTENT_LENGTH'];
            }
        }

        $contentLength = self::validatedContentLength($headers, $maxBodyBytes);
        $isMultipart = preg_match(
            '/^\s*multipart\/form-data\s*(?:;|$)/i',
            self::headerValue($headers, 'Content-Type') ?? '',
        ) === 1;
        if ($body === null) {
            $body = $isMultipart ? '' : self::readBoundedBody($maxBodyBytes);
        }
        if (strlen($body) > $maxBodyBytes) {
            throw new HttpException(413, 'The request body exceeds the allowed size.');
        }
        if (!$isMultipart && $contentLength !== null && strlen($body) !== $contentLength) {
            throw new HttpException(400, 'The request body length does not match its content length.');
        }

        return self::fromUri($method, $uri, $headers, $body, $files ?? $_FILES);
    }

    /**
     * @param array<string, string|string[]> $headers
     */
    private static function validatedContentLength(array $headers, int $maxBodyBytes): ?int
    {
        $contentLength = null;
        foreach ($headers as $name => $value) {
            if (!is_string($name) || strcasecmp($name, 'Content-Length') !== 0) {
                continue;
            }
            if ($contentLength !== null || !is_string($value)) {
                throw new HttpException(400, 'The request content length is invalid.');
            }
            $contentLength = trim($value, " \t");
        }

        if ($contentLength === null) {
            return null;
        }
        if (preg_match('/^[0-9]+$/D', $contentLength) !== 1) {
            throw new HttpException(400, 'The request content length is invalid.');
        }

        $normalized = ltrim($contentLength, '0');
        $normalized = $normalized === '' ? '0' : $normalized;
        $maximum = (string) $maxBodyBytes;
        if (
            strlen($normalized) > strlen($maximum)
            || (strlen($normalized) === strlen($maximum) && strcmp($normalized, $maximum) > 0)
        ) {
            throw new HttpException(413, 'The request body exceeds the allowed size.');
        }

        return (int) $normalized;
    }

    /**
     * @param array<string, string|string[]> $headers
     */
    private static function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $headerName => $value) {
            if (is_string($headerName) && strcasecmp($headerName, $name) === 0) {
                return is_array($value) ? implode(', ', $value) : $value;
            }
        }

        return null;
    }

    private static function readBoundedBody(int $maxBodyBytes): string
    {
        $stream = fopen('php://input', 'rb');
        if ($stream === false) {
            throw new RuntimeException('Unable to read the HTTP request body.');
        }

        try {
            $body = '';
            while (true) {
                $remaining = $maxBodyBytes - strlen($body);
                $chunk = fread($stream, min(8192, $remaining + 1));
                if ($chunk === false) {
                    throw new RuntimeException('Unable to read the HTTP request body.');
                }
                if ($chunk === '') {
                    if (feof($stream)) {
                        break;
                    }
                    throw new RuntimeException('The HTTP request body stream stopped unexpectedly.');
                }
                if (strlen($chunk) > $remaining) {
                    throw new HttpException(413, 'The request body exceeds the allowed size.');
                }
                $body .= $chunk;
            }

            return $body;
        } finally {
            fclose($stream);
        }
    }

    public function method(): string
    {
        return strtoupper($this->method);
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return array<string, string|string[]>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $headerName => $value) {
            if (strcasecmp($headerName, $name) === 0) {
                return is_array($value) ? implode(', ', $value) : $value;
            }
        }

        return null;
    }

    public function body(): string
    {
        return $this->body;
    }

    /**
     * @return array<string, mixed>
     */
    public function query(): array
    {
        if ($this->query !== null) {
            return $this->query;
        }

        $query = parse_url($this->path, PHP_URL_QUERY);
        $parameters = [];

        if (is_string($query)) {
            parse_str($query, $parameters);
        }

        return $parameters;
    }

    /**
     * @return array<string, mixed>
     * @throws JsonException
     */
    public function json(): array
    {
        if ($this->body === '') {
            return [];
        }

        $decoded = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new JsonException('A JSON request body must decode to an object or array.');
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    public function form(): array
    {
        if (stripos($this->header('Content-Type') ?? '', 'application/x-www-form-urlencoded') !== 0) {
            return [];
        }

        $values = [];
        parse_str($this->body, $values);

        return $values;
    }

    /**
     * Return a single uploaded file field, or null when no file was selected.
     * Use files() for HTML fields that submit multiple files.
     */
    public function file(string $name): ?UploadedFile
    {
        $files = $this->files($name);
        if (count($files) > 1) {
            throw new InvalidArgumentException('The upload field contains multiple files; use files() instead.');
        }

        return $files[0] ?? null;
    }

    /**
     * Return the successfully received files for a PHP upload field.
     *
     * @return list<UploadedFile>
     */
    public function files(string $name): array
    {
        if (!array_key_exists($name, $this->files)) {
            return [];
        }
        $entry = $this->files[$name];
        if (!is_array($entry)) {
            throw new UploadException('The uploaded file metadata is invalid.');
        }
        foreach (['name', 'tmp_name', 'error', 'size'] as $requiredKey) {
            if (!array_key_exists($requiredKey, $entry)) {
                throw new UploadException('The uploaded file metadata is invalid.');
            }
        }

        $uploads = [];
        $this->collectUploadEntries($entry, $entry['name'], [], $uploads);

        return $uploads;
    }

    /**
     * @param array<string, mixed> $entry
     * @param list<int|string> $path
     * @param list<UploadedFile> $uploads
     */
    private function collectUploadEntries(array $entry, mixed $name, array $path, array &$uploads): void
    {
        if (is_array($name)) {
            foreach ($name as $key => $childName) {
                $this->collectUploadEntries($entry, $childName, [...$path, $key], $uploads);
            }

            return;
        }

        $metadata = ['name' => $name];
        foreach (['tmp_name', 'error', 'size'] as $key) {
            $value = $entry[$key];
            foreach ($path as $index) {
                if (!is_array($value) || !array_key_exists($index, $value)) {
                    throw new UploadException('The uploaded file metadata is inconsistent.');
                }
                $value = $value[$index];
            }
            $metadata[$key] = $value;
        }

        if (($metadata['error'] ?? null) === UPLOAD_ERR_NO_FILE) {
            return;
        }
        $uploads[] = UploadedFile::fromPhpArray($metadata);
    }
}
