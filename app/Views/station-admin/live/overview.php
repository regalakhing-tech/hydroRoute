<?php
use App\Services\Workspace;
use App\UI\LiveForm as F;
use App\UI\OrderStatus as S;
use App\UI\UI as U;

$live = Workspace::get()->load(static fn (Workspace $w): array => $w->overview());
$notes = Workspace::get()->load(static fn (Workspace $w): array => $w->notifications($w->stationId()));
?>
<?php if (isset($live['error'])): ?><?= U::heading('Station overview') ?><section class="panel p-6" role="alert"><?= U::e($live['error']) ?></section><?php return; endif;
$c = $live['counts']; $av = $live['availability']; ?>
<?= U::heading('Station overview', (new DateTimeImmutable($live['today']))->format('l, F j, Y'), U::badge(($av['label'] ?? 'Closed') . (!empty($av['next_close_at']) && !empty($av['is_open']) ? ' until ' . S::time($av['next_close_at'], 'g:i A') : ''), !empty($av['accepting_asap']) ? 'green' : 'amber')) ?>
<div class="mb-5 grid grid-cols-2 gap-3 md:gap-4 xl:grid-cols-4">
<?= U::stat('Orders today', (string) $c['orders_today'], 'orders', 'blue', $c['new'] . ' waiting · ' . $c['preparing'] . ' preparing') ?>
<?= U::stat('Cash collected today', S::pesos($live['cash_collected_today']), 'credit', 'green', 'Verified today ' . S::pesos($live['cash_verified_today'])) ?>
<?= U::stat('Unverified cash', S::pesos($live['unverified_cash']), 'alert', 'amber', 'All days, waiting for checking') ?>
<?= U::stat('Riders free', $live['riders_free'] . ' / ' . $live['riders_total'], 'truck', 'violet', $live['active_trips'] . ' trip(s) on the road') ?>
</div>
<div class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-4 xl:grid-cols-8 text-center text-sm"><?php foreach ([['Waiting', $c['new']], ['On hold', $c['on_hold']], ['Scheduled', $c['scheduled']], ['Preparing', $c['preparing']], ['Ready', $c['ready']], ['Out for delivery', $c['out_for_delivery']], ['Completed today', $c['completed_today']], ['Cancelled today', $c['cancelled_today']]] as [$label, $n]): ?><div class="rounded-lg border border-line bg-white p-3"><p class="text-xl font-bold"><?= (int) $n ?></p><p class="text-xs text-muted"><?= U::e($label) ?></p></div><?php endforeach ?></div>
<?php if ($live['overdue'] > 0 || $live['open_reviews'] > 0): ?><p class="mb-5 flex items-center gap-2 rounded-xl border border-rose-200 bg-rose-50 p-3.5 text-sm text-rose-900" role="alert"><?= U::icon('alert', 'size-4 shrink-0') ?><span><?= (int) $live['open_reviews'] ?> alert(s) need attention, including <?= (int) $live['overdue'] ?> order(s) waiting over the overdue limit.</span><a class="ml-auto font-semibold underline" href="/station-admin/orders">Open queue</a></p><?php endif ?>
<div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_360px]">
<section class="panel p-4 md:p-5"><div class="mb-4 flex items-center justify-between gap-3"><?= U::title('Top of the queue', 'orders', '') ?><?= U::btn('Full queue', '/station-admin/orders', 'ghost', 'chevron-right', ['class' => 'btn-sm']) ?></div>
<?php if ($live['queue'] === []): ?><p class="p-4 text-center text-muted">No orders waiting.</p><?php else: ?><?= U::table(['Order', 'Customer', 'Qty', 'Priority', ''], array_map(static fn (array $o): array => ['<strong>' . U::e($o['order_no']) . '</strong>' . ($o['overdue'] ? ' ' . U::badge('Overdue', 'red') : ''), U::e($o['customer_name'] ?? ''), (int) $o['total_units'], F::chips($o['score']), U::btn('Open', '/station-admin/orders?order=' . $o['id'], 'secondary', 'eye', ['class' => 'btn-sm'])], array_slice($live['queue'], 0, 5))) ?><?php endif ?></section>
<aside class="panel p-4 md:p-5"><?= U::title('Latest notices', 'bell', 'mb-3') ?><?php if (isset($notes['error']) || $notes['notifications'] === []): ?><p class="text-sm text-muted">No notices yet.</p><?php else: ?><ul class="divide-y divide-line text-sm"><?php foreach (array_slice($notes['notifications'], 0, 6) as $n): ?><li class="py-2 <?= $n['read_at'] === null ? '' : 'text-muted' ?>"><p class="font-semibold"><?= U::e($n['title']) ?></p><p class="text-xs text-muted"><?= U::e($n['body']) ?> · <?= U::e(S::time($n['created_at'], 'M j, g:i A')) ?></p><div class="mt-1 flex gap-3"><?php if ($n['order_id']): ?><a class="text-xs font-semibold text-water" href="/station-admin/orders?order=<?= U::e($n['order_id']) ?>">Open order</a><?php endif ?><?php if ($n['read_at'] === null): ?><?= F::open('/station-admin/notifications/read', ['notification_id' => $n['id']]) ?><button type="submit" class="text-xs font-semibold text-muted">Mark read</button></form><?php endif ?></div></li><?php endforeach ?></ul><?php endif ?></aside>
</div>
<section class="panel mt-5 p-4 md:p-5"><div class="mb-4 flex items-center justify-between gap-3"><?= U::title('Ready for delivery', 'truck', '') ?><?= U::btn('Build a trip', '/station-admin/deliveries#dispatch', 'primary', 'truck', ['class' => 'btn-sm']) ?></div>
<?php if ($live['ready_delivery'] === []): ?><p class="p-4 text-center text-muted">No delivery order is ready.</p><?php else: ?><?= U::table(['Order', 'Customer', 'Barangay', 'Load', 'Status'], array_map(static fn (array $o): array => ['<strong>' . U::e($o['order_no']) . '</strong>', U::e($o['customer_name'] ?? ''), U::e($o['barangay'] ?? ''), (int) $o['load_units'], $o['redispatch_paused'] ? U::badge('Paused after failed attempt', 'amber') : ($o['due'] ? U::badge('Due', 'green') : U::badge('Later', 'gray'))], $live['ready_delivery'])) ?><?php endif ?></section>
