<?php if (!($data['preview'] ?? false)) { require dirname(__DIR__) . '/shared/live-messages.php'; return; } ?>
<?php $chat = \App\UI\MessagingData::forRole('customer'); require dirname(__DIR__).'/shared/messages.php'; ?>
