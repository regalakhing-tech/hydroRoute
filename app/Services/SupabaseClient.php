<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

final class SupabaseClient implements AuthProvider, Gateway
{
    public function __construct(private readonly array $config)
    {
    }

    public function isConfigured(): bool
    {
        return $this->config['url'] !== '' && $this->config['publishable_key'] !== '';
    }

    public function canCreateUsers(): bool
    {
        return $this->isConfigured() && ($this->config['secret_key'] ?? '') !== '';
    }

    public function signInWithPassword(string $email, string $password): array
    {
        return $this->session($this->request('POST', '/auth/v1/token?grant_type=password', ['email' => $email, 'password' => $password], null));
    }

    /** Server-only: the secret key creates an already-confirmed user after our own email code check. */
    public function createConfirmedUser(string $email, string $password, array $metadata): array
    {
        return $this->request('POST', '/auth/v1/admin/users', [
            'email' => $email,
            'password' => $password,
            'email_confirm' => true,
            'user_metadata' => (object) $metadata, // empty PHP array must still encode as {}
        ], null, true);
    }

    public function deleteUser(string $userId): void
    {
        if (!preg_match('/^[0-9a-f-]{36}$/D', $userId)) {
            throw new InvalidArgumentException('Invalid user id.');
        }
        $this->request('DELETE', '/auth/v1/admin/users/' . $userId, null, null, true);
    }

    public function setUserPassword(string $userId, string $password): void
    {
        if (!preg_match('/^[0-9a-f-]{36}$/D', $userId)) {
            throw new InvalidArgumentException('Invalid user id.');
        }
        // Also confirms the email: drivers invited by link before passwords were typed may never have opened it.
        $this->request('PUT', '/auth/v1/admin/users/' . $userId, ['password' => $password, 'email_confirm' => true], null, true);
    }

    public function saveDisplayName(string $userId, string $name, string $accessToken): void
    {
        $this->request('PATCH', '/rest/v1/profiles?id=eq.' . rawurlencode($userId), ['display_name' => $name], $accessToken);
    }

    public function signOut(string $accessToken): void
    {
        $this->request('POST', '/auth/v1/logout', null, $accessToken);
    }

    public function select(string $table, array $query = [], ?string $accessToken = null): array
    {
        $this->validateIdentifier($table);
        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $path = '/rest/v1/' . $table . ($queryString === '' ? '' : '?' . $queryString);

        return $this->request('GET', $path, null, $accessToken);
    }

    public function rpc(string $function, array $parameters, ?string $accessToken): array
    {
        $this->validateIdentifier($function);
        if ($accessToken === '') {
            throw new InvalidArgumentException('An authenticated user token is required.');
        }

        return $this->request('POST', '/rest/v1/rpc/' . $function, $parameters, $accessToken);
    }

    public function adminRpc(string $function, array $parameters): array
    {
        $this->validateIdentifier($function);

        return $this->request('POST', '/rest/v1/rpc/' . $function, $parameters, null, true);
    }

    public function refreshSession(string $refreshToken): array
    {
        return $this->session($this->request('POST', '/auth/v1/token?grant_type=refresh_token', ['refresh_token' => $refreshToken], null));
    }

    public function access(string $accessToken): array
    {
        $access = $this->rpc('my_access', [], $accessToken);

        return ['roles' => array_values(array_filter((array) ($access['roles'] ?? []), 'is_string')), 'stations' => array_values((array) ($access['stations'] ?? []))];
    }

    public function inviteLink(string $email, array $metadata): array
    {
        try {
            $result = $this->request('POST', '/auth/v1/admin/generate_link', ['type' => 'invite', 'email' => $email, 'data' => (object) $metadata], null, true);
            $type = 'invite';
        } catch (SupabaseException $exception) {
            if (!in_array($exception->errorCode, ['email_exists', 'user_already_exists'], true)) {
                throw $exception;
            }
            // Existing account (for example a customer becoming a rider): a recovery token sets a new password.
            $result = $this->request('POST', '/auth/v1/admin/generate_link', ['type' => 'recovery', 'email' => $email], null, true);
            $type = 'recovery';
        }
        $hash = $result['hashed_token'] ?? $result['properties']['hashed_token'] ?? null;
        $userId = $result['id'] ?? $result['user']['id'] ?? null;
        if (!is_string($hash) || !is_string($userId)) {
            throw new RuntimeException('Supabase returned an incomplete invitation.');
        }

        return ['user_id' => $userId, 'token_hash' => $hash, 'type' => $type];
    }

