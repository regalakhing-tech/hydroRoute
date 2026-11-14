<?php
// Live check: sends one real verification email through the SMTP settings in .env.
// Run: php tests/send-test-email.php you@example.com
declare(strict_types=1);
$config = require dirname(__DIR__).'/bootstrap.php';

$to = $argv[1] ?? '';
if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "Usage: php tests/send-test-email.php you@example.com\n");
    exit(2);
}
$mailer = new App\Services\Mailer($config['mail'], dirname(__DIR__).'/public/assets/images/logo.png');
if (!$mailer->isConfigured()) {
    fwrite(STDERR, "Set MAIL_HOST, MAIL_USERNAME, MAIL_PASSWORD and MAIL_FROM_ADDRESS in .env first.\n");
    exit(1);
}
$code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
try {
    $mailer->sendVerificationCode($to, 'HydroRoute tester', $code);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Email failed: '.$exception->getMessage()."\n");
    exit(1);
}
echo "Sent code $code to $to. Check the inbox (and spam) for the HydroRoute logo and this code.\n";
