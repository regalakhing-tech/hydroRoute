<?php
// One-time provisioning from the terminal (uses the secret key in .env).
//   php scripts/invite.php super-admin you@example.com "Your Name"
//   php scripts/invite.php station-admin guintas owner@example.com "Owner Name"
// The person receives an email with a link to set their password.
declare(strict_types=1);
$config = require dirname(__DIR__).'/bootstrap.php';

use App\Services\Invitations;
use App\Services\Mailer;
use App\Services\Registry;
use App\Services\SignupVerification as Check;
use App\Services\SupabaseClient;

$supabase = new SupabaseClient($config['supabase']);
$mailer = new Mailer($config['mail'], dirname(__DIR__).'/public/assets/images/logo.png');
$registry = new Registry($supabase);
$invitations = new Invitations($supabase, $registry, $mailer, $config['app']['url']);
$fail = static function (string $message): never { fwrite(STDERR, $message."\n"); exit(1); };

if (!$supabase->canCreateUsers() || !$mailer->isConfigured()) $fail('Set SUPABASE_SECRET_KEY and the MAIL_* settings in .env first.');
[$kind, $first, $second, $third] = array_slice($argv, 1) + ['', '', '', ''];

if ($kind === 'super-admin') {
    $email = Check::email($first) ?? $fail('Usage: php scripts/invite.php super-admin you@example.com "Your Name"');
    $name = Check::name($second) ?? 'Platform administrator';
    $sent = $invitations->platformAdmin($email, $name);
    echo ($sent ? 'Invitation emailed to ' : 'Super Admin role granted, but the email failed for ').$email."\n";
    exit($sent ? 0 : 1);
}
if ($kind === 'station-admin') {
    $email = Check::email($second) ?? $fail('Usage: php scripts/invite.php station-admin <station-slug> owner@example.com "Owner Name"');
    $station = $supabase->select('stations', ['select' => 'id,name', 'slug' => 'eq.'.$first])[0] ?? $fail("No approved station with slug '$first'.");
    $name = Check::name($third) ?? 'Station administrator';
    $sent = $invitations->stationAdmin($email, $name, $station['id'], $station['name']);
    echo ($sent ? 'Invitation emailed to ' : 'Station admin linked, but the email failed for ').$email.' for '.$station['name']."\n";
    exit($sent ? 0 : 1);
}
$fail("Usage:\n  php scripts/invite.php super-admin you@example.com \"Your Name\"\n  php scripts/invite.php station-admin <station-slug> owner@example.com \"Owner Name\"");