    public function verifyLink(string $type, string $tokenHash): array
    {
        return $this->session($this->request('POST', '/auth/v1/verify', ['type' => $type, 'token_hash' => $tokenHash], null));
    }

    public function updatePassword(string $accessToken, string $password): void
    {
        $this->request('PUT', '/auth/v1/user', ['password' => $password], $accessToken);
    }

    private function session(array $result): array
    {
        if (!is_string($result['access_token'] ?? null) || !is_array($result['user'] ?? null)) {
            throw new RuntimeException('Supabase returned an incomplete session.');
        }

        return $result;
    }

    private function validateIdentifier(string $identifier): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/D', $identifier)) {
            throw new InvalidArgumentException('Invalid Supabase resource name.');
        }
    }

    private function request(string $method, string $path, ?array $body, ?string $accessToken, bool $admin = false): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Supabase is not configured.');
        }

        $base = parse_url($this->config['url']);
        if ($base === false || ($base['scheme'] ?? '') !== 'https'
            || empty($base['host']) || isset($base['user']) || isset($base['pass'])
            || isset($base['query']) || isset($base['fragment'])
            || !in_array($base['path'] ?? '', ['', '/'], true)) {
            throw new RuntimeException('Supabase requires an HTTPS project origin.');
        }

        $key = $admin ? (string) ($this->config['secret_key'] ?? '') : $this->config['publishable_key'];
        if (!str_starts_with($key, $admin ? 'sb_secret_' : 'sb_publishable_') || preg_match('/[\r\n]/', $key)) {
            throw new RuntimeException($admin ? 'Account creation needs a Supabase secret key.' : 'Use a Supabase publishable key for user requests.');
        }
        if ($admin && $accessToken !== null) {
            throw new InvalidArgumentException('Secret-key requests never carry a user token.');
        }
        if ($accessToken !== null && preg_match('/[\r\n]/', $accessToken)) {
            throw new InvalidArgumentException('Invalid access token.');
        }

        $headers = ['apikey: ' . $key, 'Accept: application/json'];
        if ($accessToken !== null) {
            $headers[] = 'Authorization: Bearer ' . $accessToken;
        }
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        $handle = curl_init($this->config['url'] . $path);
        if ($handle === false) {
            throw new RuntimeException('Unable to initialize the Supabase request.');
        }

        try {
            $options = [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => $this->config['timeout_seconds'],
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ];
            // The Vercel runtime can use an older libcurl than local PHP.
            if (defined('CURLOPT_PROTOCOLS_STR')) {
                $options[CURLOPT_PROTOCOLS_STR] = 'https';
            } else {
                $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
            }
            // Windows PHP can trust the OS store when no CA bundle is installed.
            if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
                $options[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
            }
            curl_setopt_array($handle, $options);
            if ($body !== null) {
                curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode((object) $body, JSON_THROW_ON_ERROR));
            }

            $result = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            if ($result === false) {
                throw new RuntimeException('Supabase is temporarily unreachable; a write outcome may be uncertain.');
            }
            if ($status < 200 || $status >= 300) {
                // Auth errors carry error_code (e.g. invalid_credentials, email_exists); PostgREST uses code.
                $error = json_decode($result, true);
                $code = is_array($error) ? ($error['error_code'] ?? $error['code'] ?? '') : '';
                throw new SupabaseException('Supabase request failed with HTTP ' . $status . '.', $status, is_string($code) ? $code : '');
            }
            if ($result === '') {
                return [];
            }

            $decoded = json_decode($result, true, 512, JSON_THROW_ON_ERROR);

            // RPCs that return a scalar (for example a new row's uuid) are wrapped so callers always get an array.
            return is_array($decoded) ? $decoded : ['value' => $decoded];
        } finally {
            // CurlHandle closes automatically when it leaves scope.
            unset($handle);
        }
    }
}
