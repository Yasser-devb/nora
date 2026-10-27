<?php

declare(strict_types=1);

namespace Nora\Tests\Unit;

use Nora\Http\Request;
use Nora\Http\Response;
use Nora\Routing\MiddlewareInterface;
use Nora\Security\AuthorizationException;
use Nora\Security\AuthenticatableInterface;
use Nora\Security\CsrfMiddleware;
use Nora\Security\CsrfTokenManager;
use Nora\Security\Gate;
use Nora\Security\PasswordHasher;
use Nora\Security\SessionAuthenticator;
use Nora\Security\SessionManager;
use Nora\Security\SessionMiddleware;
use Nora\Security\SessionStoreInterface;
use PHPUnit\Framework\TestCase;

final class SecurityTest extends TestCase
{
    public function testSessionAuthenticatorRegeneratesIdAndResolvesUser(): void
    {
        $session = new ArraySessionStore();
        $tokens = new CsrfTokenManager($session);
        $oldToken = $tokens->token();
        $user = new TestIdentity('user-1');
        $auth = new SessionAuthenticator(
            $session,
            static fn (string $identifier): ?AuthenticatableInterface => $identifier === 'user-1' ? $user : null,
        );

        self::assertNull($auth->user());
        $auth->login($user);
        self::assertSame(1, $session->regenerations);
        self::assertFalse($tokens->isValid($oldToken));
        $authenticatedToken = $tokens->token();
        self::assertSame($user, $auth->user());
        $auth->logout();
        self::assertNull($auth->user());
        self::assertFalse($tokens->isValid($authenticatedToken));
        self::assertSame(2, $session->regenerations);
    }

    public function testGateDeniesByDefaultAndAuthorizesExplicitAbilities(): void
    {
        $user = new TestIdentity('admin');
        $gate = new Gate();
        self::assertFalse($gate->allows($user, 'post.update'));
        $gate->define('post.update', static fn (AuthenticatableInterface $user, mixed $post): bool =>
            $user->authIdentifier() === 'admin' && $post === 4
        );

        self::assertTrue($gate->allows($user, 'post.update', 4));
        $this->expectException(AuthorizationException::class);
        $gate->authorize($user, 'post.update', 5);
    }

    public function testCsrfMiddlewareProtectsUnsafeMethodsAndAllowsValidFormToken(): void
    {
        $session = new ArraySessionStore();
        $tokens = new CsrfTokenManager($session);
        $token = $tokens->token();
        $middleware = new CsrfMiddleware($tokens);
        $destination = static fn (): Response => Response::text('accepted');

        self::assertSame(403, $middleware->process(
            Request::fromUri('POST', '/submit'),
            $destination,
        )->status());
        self::assertSame(200, $middleware->process(
            Request::fromUri(
                'POST',
                '/submit',
                ['Content-Type' => 'application/x-www-form-urlencoded'],
                '_csrf=' . urlencode($token),
            ),
            $destination,
        )->status());
        self::assertSame(200, $middleware->process(
            Request::fromUri('GET', '/submit'),
            $destination,
        )->status());
    }

    public function testCsrfTokensAreRandomAndComparedSafely(): void
    {
        $tokens = new CsrfTokenManager(new ArraySessionStore());

        $first = $tokens->token();
        self::assertSame(64, strlen($first));
        self::assertSame($first, $tokens->token());
        self::assertTrue($tokens->isValid($first));
        self::assertFalse($tokens->isValid(substr($first, 1)));
    }

    public function testPasswordHasherUsesNativePasswordApi(): void
    {
        $hasher = new PasswordHasher();
        $hash = $hasher->hash('correct horse battery staple');

        self::assertNotSame('correct horse battery staple', $hash);
        self::assertTrue($hasher->verify('correct horse battery staple', $hash));
        self::assertFalse($hasher->verify('wrong password', $hash));
    }

    public function testNativeSessionUsesSecureCookieDefaults(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            self::markTestSkipped('A PHP session is already active.');
        }

        $savePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nora-session-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($savePath));
        $previousSavePath = session_save_path();
        session_save_path($savePath);
        session_id('');
        $manager = new SessionManager();

        try {
            $manager->start();
            $params = session_get_cookie_params();
            self::assertTrue($params['httponly']);
            self::assertTrue($params['secure']);
            self::assertSame('Lax', $params['samesite']);
            $manager->set('test.value', 'stored');
            self::assertSame('stored', $manager->get('test.value'));
            $manager->destroy();
        } finally {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_destroy();
            }
            session_save_path($previousSavePath);
            foreach (glob($savePath . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($savePath);
        }
    }

    public function testSessionMiddlewareStartsAndClosesSessionAroundRequest(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            self::markTestSkipped('A PHP session is already active.');
        }

        $savePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nora-session-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($savePath));
        $previousSavePath = session_save_path();
        session_save_path($savePath);
        session_id('');
        $session = new SessionManager(false);
        $app = new \Nora\Core\Application();
        $app->middleware(new SessionMiddleware($session));
        $app->route('GET', '/', static function () use ($session): \Nora\Http\Response {
            $session->set('middleware.value', 'stored');

            return \Nora\Http\Response::text('ok');
        });

        try {
            self::assertSame(200, $app->handle(Request::fromUri('GET', '/'))->status());
            self::assertSame(PHP_SESSION_NONE, session_status());
            $session->start();
            self::assertSame('stored', $session->get('middleware.value'));
            $session->destroy();
        } finally {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_destroy();
            }
            session_save_path($previousSavePath);
            foreach (glob($savePath . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($savePath);
        }
    }
}

/**
 * @internal
 */
final class ArraySessionStore implements SessionStoreInterface
{
    /** @var array<string, mixed> */
    private array $values = [];

    public int $regenerations = 0;

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
        $this->regenerations++;
    }
}

/**
 * @internal
 */
final class TestIdentity implements AuthenticatableInterface
{
    public function __construct(private readonly string $identifier)
    {
    }

    public function authIdentifier(): string
    {
        return $this->identifier;
    }
}
