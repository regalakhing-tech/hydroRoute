<?php
// Sends queued order-update emails now (up to 20). Run: php scripts/send-emails.php
// Needs SUPABASE_SECRET_KEY and the MAIL_* settings in .env. Safe to run repeatedly: claimed emails are locked.
declare(strict_types=1);
$config = require dirname(__DIR__) . '/bootstrap.php';
$outbox = App\Services\EmailOutbox::fromConfig(new App\Services\SupabaseClient($config['supabase']), $config);
if ($outbox === null) { fwrite(STDERR, "Mail is not configured (MAIL_* in .env).\n"); exit(1); }
$result = $outbox->flush(20);
echo "Sent {$result['sent']}, failed {$result['failed']}.\n";
