<?php

declare(strict_types=1);

namespace App\Core;

/** Thin wrapper over $_SESSION so controllers and tests share one shape. */
final class Session
{
    /** Normal sessions end after 8 hours without a save or when the browser closes. */
    public const TTL = 28800;
    /** A station admin's trusted device stays signed in for 30 days, renewed while it is used. */
    public const REMEMBER_TTL = 2592000;
    private static bool $secure = false;

    /** A null store keeps native file sessions (local development); Vercel instances need a shared store. */
    public static function start(bool $secure, ?\SessionHandlerInterface $store = null): void
    {
        if (PHP_SAPI === 'cli' || session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        self::$secure = $secure;
        ini_set('session.use_strict_mode', '1');
        // shortcut: native file sessions (local only) are swept after 8 hours even when remembered; the shared store honours ttl().
        ini_set('session.gc_maxlifetime', (string) self::TTL);
        session_name('hydroroute_session');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
        if ($store !== null) {
            session_set_save_handler($store, true);
        }
        session_start();
        self::renewRemember();
    }

    /** Seconds the shared store keeps this session: 30 days on a remembered station device, otherwise 8 hours. */
    public static function ttl(): int
    {
        $until = (int) ($_SESSION['remember_until'] ?? 0);
        return $until > time() ? $until - time() : self::TTL;
    }

    /** After a station admin logs in with "keep me signed in": a persistent cookie and a 30-day store lifetime. */
    public static function remember(): void
    {
        $_SESSION['remember_until'] = time() + self::REMEMBER_TTL;
        self::sendCookie($_SESSION['remember_until']);
    }

    /** Slides a remembered session at most once a day; drops the flag once it lapses or the account is not a station admin. */
    private static function renewRemember(): void
    {
        $until = (int) ($_SESSION['remember_until'] ?? 0);
        if ($until === 0) return;
        if ($until <= time() || (self::user()['role'] ?? null) !== 'station-admin') {
            unset($_SESSION['remember_until']);
            return;
        }
        if ($until - time() < self::REMEMBER_TTL - 86400) self::remember();
    }

    private static function sendCookie(int $expires): void
    {
        if (PHP_SAPI === 'cli' || headers_sent() || session_status() !== PHP_SESSION_ACTIVE) return;
        setcookie(session_name(), (string) session_id(), ['expires' => $expires, 'path' => '/', 'secure' => self::$secure, 'httponly' => true, 'samesite' => 'Lax']);
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
