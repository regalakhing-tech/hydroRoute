<?php
// Original customer home design with the real active order, stations and recent orders.
use App\Services\Workspace;
use App\UI\OrderStatus as S;
use App\UI\UI as U;

$live = Workspace::get()->load(static fn (Workspace $w): array => $w->home());
$first = explode(' ', trim((string) ($data['roleInfo']['name'] ?? '')))[0] ?: 'there';
$hour = (int) (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('G');
?>
<?= U::heading(($hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening')) . ', ' . $first) ?>
<?php if (isset($live['error'])): ?><section class="panel p-6" role="alert"><?= U::e($live['error']) ?></section><?php return; endif;
$order = $live['active']; ?>
<section class="panel p-4 md:p-6">
<?php if ($order === null): ?>
    <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="section-title flex items-center gap-2.5"><span class="title-icon"><?= U::icon('truck','size-5') ?></span>Your active order</h2><?= U::badge('No active order', 'gray') ?></div>
    <p class="mt-3 text-sm text-muted">You have no order in progress. Choose a station to order water refills.</p>
    <div class="mt-5 border-t border-line pt-4"><?= U::btn('Find stations', '/customer/stations', 'primary', 'search', ['class' => 'w-full sm:w-auto']) ?></div>
<?php else:
    $pickup = $order['fulfillment'] === 'pickup';
    $steps = $pickup ? ['confirmed', 'preparing', 'ready', 'collected'] : ['confirmed', 'preparing', 'ready', 'out_for_delivery', 'delivered'];
    $reached = array_column($order['timeline'], 'at', 'to');
    $stage = array_search($order['status'], $steps, true); $stage = $stage === false ? -1 : $stage; ?>
    <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="section-title flex items-center gap-2.5"><span class="title-icon"><?= U::icon('truck','size-5') ?></span>Your active order</h2><?= S::badge($order['status'], $order['fulfillment']) ?></div>
    <p class="mt-2 flex items-center gap-2 text-sm text-muted"><?= U::icon('store','size-4 shrink-0') ?><?= U::e($order['order_no']) ?>, <?= U::e($order['station_name']) ?><?= $order['schedule_mode'] === 'scheduled' ? ' · for ' . U::e(S::time($order['requested_at'], 'M j, g:i A')) : '' ?></p>
    <ol class="mt-5 grid <?= count($steps) === 4 ? 'grid-cols-4' : 'grid-cols-5' ?>" aria-label="<?= $pickup ? 'Pickup' : 'Delivery' ?> progress">
    <?php foreach ($steps as $i => $step): $done = $i < $stage; $current = $i === $stage; ?>
        <li class="relative flex flex-col items-center text-center"<?= $current ? ' aria-current="step"' : '' ?>>
            <?php if ($i < count($steps) - 1): ?><span class="absolute left-1/2 top-4 h-0.5 w-full <?= $done ? 'bg-water' : 'bg-line' ?>" aria-hidden="true"></span><?php endif ?>
            <span class="relative flex size-8 items-center justify-center rounded-full <?= $done ? 'bg-water text-white' : ($current ? 'bg-white ring-2 ring-water' : 'bg-white ring-2 ring-line') ?>"><?= $done ? U::icon('check','size-4') : ($current ? '<span class="size-2.5 rounded-full bg-water"></span>' : '') ?></span>
            <span class="mt-2 px-1 text-xs font-semibold leading-tight sm:text-sm <?= $done || $current ? 'text-ink' : 'text-muted' ?>"><?= U::e(S::label($step, $order['fulfillment'])) ?></span>
            <span class="mt-0.5 hidden text-xs text-muted sm:block"><?= U::e(isset($reached[$step]) ? S::time($reached[$step], 'g:i A') : '—') ?></span>
        </li>
    <?php endforeach ?>
    </ol>
    <div class="mt-5 flex flex-col gap-3 border-t border-line pt-4 sm:flex-row sm:items-center sm:justify-between">
        <dl class="flex flex-wrap gap-x-8 gap-y-2 text-sm"><div class="flex items-center gap-2"><dt class="text-muted"><?= U::icon('bottle','size-4') ?><span class="sr-only">Items</span></dt><dd class="font-semibold"><?= (int) $order['total_units'] ?> item<?= (int) $order['total_units'] === 1 ? '' : 's' ?></dd></div><div class="flex items-center gap-2"><dt class="text-muted"><?= U::icon('credit','size-4') ?><span class="sr-only">Total amount</span></dt><dd class="font-semibold"><?= S::pesos($order['total_centavos']) ?></dd></div></dl>
        <?= U::btn('Track order', '/customer/tracking?order=' . $order['id'], 'primary', 'pin', ['class' => 'w-full sm:w-auto']) ?>
    </div>
<?php endif ?>
</section>
<section class="mt-6">
    <div class="mb-3 flex items-center justify-between gap-4"><h2 class="section-title flex items-center gap-2.5"><span class="title-icon"><?= U::icon('store','size-5') ?></span>Stations in Hamtic</h2><a href="/customer/stations" class="flex shrink-0 items-center gap-1 text-sm font-semibold text-water hover:underline">See all<?= U::icon('chevron-right','size-4') ?></a></div>
    <?php if ($live['stations'] === []): ?><p class="panel p-5 text-sm text-muted">No station is available right now.</p><?php else: ?>
    <div class="scroll-row -mx-4 scroll-px-4 px-4 sm:mx-0 sm:scroll-px-0 sm:px-0">
        <?php foreach ($live['stations'] as $station) require dirname(__DIR__, 2) . '/partials/station-card.php'; ?>
    </div><?php endif ?>
</section>
<section class="panel mt-6 p-4 md:p-6"><h2 class="section-title mb-3 flex items-center gap-2.5"><span class="title-icon"><?= U::icon('orders','size-5') ?></span>Recent orders</h2><?php if ($live['recent'] === []): ?><p class="text-sm text-muted">Your orders will appear here.</p><?php else: $rows = []; foreach ($live['recent'] as $o) $rows[] = [U::e(S::time($o['created_at'], 'M j, Y')), U::e($o['station_name']), (int) $o['total_units'] . ' item' . ((int) $o['total_units'] === 1 ? '' : 's'), S::pesos($o['total_centavos']), S::badge($o['status'], $o['fulfillment']) . ' ' . U::btn('View', '/customer/tracking?order=' . $o['id'], 'ghost')]; echo U::table(['Date', 'Station', 'Quantity', 'Total', 'Status'], $rows, ['class' => 'min-w-[640px]']); endif ?></section>
