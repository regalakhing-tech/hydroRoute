<?php
// Original notifications design with the customer's real notices.
use App\Services\Workspace;
use App\UI\LiveForm as F;
use App\UI\OrderStatus as S;
use App\UI\UI as U;

$live = Workspace::get()->load(static fn (Workspace $w): array => $w->notifications(null));
$notes = $live['notifications'] ?? [];
$icon = static fn (string $kind): string => match (true) { str_contains($kind, 'out_for_delivery') => 'truck', str_contains($kind, 'ready') || str_contains($kind, 'collected') => 'bottle', str_contains($kind, 'message') => 'message', str_contains($kind, 'reservation') => 'calendar', str_contains($kind, 'cancel') || str_contains($kind, 'closure') => 'alert', default => 'check' };
?>
<?= U::heading('Notifications', 'Updates about your orders and deliveries.', U::btn('Notification preferences', '/customer/account', 'secondary', 'settings')) ?>
<section class="panel p-5 md:p-6"><div class="flex justify-between items-center mb-5"><?= U::title('Your latest updates','file','') ?><div class="flex items-center gap-2"><?= U::badge(($live['unread'] ?? 0) . ' unread', 'blue') ?><?php if (($live['unread'] ?? 0) > 0): ?><?= F::button('/customer/notifications/read-all', [], 'Mark all as read', 'ghost', 'check') ?><?php endif ?></div></div><div class="divide-y divide-line"><?php foreach ($notes as $n): ?><article class="flex gap-4 py-6"><span class="flex size-12 shrink-0 items-center justify-center rounded-full bg-foam text-water"><?= U::icon($icon($n['kind']), 'size-6') ?></span><div class="flex-1"><div class="flex flex-wrap items-start justify-between gap-2"><h3 class="font-bold text-lg"><?= U::e($n['title']) ?></h3><time class="muted text-sm"><?= U::e(S::time($n['created_at'], 'M j, Y · g:i A')) ?></time></div><p class="muted mt-2"><?= U::e($n['body']) ?></p><?php if ($n['order_id']): ?><a href="/customer/tracking?order=<?= U::e($n['order_id']) ?>" class="inline-flex gap-2 items-center text-water font-semibold mt-4">View order <?= U::icon('arrow-right', 'size-4') ?></a><?php endif ?></div><?php if ($n['read_at'] === null): ?><?= F::open('/customer/notifications/read', ['notification_id' => $n['id']], ['class' => 'self-start']) ?><button type="submit" class="mt-2 block size-2.5 rounded-full bg-water" aria-label="Unread. Mark as read" title="Mark as read"></button></form><?php endif; ?></article><?php endforeach; ?></div><p class="text-center muted text-sm border-t border-line pt-5"><?= isset($live['error']) ? U::e($live['error']) : ($notes === [] ? 'No updates yet. Order updates appear here.' : 'You’re all caught up.') ?></p></section>
