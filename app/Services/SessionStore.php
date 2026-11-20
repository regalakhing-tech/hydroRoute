<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Session;
use SessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface;
use Throwable;

/**
 * PHP sessions kept in Supabase (public.app_sessions) so every Vercel instance sees the same login.
 * Rows are keyed by a SHA-256 of the cookie, so a database leak does not leak live session cookies.
 */
final class SessionStore implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    /** @var array{0: string, 1: string}|null last id read and its data; strict mode reads each id twice */
    private ?array $loaded = null;

    public function __construct(private readonly Gateway $db)
    {
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        if ($this->loaded !== null && $this->loaded[0] === $id) {
            return $this->loaded[1];
        }
        try {
            $data = $this->db->adminRpc('session_read', ['p_id' => hash('sha256', $id)])['value'] ?? null;
            $data = is_string($data) ? (string) base64_decode($data, true) : '';
        } catch (Throwable $exception) {
            // An unreachable store must not take every page down; the visitor is treated as logged out.
            error_log('Session read failed: ' . $exception::class);
            $data = '';
        }
        $this->loaded = [$id, $data];

        return $data;
    }

    public function write(string $id, string $data): bool
    {
        if ($data === '' && $this->loaded === [$id, '']) {
            return true; // new visitor with nothing to keep: no round trip
        }
        try {
            $this->db->adminRpc('session_write', [
                'p_id' => hash('sha256', $id),
                'p_data' => $data === '' ? '' : base64_encode($data),
                'p_ttl' => Session::ttl(),
            ]);
        } catch (Throwable $exception) {
            error_log('Session write failed: ' . $exception::class);
            return false;
        }
        $this->loaded = [$id, $data];

        return true;
    }

    public function destroy(string $id): bool
    {
        return $this->write($id, '');
    }

    public function gc(int $max_lifetime): int|false
    {
        return 0; // session_write clears expired rows
    }

    /** Strict mode: an unknown cookie gets a fresh id instead of being adopted. */
    public function validateId(string $id): bool
    {
        return $this->read($id) !== '';
    }

    /** Unchanged data skips the round trip; the expiry slides on each real write (login, token refresh, flash). */
    public function updateTimestamp(string $id, string $data): bool
    {
        return true;
    }
}
