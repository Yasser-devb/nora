<?php

declare(strict_types=1);

namespace Nora\Http;

use RuntimeException;

class HttpException extends RuntimeException
{
    public function __construct(
        private readonly int $status,
        string $message = '',
    ) {
        if ($status < 400 || $status > 599) {
            throw new \InvalidArgumentException('HTTP exceptions must use an error status code.');
        }

        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}
