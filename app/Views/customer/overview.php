<?php if (!($data['preview'] ?? false)) { require __DIR__ . '/live/overview.php'; return; } ?>
<?php use App\UI\UI as U; $sample = $data['sample']; $order = $sample['orders'][0]; $steps = array_slice($sample['progress'], 0, 5); ?>
<?= U::heading('Good morning, '.explode(' ', $data['roleInfo']['name'])[0]) ?>
<section class="panel p-4 md:p-6">
    <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="section-title flex items-center gap-2.5"><span class="title-icon"><?= U::icon('truck','size-5') ?></span>Your active order</h2><?= U::badge($order['status'], $order['tone']) ?></div>
    <p class="mt-2 flex items-center gap-2 text-sm text-muted"><?= U::icon('store','size-4 shrink-0') ?><?= U::e($order['id']) ?>, <?= U::e($order['station']) ?></p>
    <ol class="mt-5 grid grid-cols-5" aria-label="Delivery progress">
    <?php foreach ($steps as $i => [$label, , $time]): $done = $i < $order['stage']; $current = $i === $order['stage']; ?>
        <li class="relative flex flex-col items-center text-center"<?= $current ? ' aria-current="step"' : '' ?>>
            <?php if ($i < count($steps) - 1): ?><span class="absolute left-1/2 top-4 h-0.5 w-full <?= $done ? 'bg-water' : 'bg-line' ?>" aria-hidden="true"></span><?php endif ?>
            <span class="relative flex size-8 items-center justify-center rounded-full <?= $done ? 'bg-water text-white' : ($current ? 'bg-white ring-2 ring-water' : 'bg-white ring-2 ring-line') ?>"><?= $done ? U::icon('check','size-4') : ($current ? '<span class="size-2.5 rounded-full bg-water"></span>' : '') ?></span>
            <span class="mt-2 px-1 text-xs font-semibold leading-tight sm:text-sm <?= $done || $current ? 'text-ink' : 'text-muted' ?>"><?= U::e($label) ?></span>
            <span class="mt-0.5 hidden text-xs text-muted sm:block"><?= U::e($time) ?></span>
        </li>
    <?php endforeach ?>
    </ol>
    <div class="mt-5 flex flex-col gap-3 border-t border-line pt-4 sm:flex-row sm:items-center sm:justify-between">
        <dl class="flex flex-wrap gap-x-8 gap-y-2 text-sm"><div class="flex items-center gap-2"><dt class="text-muted"><?= U::icon('bottle','size-4') ?><span class="sr-only">Items</span></dt><dd class="font-semibold"><?= (int)$order['quantity'] ?> refill containers</dd></div><div class="flex items-center gap-2"><dt class="text-muted"><?= U::icon('credit','size-4') ?><span class="sr-only">Total amount</span></dt><dd class="font-semibold"><?= U::money($order['total']) ?></dd></div></dl>
        <?= U::btn('Track order', '/customer/tracking?order='.$order['id'], 'primary', 'pin', ['class' => 'w-full sm:w-auto']) ?>
    </div>
</section>
<section class="mt-6">
    <div class="mb-3 flex items-center justify-between gap-4"><h2 class="section-title flex items-center gap-2.5"><span class="title-icon"><?= U::icon('store','size-5') ?></span>Stations near Guintas</h2><a href="/customer/stations" class="flex shrink-0 items-center gap-1 text-sm font-semibold text-water hover:underline">See all<?= U::icon('chevron-right','size-4') ?></a></div>
    <div class="scroll-row -mx-4 scroll-px-4 px-4 sm:mx-0 sm:scroll-px-0 sm:px-0">
        <?php foreach ($sample['stations'] as $station) require dirname(__DIR__).'/partials/station-card.php'; ?>
    </div>
</section>
<section class="panel mt-6 p-4 md:p-6"><h2 class="section-title mb-3 flex items-center gap-2.5"><span class="title-icon"><?= U::icon('orders','size-5') ?></span>Recent orders</h2><?php $rows = []; foreach (array_slice($sample['orders'], 1) as $o) $rows[] = [U::e($o['date']), U::e($o['station']), $o['quantity'].' refill containers', U::money($o['total']), U::badge($o['status'], $o['tone']).' '.U::btn('View', '/customer/tracking?order='.$o['id'], 'ghost')]; echo U::table(['Date', 'Station', 'Quantity', 'Total', 'Status'], $rows, ['class' => 'min-w-[640px]']); ?></section>
