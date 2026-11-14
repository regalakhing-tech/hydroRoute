<?php
// Live check against the Supabase project in .env: creates a confirmed test user with the secret key,
// logs in, saves the profile name, reads it back through RLS, logs out, then deletes the test user.
// No email is sent. Run: php tests/auth-live.php
declare(strict_types=1);
$config = require dirname(__DIR__).'/bootstrap.php';

use App\Services\SupabaseClient;
use App\Services\SupabaseException;

$client = new SupabaseClient($config['supabase']);
if (!$client->canCreateUsers()) {
    fwrite(STDERR, "Set SUPABASE_SECRET_KEY in .env first.\n");
    exit(1);
}
$checks = 0;
function check(bool $condition, string $message): void { global $checks; if (!$condition) throw new RuntimeException('FAILED: '.$message); $checks++; echo "  ok  $message\n"; }

// Plus-addressing on the sender mailbox keeps the test address deliverable without sending anything.
$mailbox = $config['mail']['username'] ?: 'hydroroute@example.com';
[$local, $domain] = explode('@', $mailbox, 2) + [1 => 'example.com'];
$email = strtolower($local.'+hydroroute-test-'.bin2hex(random_bytes(3)).'@'.$domain);
$password = 'Test-'.bin2hex(random_bytes(8));
$userId = null;

try {
    $user = $client->createConfirmedUser($email, $password, ['full_name' => 'Live Test', 'phone' => '+639170000000']);
    $userId = is_string($user['id'] ?? null) ? $user['id'] : null;
    check($userId !== null, 'account created with the secret key');
    check(!empty($user['email_confirmed_at']), 'account is confirmed without a Supabase email');
    try {
        $client->createConfirmedUser($email, $password, []);
        check(false, 'duplicate email refused');
    } catch (SupabaseException $exception) {
        check(in_array($exception->errorCode, ['email_exists', 'user_already_exists'], true), 'duplicate email refused ('.$exception->errorCode.')');
    }
    try {
        $client->signInWithPassword($email, 'wrong-password-123');
        check(false, 'wrong password refused');
    } catch (SupabaseException $exception) {
        check($exception->errorCode === 'invalid_credentials', 'wrong password refused (invalid_credentials)');
    }
    $session = $client->signInWithPassword($email, $password);
    check($session['user']['id'] === $userId && $session['user']['user_metadata']['full_name'] === 'Live Test', 'right password returns a session with the name');
    $client->saveDisplayName($userId, 'Live Test', $session['access_token']);
    $profile = $client->select('profiles', ['select' => 'id,display_name', 'id' => 'eq.'.$userId], $session['access_token']);
    check(($profile[0]['display_name'] ?? '') === 'Live Test', 'profile row created by the trigger and name saved through RLS');
    $client->signOut($session['access_token']);
    check(true, 'logout accepted');
} finally {
    if ($userId !== null) {
        $handle = curl_init(rtrim($config['supabase']['url'], '/').'/auth/v1/admin/users/'.rawurlencode($userId));
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_HTTPHEADER => ['apikey: '.$config['supabase']['secret_key']],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_OPTIONS => CURLSSLOPT_NATIVE_CA,
        ]);
        curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        echo $status === 200 ? "  ok  test user deleted\n" : "  !!  could not delete test user $email (HTTP $status); remove it in Authentication > Users\n";
    }
}
echo "$checks live auth checks passed.\n";
