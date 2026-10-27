<?php

declare(strict_types=1);

namespace Nora\Http;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class Request
{
    /**
     * @param array<string, string|string[]> $headers
     * @param array<string, mixed>|null $query
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $headers = [],
        private readonly string $body = '',
        private readonly ?array $query = null,
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

        return new self(strtoupper($method), $path, $headers, $body, $query);
    }

    /**
     * Create a request from PHP's server variables.
     *
     * @param array<string, mixed>|null $server
     * @param array<string, string|string[]>|null $headers
     */
    public static function fromGlobals(
        ?array $server = null,
        ?array $headers = null,
        ?string $body = null,
    ): self {
        $server ??= $_SERVER;
        $method = $server['REQUEST_METHOD'] ?? null;
        $uri = $server['REQUEST_URI'] ?? null;

        if (!is_string($method) || !is_string($uri)) {
            throw new RuntimeException('The PHP request method or URI is unavailable.');
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
        }

        if ($body === null) {
            $contents = file_get_contents('php://input');
            if ($contents === false) {
                throw new RuntimeException('Unable to read the HTTP request body.');
            }
            $body = $contents;
        }

        return self::fromUri($method, $uri, $headers, $body);
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
}
