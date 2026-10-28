<?php

declare(strict_types=1);

namespace Nora\Tests\Unit;

use Nora\Security\AccountProviderInterface;
use Nora\Security\AuthenticatableInterface;
use Nora\Security\AuthenticatorInterface;
use Nora\Security\PasswordAccountInterface;
use Nora\Security\PasswordHasher;
use Nora\Security\PasswordLoginService;
use Nora\Security\RateLimiter;
use Nora\Security\RateLimitStoreInterface;
use PHPUnit\Framework\TestCase;

final class PasswordLoginServiceTest extends TestCase
{
    public function testValidCredentialsLogInAndRotateThroughTheAuthenticator(): void
    {
        $account = new TestPasswordAccount('person-1', 'user@example.test', password_hash('correct', PASSWORD_DEFAULT), true);
        $accounts = new InMemoryAccountProvider($account);
        $authenticator = new RecordingAuthenticator();
        $service = new PasswordLoginService($accounts, $authenticator, new PasswordHasher(), $this->limiter());

        self::assertTrue($service->attempt('user@example.test', 'correct', 'login:client-1')->isAuthenticated());
        self::assertSame($account, $authenticator->loggedIn);
        self::assertSame([], $accounts->updatedHashes);
    }

    public function testMissingAccountAndWrongPasswordBothFailWithoutLoggingIn(): void
    {
        $accounts = new InMemoryAccountProvider(
            new TestPasswordAccount('person-1', 'user@example.test', password_hash('correct', PASSWORD_DEFAULT), true),
        );
        $authenticator = new RecordingAuthenticator();
        $service = new PasswordLoginService($accounts, $authenticator, new PasswordHasher(), $this->limiter());

        $missing = $service->attempt('missing@example.test', 'wrong', 'login:client-1');
        $wrongPassword = $service->attempt('user@example.test', 'wrong', 'login:client-1');

        self::assertFalse($missing->isAuthenticated());
        self::assertNull($missing->retryAfterSeconds());
        self::assertFalse($wrongPassword->isAuthenticated());
        self::assertNull($wrongPassword->retryAfterSeconds());
        self::assertNull($authenticator->loggedIn);
    }

    public function testDisabledAccountCannotAuthenticateEvenWithTheCorrectPassword(): void
    {
        $accounts = new InMemoryAccountProvider(
            new TestPasswordAccount('person-1', 'user@example.test', password_hash('correct', PASSWORD_DEFAULT), false),
        );
        $authenticator = new RecordingAuthenticator();
        $service = new PasswordLoginService($accounts, $authenticator, new PasswordHasher(), $this->limiter());

        self::assertFalse($service->attempt('user@example.test', 'correct', 'login:client-1')->isAuthenticated());
        self::assertNull($authenticator->loggedIn);
    }

    public function testRateLimitedAttemptsDoNotResolveAccountsOrStartSessions(): void
    {
        $accounts = new InMemoryAccountProvider();
        $authenticator = new RecordingAuthenticator();
        $service = new PasswordLoginService($accounts, $authenticator, new PasswordHasher(), $this->limiter(1));

        self::assertFalse($service->attempt('missing@example.test', 'wrong', 'login:client-1')->isAuthenticated());
        $throttled = $service->attempt('user@example.test', 'correct', 'login:client-1');
        self::assertFalse($throttled->isAuthenticated());
        self::assertSame(60, $throttled->retryAfterSeconds());
        self::assertSame(1, $accounts->lookups);
        self::assertNull($authenticator->loggedIn);
    }

    public function testSuccessfulLoginRehashesOutdatedPasswordHash(): void
    {
        $account = new TestPasswordAccount(
            'person-1',
            'user@example.test',
            password_hash('correct', PASSWORD_DEFAULT, ['cost' => 4]),
            true,
        );
        $accounts = new InMemoryAccountProvider($account);
        $authenticator = new RecordingAuthenticator();
        $service = new PasswordLoginService($accounts, $authenticator, new PasswordHasher(), $this->limiter());

        self::assertTrue($service->attempt('user@example.test', 'correct', 'login:client-1')->isAuthenticated());
        self::assertCount(1, $accounts->updatedHashes);
        self::assertTrue(password_verify('correct', $accounts->updatedHashes[0]));
    }

    private function limiter(int $limit = 10): RateLimiter
    {
        return new RateLimiter(new InMemoryRateLimitStore(), $limit, 60, static fn (): int => 120);
    }
}

/**
 * @internal
 */
final class InMemoryAccountProvider implements AccountProviderInterface
{
    /** @var list<string> */
    public array $updatedHashes = [];

    public int $lookups = 0;

    /** @var array<string, PasswordAccountInterface> */
    private array $accounts = [];

    public function __construct(PasswordAccountInterface ...$accounts)
    {
        foreach ($accounts as $account) {
            $this->accounts[$account->email()] = $account;
        }
    }

    public function findByEmail(string $email): ?PasswordAccountInterface
    {
        $this->lookups++;

        return $this->accounts[$email] ?? null;
    }

    public function updatePasswordHash(string $identifier, string $passwordHash): void
    {
        $this->updatedHashes[] = $passwordHash;
    }
}

/**
 * @internal
 */
final readonly class TestPasswordAccount implements PasswordAccountInterface
{
    public function __construct(
        private string $identifier,
        private string $emailAddress,
        private string $hash,
        private bool $enabled,
    ) {
    }

    public function authIdentifier(): string
    {
        return $this->identifier;
    }

    public function email(): string
    {
        return $this->emailAddress;
    }

    public function passwordHash(): string
    {
        return $this->hash;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}

/**
 * @internal
 */
final class RecordingAuthenticator implements AuthenticatorInterface
{
    public ?AuthenticatableInterface $loggedIn = null;

    public function user(): ?AuthenticatableInterface
    {
        return $this->loggedIn;
    }

    public function login(AuthenticatableInterface $user): void
    {
        $this->loggedIn = $user;
    }

    public function logout(): void
    {
        $this->loggedIn = null;
    }
}

/**
 * @internal
 */
final class InMemoryRateLimitStore implements RateLimitStoreInterface
{
    /** @var array<string, int> */
    private array $counts = [];

    public function consume(string $bucketHash, int $limit, int $windowStart, int $resetAt): int
    {
        return $this->counts[$bucketHash] = ($this->counts[$bucketHash] ?? 0) + 1;
    }

    public function cleanup(int $now): int
    {
        return 0;
    }
}
