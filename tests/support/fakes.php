<?php
// In-memory stand-ins for Supabase Auth, PostgREST and SMTP, shared by the PHP unit tests.
declare(strict_types=1);

use App\Services\AuthProvider;
use App\Services\CodeMailer;
use App\Services\Gateway;
use App\Services\SupabaseException;

$checks = 0;
function check(bool $condition, string $message): void { global $checks; if (!$condition) throw new RuntimeException('FAILED: '.$message); $checks++; }

final class FakeMailer implements CodeMailer
{
    public array $sent = [];
    public array $invites = [];
    public bool $fail = false;
    public function isConfigured(): bool { return true; }
    public function sendVerificationCode(string $to, string $name, string $code): void
    {
        if ($this->fail) throw new RuntimeException('SMTP Error: Could not authenticate.');
        $this->sent[] = ['to' => $to, 'name' => $name, 'code' => $code];
    }
    public function sendInvite(string $to, string $name, string $intro, string $link): void
    {
        if ($this->fail) throw new RuntimeException('SMTP Error: Could not authenticate.');
        $this->invites[] = ['to' => $to, 'name' => $name, 'intro' => $intro, 'link' => $link];
    }
    public function lastCode(): string { return end($this->sent)['code']; }
}

/** Accounts keyed by email: id, email, password, user_metadata, roles, stations. */
final class FakeAuth implements AuthProvider
{
    public array $users = [];
    public array $profiles = [];
    public array $signedOut = [];
    public array $links = [];
    public function isConfigured(): bool { return true; }
    public function canCreateUsers(): bool { return true; }
    public function createConfirmedUser(string $email, string $password, array $metadata): array
    {
        if (isset($this->users[$email])) throw new SupabaseException('Supabase request failed with HTTP 422.', 422, 'email_exists');
        return $this->users[$email] = ['id' => 'user-'.count($this->users), 'email' => $email, 'password' => $password, 'user_metadata' => $metadata, 'roles' => ['customer'], 'stations' => []];
    }
    public function signInWithPassword(string $email, string $password): array
    {
        $user = $this->users[$email] ?? null;
        if ($user === null || $user['password'] === null || $user['password'] !== $password) throw new SupabaseException('Supabase request failed with HTTP 400.', 400, 'invalid_credentials');
        return $this->session($user);
    }
    public function refreshSession(string $refreshToken): array { return $this->session($this->find('refresh-', $refreshToken)); }
    public function setUserPassword(string $userId, string $password): void { foreach ($this->users as $email => $user) if ($user['id'] === $userId) $this->users[$email]['password'] = $password; }
    public function deleteUser(string $userId): void { $this->users = array_filter($this->users, static fn (array $user): bool => $user['id'] !== $userId); }
    public function saveDisplayName(string $userId, string $name, string $accessToken): void { $this->profiles[$userId] = $name; }
    public function signOut(string $accessToken): void { $this->signedOut[] = $accessToken; }
    public function access(string $accessToken): array
    {
        $user = $this->find('token-', $accessToken);
        return ['roles' => $user['roles'], 'stations' => $user['stations']];
    }
    public function inviteLink(string $email, array $metadata): array
    {
        $type = isset($this->users[$email]) ? 'recovery' : 'invite';
        $this->users[$email] ??= ['id' => 'user-'.count($this->users), 'email' => $email, 'password' => null, 'user_metadata' => $metadata, 'roles' => ['customer'], 'stations' => []];
        $hash = 'hash'.str_repeat('a', 30).count($this->links);
        $this->links[$hash] = $email;
        return ['user_id' => $this->users[$email]['id'], 'token_hash' => $hash, 'type' => $type];
    }
    public function verifyLink(string $type, string $tokenHash): array
    {
        $email = $this->links[$tokenHash] ?? throw new SupabaseException('Supabase request failed with HTTP 403.', 403, 'otp_expired');
        unset($this->links[$tokenHash]);
        return $this->session($this->users[$email]);
    }
    public function updatePassword(string $accessToken, string $password): void
    {
        $this->users[$this->find('token-', $accessToken)['email']]['password'] = $password;
    }
    public function grant(string $email, string $role, ?array $station = null): void
    {
        $this->users[$email]['roles'][] = $role;
        if ($station !== null) $this->users[$email]['stations'][] = $station;
    }
    private function session(array $user): array
    {
        return ['access_token' => 'token-'.$user['id'], 'refresh_token' => 'refresh-'.$user['id'], 'expires_at' => 2_000_000_000,
                'user' => ['id' => $user['id'], 'email' => $user['email'], 'user_metadata' => $user['user_metadata']]];
    }
    private function find(string $prefix, string $token): array
    {
        foreach ($this->users as $user) if ($prefix.$user['id'] === $token) return $user;
        throw new SupabaseException('Supabase request failed with HTTP 401.', 401, 'bad_jwt');
    }
}

/** Records every PostgREST call; select() applies simple eq. filters to canned rows. */
final class FakeGateway implements Gateway
{
    public array $calls = [];
    public array $tables = [];
    public array $results = [];
    public array $errors = [];
    public function select(string $table, array $query = [], ?string $accessToken = null): array
    {
        $this->calls[] = ['select', $table, $query, $accessToken];
        if (isset($this->errors[$table])) throw $this->errors[$table];
        return array_values(array_filter($this->tables[$table] ?? [], static function (array $row) use ($query): bool {
            foreach ($query as $column => $filter) if (is_string($filter) && str_starts_with($filter, 'eq.') && (string) ($row[$column] ?? '') !== substr($filter, 3)) return false;
            return true;
        }));
    }
    public function rpc(string $function, array $parameters, ?string $accessToken): array
    {
        $this->calls[] = ['rpc', $function, $parameters, $accessToken];
        if (isset($this->errors[$function])) throw $this->errors[$function];
        return $this->results[$function] ?? [];
    }
    public function adminRpc(string $function, array $parameters): array
    {
        $this->calls[] = ['admin', $function, $parameters, null];
        if (isset($this->errors[$function])) throw $this->errors[$function];
        return [];
    }
    public function called(string $kind, string $name): array
    {
        return array_values(array_filter($this->calls, static fn (array $call): bool => $call[0] === $kind && $call[1] === $name));
    }
}
