<?php

declare(strict_types=1);

namespace Nora\Http;

final class UploadException extends HttpException
{
    public function __construct(string $message, int $status = 400)
    {
        parent::__construct($status, $message);
    }
}
