<?php if (!($data['preview'] ?? false)) { require dirname(__DIR__) . '/shared/live-messages.php'; return; } ?>
<?php $chat = \App\UI\MessagingData::forRole('station-admin'); require dirname(__DIR__).'/shared/messages.php'; ?>
