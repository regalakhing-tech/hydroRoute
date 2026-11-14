<?php
use App\Services\Workspace;
use App\UI\OrderStatus as S;
use App\UI\UI as U;

$live = Workspace::get()->load(static fn (Workspace $w): array => $w->home());
$first = explode(' ', trim((string) ($data['user']['name'] ?? '')))[0] ?: 'there';
$hour = (int) (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
?>
<?= U::heading($greeting . ', ' . $first) ?>
<?php if (isset($live['error'])): ?><section class="panel p-6" role="alert"><?= U::e($live['error']) ?></section><?php return; endif;
$active = $live['active']; ?>
<div class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-3">
<a href="/customer/notifications" class="panel flex items-center gap-3 p-4 hover:bg-foam"><?= U::icon('bell', 'size-6 text-water') ?><span><span class="block text-xl font-bold"><?= (int) $live['unread_notifications'] ?></span><span class="text-xs text-muted">Unread notifications</span></span></a>
<a href="/customer/messages" class="panel flex items-center gap-3 p-4 hover:bg-foam"><?= U::icon('message', 'size-6 text-water') ?><span><span class="block text-xl font-bold"><?= (int) $live['unread_messages'] ?></span><span class="text-xs text-muted">Unread messages</span></span></a>
<a href="/customer/addresses" class="panel col-span-2 flex items-center gap-3 p-4 hover:bg-foam sm:col-span-1"><?= U::icon('pin', 'size-6 text-water') ?><span class="min-w-0"><span class="block truncate font-semibold"><?= U::e($live['default_address']['label'] ?? 'No address yet') ?></span><span class="block truncate text-xs text-muted"><?= $live['default_address'] ? U::e($live['default_address']['address']) . (!$live['default_address']['pinned'] ? ' · add a map pin for delivery' : '') : 'Add an address with a map pin to order delivery' ?></span></span></a>
</div>
<?php if ($active !== null):
    $pickup = $active['fulfillment'] === 'pickup';
    $steps = $pickup ? ['confirmed', 'preparing', 'ready', 'collected'] : ['confirmed', 'preparing', 'ready', 'out_for_delivery', 'delivered'];
    $reached = array_column($active['timeline'], 'at', 'to');
    $now = array_search($active['status'], $steps, true); ?>
<section class="panel p-4 md:p-6">
<div class="flex flex-wrap items-center justify-between gap-3"><h2 class="section-title flex items-center gap-2.5"><span class="title-icon"><?= U::icon('truck', 'size-5') ?></span>Your active order</h2><?= S::badge($active['status'], $active['fulfillment']) ?></div>
<p class="mt-2 flex items-center gap-2 text-sm text-muted"><?= U::icon('store', 'size-4 shrink-0') ?><?= U::e(($active['order_no'] ?? '') . ', ' . $active['station_name']) ?><?= $active['schedule_mode'] === 'scheduled' ? ' · for ' . U::e(S::time($active['requested_at'], 'M j, g:i A')) : '' ?></p>
<?php if ($now !== false): ?><ol class="mt-5 grid <?= count($steps) === 4 ? 'grid-cols-4' : 'grid-cols-5' ?>" aria-label="Order progress"><?php foreach ($steps as $i => $step): $done = $i < $now || isset($reached[$step]) && $i !== $now; $current = $i === $now; ?>
<li class="relative flex flex-col items-center text-center"<?= $current ? ' aria-current="step"' : '' ?>><?php if ($i < count($steps) - 1): ?><span class="absolute left-1/2 top-4 h-0.5 w-full <?= $done ? 'bg-water' : 'bg-line' ?>" aria-hidden="true"></span><?php endif ?><span class="relative flex size-8 items-center justify-center rounded-full <?= $done ? 'bg-water text-white' : ($current ? 'bg-white ring-2 ring-water' : 'bg-white ring-2 ring-line') ?>"><?= $done ? U::icon('check', 'size-4') : ($current ? '<span class="size-2.5 rounded-full bg-water"></span>' : '') ?></span><span class="mt-2 px-1 text-xs font-semibold leading-tight sm:text-sm <?= $done || $current ? 'text-ink' : 'text-muted' ?>"><?= U::e(S::label($step, $active['fulfillment'])) ?></span><span class="mt-0.5 hidden text-xs text-muted sm:block"><?= U::e(isset($reached[$step]) ? S::time($reached[$step], 'g:i A') : '') ?></span></li>
<?php endforeach ?></ol><?php endif ?>
<div class="mt-5 flex flex-col gap-3 border-t border-line pt-4 sm:flex-row sm:items-center sm:justify-between"><p class="text-sm"><strong><?= (int) $active['total_units'] ?></strong> item(s) · <strong><?= S::pesos($active['total_centavos']) ?></strong> · <?= $pickup ? 'Cash on pickup' : 'Cash on delivery' ?></p><?= U::btn('Track order', '/customer/tracking?order=' . $active['id'], 'primary', 'pin', ['class' => 'w-full sm:w-auto']) ?></div></section>
<?php else: ?><section class="panel p-6 text-center"><p class="font-semibold">No active order right now.</p><p class="mt-1 text-sm text-muted">Choose a station to order water refills.</p><div class="mt-4"><?= U::btn('Find stations', '/customer/stations', 'primary', 'search') ?></div></section><?php endif ?>
<section class="mt-6"><div class="mb-3 flex items-center justify-between gap-4"><h2 class="section-title flex items-center gap-2.5"><span class="title-icon"><?= U::icon('store', 'size-5') ?></span>Stations in Hamtic</h2><a href="/customer/stations" class="flex shrink-0 items-center gap-1 text-sm font-semibold text-water hover:underline">See all<?= U::icon('chevron-right', 'size-4') ?></a></div>
<?php if ($live['stations'] === []): ?><p class="panel p-5 text-sm text-muted">No station is available right now.</p><?php else: ?><div class="scroll-row -mx-4 scroll-px-4 px-4 sm:mx-0 sm:scroll-px-0 sm:px-0"><?php foreach ($live['stations'] as $station): ?>
<article class="lift flex w-[84%] shrink-0 flex-col rounded-2xl border border-line bg-white p-5 sm:w-80"><div><?= U::badge($station['status'], $station['tone']) ?></div><h3 class="mt-3 font-heading text-xl font-bold leading-snug"><?= U::e($station['name']) ?></h3><p class="mt-1 flex items-center gap-1.5 text-sm text-muted"><?= U::icon('pin', 'size-4 shrink-0') ?>Brgy. <?= U::e($station['area']) ?>, Hamtic</p><p class="mt-3 flex-1 text-sm text-muted"><?= U::e($station['next']) ?></p><div class="mt-4"><?= U::btn('View station', '/customer/station?station=' . $station['id'], 'secondary', 'arrow-right', ['class' => 'w-full']) ?></div></article>
<?php endforeach ?></div><?php endif ?></section>
<section class="panel mt-6 p-4 md:p-6"><h2 class="section-title mb-3 flex items-center gap-2.5"><span class="title-icon"><?= U::icon('orders', 'size-5') ?></span>Recent orders</h2>
<?php if ($live['recent'] === []): ?><p class="text-sm text-muted">Your orders will appear here.</p><?php else: ?><?= U::table(['Order', 'Date', 'Station', 'Items', 'Total', 'Status', ''], array_map(static fn (array $o): array => ['<strong>' . U::e($o['order_no']) . '</strong>', U::e(S::time($o['created_at'], 'M j, Y')), U::e($o['station_name']), (int) $o['total_units'], S::pesos($o['total_centavos']), S::badge($o['status'], $o['fulfillment']), U::btn('View', '/customer/tracking?order=' . $o['id'], 'ghost', 'eye')], $live['recent']), ['class' => 'min-w-[640px]']) ?><?php endif ?></section>
