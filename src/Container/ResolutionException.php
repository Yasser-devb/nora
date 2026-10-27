<?php

declare(strict_types=1);

namespace Nora\Container;

use RuntimeException;
use Throwable;

final class ResolutionException extends RuntimeException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
