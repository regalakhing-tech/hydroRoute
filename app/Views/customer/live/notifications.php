<?php
use App\Controllers\LiveController;
use App\Core\Session;
use App\Services\Workspace;
use App\UI\OrderStatus as S;
use App\UI\UI as U;

$live = Workspace::get()->load(static fn (Workspace $w): array => $w->notifications(null));
$csrf = U::e(Session::csrfToken());
$icon = static fn (string $kind): string => match (true) {
    str_contains($kind, 'out_for_delivery') => 'truck', str_contains($kind, 'cancel') => 'close', str_contains($kind, 'closure') => 'alert',
    str_contains($kind, 'message') => 'message', str_contains($kind, 'reservation') => 'calendar', default => 'bell',
};
?>
<?= U::heading('Notifications', 'Updates about your orders, messages and reservations.', !isset($live['error']) && $live['unread'] > 0 ? '<form method="post" action="/customer/notifications/read-all"><input type="hidden" name="_token" value="' . $csrf . '"><input type="hidden" name="request_key" value="' . U::e(LiveController::key()) . '">' . U::btn('Mark all as read', '', 'secondary', 'check', ['type' => 'submit']) . '</form>' : '') ?>
<section class="panel p-5 md:p-6">
<?php if (isset($live['error'])): ?><p class="p-6 text-center text-muted" role="alert"><?= U::e($live['error']) ?></p>
<?php elseif ($live['notifications'] === []): ?><p class="p-8 text-center text-muted">You have no notifications yet. Order updates appear here.</p>
<?php else: ?><div class="mb-2 flex items-center justify-between"><?= U::title('Latest updates', 'bell', '') ?><?= U::badge($live['unread'] . ' unread', $live['unread'] ? 'blue' : 'gray') ?></div><div class="divide-y divide-line">
<?php foreach ($live['notifications'] as $n): $unread = $n['read_at'] === null; ?>
<article class="flex gap-4 py-5"><span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-foam text-water"><?= U::icon($icon($n['kind']), 'size-5') ?></span><div class="min-w-0 flex-1"><div class="flex flex-wrap items-start justify-between gap-2"><h3 class="font-bold <?= $unread ? '' : 'text-muted' ?>"><?= U::e($n['title']) ?></h3><time class="text-sm text-muted"><?= U::e(S::time($n['created_at'])) ?></time></div><p class="mt-1 text-sm text-muted"><?= U::e($n['body']) ?></p><p class="mt-1 text-xs text-muted"><?= U::e($n['station_name']) ?></p>
<div class="mt-3 flex flex-wrap gap-3"><?php if ($n['order_id']): ?><a href="/customer/tracking?order=<?= U::e($n['order_id']) ?>" class="inline-flex items-center gap-1 text-sm font-semibold text-water">View order <?= U::icon('arrow-right', 'size-4') ?></a><?php endif ?>
<?php if ($unread): ?><form method="post" action="/customer/notifications/read"><input type="hidden" name="_token" value="<?= $csrf ?>"><input type="hidden" name="request_key" value="<?= U::e(LiveController::key()) ?>"><input type="hidden" name="notification_id" value="<?= U::e($n['id']) ?>"><button type="submit" class="text-sm font-semibold text-muted hover:text-ink">Mark as read</button></form><?php endif ?></div></div><?php if ($unread): ?><span class="mt-2 size-2 rounded-full bg-water" aria-label="Unread"></span><?php endif ?></article>
<?php endforeach ?></div><?php endif ?></section>
