<?php

declare(strict_types=1);

namespace Nora\Security;

use RuntimeException;

final class SessionManager implements SessionStoreInterface
{
    public function __construct(private readonly bool $secureCookies = true)
    {
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if (session_status() !== PHP_SESSION_NONE) {
            throw new RuntimeException('A PHP session cannot be started in the current state.');
        }

        if (ini_set('session.use_strict_mode', '1') === false
            || ini_set('session.use_only_cookies', '1') === false
            || ini_set('session.use_trans_sid', '0') === false
        ) {
            throw new RuntimeException('Unable to apply secure PHP session settings.');
        }

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $this->secureCookies,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        if (!session_start()) {
            throw new RuntimeException('Unable to start the PHP session.');
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->assertStarted();

        return $_SESSION[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        $this->assertStarted();

        return array_key_exists($key, $_SESSION);
    }

    public function set(string $key, mixed $value): void
    {
        $this->assertStarted();
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        $this->assertStarted();
        unset($_SESSION[$key]);
    }

    public function regenerateId(): void
    {
        $this->assertStarted();
        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Unable to regenerate the session identifier.');
        }
    }

    public function close(): void
    {
        $this->assertStarted();
        if (!session_write_close()) {
            throw new RuntimeException('Unable to close the PHP session.');
        }
    }

    public function destroy(): void
    {
        $this->assertStarted();
        $_SESSION = [];
        if (!session_destroy()) {
            throw new RuntimeException('Unable to destroy the PHP session.');
        }

        $params = session_get_cookie_params();
        $expired = setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'],
        ]);
        if (!$expired) {
            throw new RuntimeException('Unable to expire the PHP session cookie.');
        }
    }

    private function assertStarted(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('Start the session before accessing session data.');
        }
    }
}
