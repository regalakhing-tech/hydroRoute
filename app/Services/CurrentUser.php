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
    /** True after a refresh failed for a temporary reason (outage, rate limit): the login is kept, but there is no token right now. */
    public bool $unavailable = false;

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
        } catch (SupabaseException $error) {
            // Only a refusal (revoked or invalid refresh token) ends the login; an outage or 5xx keeps it for the next request.
            if ($error->status >= 400 && $error->status < 500 && $error->status !== 429) Session::logout();
            else $this->unavailable = true;
            return null;
        } catch (Throwable) {
            $this->unavailable = true; // network failure: keep the session, so a remembered station device is not signed out by a blip
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
