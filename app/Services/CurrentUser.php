<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Session;
use Closure;
use Throwable;

/** The logged-in user's Supabase access token, refreshed shortly before it expires. */
final class CurrentUser
{
    private readonly Closure $clock;

    public function __construct(private readonly AuthProvider $auth, ?Closure $clock = null)
    {
        $this->clock = $clock ?? time(...);
    }

    /** Null when nobody is logged in or the session can no longer be refreshed (the user is logged out). */
    public function token(): ?string
    {
        $tokens = $_SESSION['auth'] ?? null;
        if (!is_array($tokens) || !is_string($tokens['access_token'] ?? null)) {
            return null;
        }
        if ((int) ($tokens['expires_at'] ?? 0) > ($this->clock)() + 60) {
            return $tokens['access_token'];
        }
        try {
            $session = $this->auth->refreshSession((string) ($tokens['refresh_token'] ?? ''));
        } catch (Throwable) {
            Session::logout();
            return null;
        }
        $_SESSION['auth'] = [
            'access_token' => $session['access_token'],
            'refresh_token' => (string) ($session['refresh_token'] ?? $tokens['refresh_token']),
            'expires_at' => (int) ($session['expires_at'] ?? 0),
        ];

        return $session['access_token'];
    }
}
