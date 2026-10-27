<?php

declare(strict_types=1);

namespace Nora\Tests\Security;

use Nora\Core\Application;
use Nora\Http\Request;
use Nora\Http\Response;
use Nora\Routing\MiddlewareInterface;
use Nora\Security\CsrfMiddleware;
use Nora\Security\CsrfTokenManager;
use Nora\Security\SessionStoreInterface;
use PHPUnit\Framework\TestCase;

final class CsrfRegressionTest extends TestCase
{
    public function testStateChangingRouteRejectsMissingAndIncorrectTokens(): void
    {
        $session = new CsrfTestSession();
        $tokens = new CsrfTokenManager($session);
        $csrf = new CsrfMiddleware($tokens);
        $app = new Application();
        $app->middleware($csrf);
        $app->route('POST', '/transfer', static fn (): Response => Response::text('processed'));

        self::assertSame(403, $app->handle(Request::fromUri('POST', '/transfer'))->status());
        self::assertSame(
            403,
            $app->handle(Request::fromUri('POST', '/transfer', ['X-CSRF-Token' => 'attacker-token']))->status(),
        );

        $token = $tokens->token();
        self::assertSame(
            200,
            $app->handle(Request::fromUri('POST', '/transfer', ['X-CSRF-Token' => $token]))->status(),
        );
    }
}

/**
 * @internal
 */
final class CsrfTestSession implements SessionStoreInterface
{
    /** @var array<string, mixed> */
    private array $values = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->values[$key]);
    }

    public function regenerateId(): void
    {
    }
}
