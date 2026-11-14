<?php
// Original "My orders" design with the customer's real orders.
use App\Services\Workspace;
use App\UI\Fulfillment;
use App\UI\OrderStatus as S;
use App\UI\UI as U;

$live = Workspace::get()->load(static fn (Workspace $w): array => $w->orders('all'));
$orders = $live['orders'] ?? [];
$labels = array_values(array_unique(array_map(static fn (array $o): string => S::label($o['status'], $o['fulfillment']), $orders)));
?>
<?= U::heading('My orders', 'Keep track of your refills and collection history.', U::btn('Order water', '/customer/stations', 'primary', 'plus')) ?>
<section class="panel p-5 md:p-6"><div class="mb-5 grid gap-4 md:grid-cols-[1fr_220px]"><?= U::field('Search orders', 'order_search', '', 'search', ['placeholder' => 'Order number or station', 'data-search' => 'customer-orders']) ?><?= U::select('Order status', 'status', ['all' => 'All orders'] + array_combine($labels, $labels), 'all', ['data-filter-target' => 'customer-orders']) ?></div><div class="overflow-x-auto"><table id="customer-orders" class="data-table"><thead><tr><?php foreach (['Order', 'Date', 'Station', 'Items', 'Total', 'Method', 'Status', ''] as $h): ?><th><?= U::e($h) ?></th><?php endforeach; ?></tr></thead><tbody><?php foreach ($orders as $o): $label = S::label($o['status'], $o['fulfillment']); ?><tr data-search-row data-filter-value="<?= U::e($label) ?>"><td class="font-semibold"><?= U::e($o['order_no']) ?></td><td><?= U::e(S::time($o['created_at'], 'M j, Y')) ?></td><td><?= U::e($o['station_name']) ?></td><td><?= (int) $o['total_units'] ?> item<?= (int) $o['total_units'] === 1 ? '' : 's' ?></td><td><?= S::pesos($o['total_centavos']) ?></td><td><?= U::e(Fulfillment::label($o['fulfillment'])) ?></td><td><?= U::badge($label, S::tone($o['status'])) ?></td><td><?= U::btn('View', '/customer/tracking?order=' . $o['id'], 'ghost', 'eye') ?></td></tr><?php endforeach; ?></tbody></table></div><p data-empty <?= $orders === [] ? '' : 'hidden' ?> class="p-8 text-center muted"><?= isset($live['error']) ? U::e($live['error']) : ($orders === [] ? 'No orders yet. Find a station to place your first order.' : 'No orders match your search.') ?></p><p class="muted text-sm mt-5">Showing your <?= count($orders) ?> most recent order<?= count($orders) === 1 ? '' : 's' ?></p></section>
