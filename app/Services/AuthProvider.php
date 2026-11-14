<?php

declare(strict_types=1);

namespace App\Services;

/** What the login and account flows need from Supabase Auth; tests swap in a fake. */
interface AuthProvider
{
    public function isConfigured(): bool;

    public function canCreateUsers(): bool;

    /** @return array{access_token: string, refresh_token?: string, expires_at?: int, user: array} */
    public function signInWithPassword(string $email, string $password): array;

    public function refreshSession(string $refreshToken): array;

    public function createConfirmedUser(string $email, string $password, array $metadata): array;

    /** Server-only cleanup of an account this server just created. */
    public function deleteUser(string $userId): void;

    /** Server-only: a station admin resets the password of one of their own drivers. */
    public function setUserPassword(string $userId, string $password): void;

    public function saveDisplayName(string $userId, string $name, string $accessToken): void;

    public function signOut(string $accessToken): void;

    /** @return array{roles: list<string>, stations: list<array{id: string, name: string, slug: ?string}>} */
    public function access(string $accessToken): array;

    /**
     * Creates the account if needed and returns a one-time set-password token; sends no email itself.
     * @return array{user_id: string, token_hash: string, type: 'invite'|'recovery'}
     */
    public function inviteLink(string $email, array $metadata): array;

    /** Exchanges a set-password token for a session. */
    public function verifyLink(string $type, string $tokenHash): array;

    public function updatePassword(string $accessToken, string $password): void;
}
