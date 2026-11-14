<?php
// Live end-to-end check against the Supabase project in .env (needs the 20261114 migration applied):
// a business registers, a temporary Super Admin approves it, the owner sets a password and logs in,
// the owner adds a driver with a password, the driver logs in. No email is sent; everything is deleted after.
// Run: php tests/registry-live.php
declare(strict_types=1);
$config = require dirname(__DIR__).'/bootstrap.php';

use App\Services\CodeMailer;
use App\Services\Invitations;
use App\Services\Registry;
use App\Services\SupabaseClient;

$client = new SupabaseClient($config['supabase']);
if (!$client->canCreateUsers()) { fwrite(STDERR, "Set SUPABASE_SECRET_KEY in .env first.\n"); exit(1); }
$checks = 0;
function check(bool $condition, string $message): void { global $checks; if (!$condition) throw new RuntimeException('FAILED: '.$message); $checks++; echo "  ok  $message\n"; }

/** Captures set-password links instead of emailing them. */
final class CapturingMailer implements CodeMailer
{
    public array $links = [];
    public function isConfigured(): bool { return true; }
    public function sendVerificationCode(string $to, string $name, string $code): void {}
    public function sendInvite(string $to, string $name, string $intro, string $link): void { $this->links[$to] = $link; }
}

/** Service-role REST call for cleanup only. */
$admin = static function (string $method, string $path) use ($config): int {
    $handle = curl_init(rtrim($config['supabase']['url'], '/').$path);
    curl_setopt_array($handle, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => ['apikey: '.$config['supabase']['secret_key']], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_SSL_OPTIONS => CURLSSLOPT_NATIVE_CA]);
    curl_exec($handle);
    return (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
};

$mailbox = $config['mail']['username'] ?: 'hydroroute@example.com';
[$local, $domain] = explode('@', $mailbox, 2) + [1 => 'example.com'];
$tag = bin2hex(random_bytes(3));
$address = static fn (string $who): string => strtolower($local.'+hr-'.$who.'-'.$tag.'@'.$domain);
$mailer = new CapturingMailer();
$registry = new Registry($client);
$invitations = new Invitations($client, $registry, $mailer, 'http://localhost:8000');
$open = static function (string $email) use ($mailer, $client): array {
    parse_str((string) parse_url($mailer->links[$email], PHP_URL_QUERY), $link);
    $session = $client->verifyLink($link['type'], $link['token_hash']);
    $password = 'Live-'.bin2hex(random_bytes(6));
    $client->updatePassword($session['access_token'], $password);
    return $client->signInWithPassword($email, $password);
};
$users = [];
$registrationId = null;
$stationId = null;

try {
    check(count($client->select('stations', ['select' => 'slug', 'slug' => 'in.(guintas,poblacion,riverside)'])) === 3, 'the three seeded stations are public');

    $registrationId = $registry->submit(['station_name' => 'Live Test Water '.$tag, 'barangay' => 'Bia-an', 'address' => 'Purok 1, test only', 'station_phone' => '+639171112233',
        'station_email' => '', 'owner_name' => 'Live Owner', 'owner_email' => $address('owner'), 'owner_phone' => '+639181112233', 'owner_role' => 'Owner']);
    check(strlen($registrationId) === 36, 'visitor submits a station registration');
    try {
        $client->select('station_registrations', ['select' => 'id']);
        check(false, 'visitors cannot read registrations');
    } catch (App\Services\SupabaseException $exception) {
        check($exception->status === 401, 'visitors cannot read registrations (permission denied)');
    }

    check($invitations->platformAdmin($address('admin'), 'Live Registry Admin'), 'temporary Super Admin invited');
    $adminSession = $open($address('admin'));
    $users[] = $adminSession['user']['id'];
    check(in_array('super_admin', $client->access($adminSession['access_token'])['roles'], true), 'Super Admin sets a password and logs in');
    $pending = $registry->registrations($adminSession['access_token']);
    check(in_array($registrationId, array_column($pending, 'id'), true), 'Super Admin sees the pending registration');

    $result = $registry->review($adminSession['access_token'], $registrationId, 'approve', null);
    $stationId = $result['station_id'];
    check($result['status'] === 'approved' && count($client->select('stations', ['select' => 'id', 'id' => 'eq.'.$stationId])) === 1, 'approval lists the station publicly');
    check($invitations->stationAdmin($result['owner_email'], $result['owner_name'], $stationId, $result['station_name']), 'owner invited');
    $ownerSession = $open($address('owner'));
    $users[] = $ownerSession['user']['id'];
    $ownerAccess = $client->access($ownerSession['access_token']);
    check(in_array('station_admin', $ownerAccess['roles'], true) && $ownerAccess['stations'][0]['id'] === $stationId, 'owner sets a password and reaches their station');

    $driver = ['full_name' => 'Live Driver', 'phone' => '+639175550101', 'email' => $address('driver'), 'vehicle' => 'Tricycle', 'plate' => 'LIVE 123', 'capacity' => 12];
    // Same steps as StationController::addDriver: the admin types the password, no email is sent.
    $account = $client->createConfirmedUser($driver['email'], 'Rider-'.$tag.'-pass', ['full_name' => $driver['full_name'], 'phone' => $driver['phone']]);
    $users[] = $account['id'];
    $registry->registerDriver($account['id'], $stationId, $driver, $ownerSession['user']['id']);
    check(!isset($mailer->links[$driver['email']]), 'owner adds a driver with a password (no email)');
    $driverSession = $client->signInWithPassword($driver['email'], 'Rider-'.$tag.'-pass');
    $driverAccess = $client->access($driverSession['access_token']);
    check(in_array('rider', $driverAccess['roles'], true) && $driverAccess['stations'][0]['id'] === $stationId, 'driver logs in as a rider with the password from the admin');
    check(count($registry->drivers($ownerSession['access_token'], $stationId)) === 1, 'owner sees the driver');
    check(count($registry->drivers($driverSession['access_token'])) === 1, 'driver sees only their own record');
    check(in_array($driverSession['user']['id'], array_column($registry->drivers($adminSession['access_token']), 'user_id'), true), 'Super Admin sees the driver registration');
} finally {
    if ($registrationId !== null) $admin('DELETE', '/rest/v1/station_registrations?id=eq.'.$registrationId);
    foreach ($users as $id) $admin('DELETE', '/auth/v1/admin/users/'.$id);
    if ($stationId !== null) $admin('DELETE', '/rest/v1/stations?id=eq.'.$stationId);
    echo "  ..  test station, registration and accounts removed\n";
}
echo "$checks live registry checks passed.\n";
