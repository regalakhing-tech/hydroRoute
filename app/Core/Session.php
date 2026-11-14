<?php

declare(strict_types=1);

namespace App\Core;

/** Thin wrapper over $_SESSION so controllers and tests share one shape. */
final class Session
{
    /** A null store keeps native file sessions (local development); Vercel instances need a shared store. */
    public static function start(bool $secure, ?\SessionHandlerInterface $store = null): void
    {
        if (PHP_SAPI === 'cli' || session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', '28800');
        session_name('hydroroute_session');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
        if ($store !== null) {
            session_set_save_handler($store, true);
        }
        session_start();
    }

    public static function csrfToken(): string
    {
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function validCsrf(mixed $token): bool
    {
        return is_string($token) && is_string($_SESSION['csrf'] ?? null) && hash_equals($_SESSION['csrf'], $token);
    }

    /** Type is a SweetAlert2 icon: success, error, warning or info. */
    public static function flash(string $type, string $message): void
    {
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    }

    public static function takeFlash(): ?array
    {
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        return is_array($flash) ? $flash : null;
    }

    /** Non-secret form values to refill after a redirect; never pass passwords or codes. */
    public static function keepInput(array $input): void
    {
        $_SESSION['old'] = $input;
    }

    public static function takeInput(): array
    {
        $old = $_SESSION['old'] ?? [];
        unset($_SESSION['old']);
        return is_array($old) ? $old : [];
    }

    public static function user(): ?array
    {
        return is_array($_SESSION['user'] ?? null) ? $_SESSION['user'] : null;
    }

    public static function login(array $user, array $tokens): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['user'] = $user;
        $_SESSION['auth'] = $tokens;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }
}
