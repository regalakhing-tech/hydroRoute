<?php
use App\Services\Workspace;
use App\UI\OrderStatus as S;
use App\UI\UI as U;

$group = in_array($_GET['group'] ?? '', ['active', 'completed', 'cancelled'], true) ? $_GET['group'] : 'all';
$live = Workspace::get()->load(static fn (Workspace $w): array => $w->orders($group));
$tabs = ['/customer/orders' => 'All', '/customer/orders?group=active' => 'Active', '/customer/orders?group=completed' => 'Completed', '/customer/orders?group=cancelled' => 'Cancelled'];
?>
<?= U::heading('My orders', 'Your orders from every station, newest first.', U::btn('Order water', '/customer/stations', 'primary', 'plus')) ?>
<?= U::tabs($tabs, $group === 'all' ? '/customer/orders' : '/customer/orders?group=' . $group) ?>
<section class="panel p-5 md:p-6">
<?php if (isset($live['error'])): ?><p class="p-6 text-center text-muted" role="alert"><?= U::e($live['error']) ?></p>
<?php elseif ($live['orders'] === []): ?><div class="p-8 text-center"><p class="font-semibold">No orders here yet.</p><p class="mt-1 text-sm text-muted">Find a station to place your first order.</p><div class="mt-4"><?= U::btn('Find stations', '/customer/stations', 'primary', 'search') ?></div></div>
<?php else: ?>
<div class="mb-4 max-w-md"><?= U::field('Search orders', 'order_search', '', 'search', ['placeholder' => 'Order number or station', 'data-search' => 'customer-orders', 'icon' => 'search']) ?></div>
<div class="overflow-x-auto"><table id="customer-orders" class="data-table min-w-[720px]"><thead><tr><?php foreach (['Order', 'Placed', 'Station', 'Items', 'Total', 'Method', 'Status', ''] as $h): ?><th scope="col"><?= U::e($h) ?></th><?php endforeach ?></tr></thead><tbody>
<?php foreach ($live['orders'] as $o): ?><tr data-search-row><td class="font-semibold whitespace-nowrap"><?= U::e($o['order_no']) ?></td><td class="whitespace-nowrap"><?= U::e(S::time($o['created_at'], 'M j, Y')) ?><?= $o['schedule_mode'] === 'scheduled' ? '<span class="block text-xs text-muted">For ' . U::e(S::time($o['requested_at'], 'M j, g:i A')) . '</span>' : '' ?></td><td><?= U::e($o['station_name']) ?></td><td><?= (int) $o['total_units'] ?></td><td><?= S::pesos($o['total_centavos']) ?></td><td><?= $o['fulfillment'] === 'pickup' ? 'Pickup' : 'Delivery' ?></td><td><?= S::badge($o['status'], $o['fulfillment']) ?></td><td><?= U::btn('View', '/customer/tracking?order=' . $o['id'], 'ghost', 'eye') ?></td></tr><?php endforeach ?>
</tbody></table><p data-empty hidden class="p-8 text-center muted">No orders match your search.</p></div>
<p class="mt-4 text-sm text-muted">Showing your <?= count($live['orders']) ?> most recent orders.</p>
<?php endif ?></section>
