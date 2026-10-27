<?php

declare(strict_types=1);

namespace Nora\Security;

use Nora\Http\HttpException;

final class AuthorizationException extends HttpException
{
    public function __construct(string $message = 'The requested action is not authorized.')
    {
        parent::__construct(403, $message);
    }
}
