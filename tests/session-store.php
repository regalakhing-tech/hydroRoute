<?php
// Sessions must survive a hop to another Vercel instance. Each "instance" below is a fresh SessionStore over one shared table.
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use App\Services\Gateway;
use App\Services\SessionStore;
$checks = 0;
function check(bool $condition, string $message): void { global $checks; if (!$condition) throw new RuntimeException($message); $checks++; }

/** Mirrors session_read / session_write from supabase/migrations/20261114130000_app_sessions.sql. */
final class SessionTable implements Gateway
{
    public array $rows = [];
    public bool $down = false;
    public int $writes = 0;
    public function select(string $table, array $query = [], ?string $accessToken = null): array { return []; }
    public function rpc(string $function, array $parameters, ?string $accessToken): array { return []; }
    public function adminRpc(string $function, array $parameters): array
    {
        if ($this->down) throw new RuntimeException('Supabase is temporarily unreachable.');
        if ($function === 'session_read') return ['value' => $this->rows[$parameters['p_id']] ?? null];
        $this->writes++;
        if ($parameters['p_data'] === '') unset($this->rows[$parameters['p_id']]);
        else $this->rows[$parameters['p_id']] = $parameters['p_data'];
        return [];
    }
}

ini_set('session.use_cookies', '0');
ini_set('session.use_strict_mode', '1');
$table = new SessionTable();
function request(SessionTable $table, ?string $id, callable $work): string
{
    $_SESSION = [];
    session_set_save_handler(new SessionStore($table), false);
    if ($id !== null) session_id($id);
    session_start();
    $work();
    $id = session_id();
    session_write_close();
    return $id;
}

$id = request($table, null, static function (): void { $_SESSION['csrf'] = 'token-1'; });
check(count($table->rows) === 1 && !isset($table->rows[$id]) && isset($table->rows[hash('sha256', $id)]), 'Row is keyed by a hash, never the raw cookie');
request($table, $id, static function (): void { check(($_SESSION['csrf'] ?? null) === 'token-1', 'Another instance sees the same session'); });

$writes = $table->writes;
request($table, null, static fn () => null);
check(count($table->rows) === 1 && $table->writes === $writes, 'Empty visitor sessions make no write call');

$planted = str_repeat('a', 32);
$fresh = request($table, $planted, static function (): void { $_SESSION['user'] = ['role' => 'customer']; });
check($fresh !== $planted && !isset($table->rows[hash('sha256', $planted)]), 'Strict mode replaces an unknown, attacker-chosen id');

$old = request($table, $id, static function (): void { $_SESSION['user'] = ['role' => 'customer']; session_regenerate_id(true); });
check($old !== $id && !isset($table->rows[hash('sha256', $id)]) && isset($table->rows[hash('sha256', $old)]), 'Login regeneration deletes the old row');

$table->down = true;
request($table, $old, static function (): void { check($_SESSION === [], 'Store outage reads as logged out instead of crashing'); });
$table->down = false;
request($table, $old, static function (): void { check(($_SESSION['user']['role'] ?? null) === 'customer', 'Data survives a failed request'); });

echo $checks . " session store checks passed.\n";
