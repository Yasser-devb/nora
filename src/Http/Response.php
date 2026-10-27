<?php

declare(strict_types=1);

namespace Nora\Http;

use InvalidArgumentException;
use JsonException;

final class Response
{
    /**
     * @param array<string, string|string[]> $headers
     */
    public function __construct(
        private readonly string $body = '',
        private readonly int $status = 200,
        private readonly array $headers = [],
    ) {
        if ($status < 100 || $status > 599) {
            throw new InvalidArgumentException('The HTTP status code must be between 100 and 599.');
        }

        foreach ($headers as $name => $value) {
            if (preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $name) !== 1) {
                throw new InvalidArgumentException('An HTTP header name is invalid.');
            }

            foreach ((array) $value as $headerValue) {
                if (str_contains($headerValue, "\r") || str_contains($headerValue, "\n")) {
                    throw new InvalidArgumentException('An HTTP header value contains an invalid line break.');
                }
            }
        }
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /**
     * @param array<string, mixed> $data
     * @throws JsonException
     */
    public static function json(array $data, int $status = 200): self
    {
        return new self(
            json_encode($data, JSON_THROW_ON_ERROR),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }

    public function body(): string
    {
        return $this->body;
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, string|string[]>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function withHeader(string $name, string|array $value): self
    {
        $headers = [];
        foreach ($this->headers as $existingName => $existingValue) {
            if (strcasecmp($existingName, $name) !== 0) {
                $headers[$existingName] = $existingValue;
            }
        }
        $headers[$name] = $value;

        return new self($this->body, $this->status, $headers);
    }

    public function send(): void
    {
        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            foreach ((array) $value as $headerValue) {
                header($name . ': ' . $headerValue, false);
            }
        }

        echo $this->body;
    }
}
