<?php
use App\Services\Workspace;
use App\UI\OrderStatus as S;
use App\UI\UI as U;

$live = Workspace::get()->load(static fn (Workspace $w): array => $w->riderHistory());
?>
<?= U::heading('History', 'Your recorded deliveries, newest first.') ?>
<section class="panel p-4 md:p-5"><?php if (isset($live['error'])): ?><p role="alert"><?= U::e($live['error']) ?></p><?php elseif ($live['stops'] === []): ?><p class="p-6 text-center text-muted">No deliveries recorded yet.</p><?php else: ?>
<ul class="divide-y divide-line"><?php foreach ($live['stops'] as $s): ?><li class="flex items-start justify-between gap-3 py-3 text-sm"><div><p class="font-semibold"><?= U::e($s['order_no'] ?? '') ?> · <?= U::e($s['customer_name'] ?? '') ?></p><p class="text-muted"><?= U::e($s['barangay'] ?? '') ?> · Trip <?= (int) $s['trip_no'] ?> · <?= U::e($s['station_name']) ?></p><p class="text-muted"><?= (int) $s['total_units'] ?> item(s)<?= $s['state'] === 'delivered' ? ' · ' . (int) $s['empties_returned'] . ' empties collected' : ' · ' . U::e($s['failure_reason'] ?? '') ?></p></div><div class="text-right"><?= U::badge($s['state'] === 'delivered' ? 'Delivered' : 'Failed', $s['state'] === 'delivered' ? 'green' : 'red') ?><p class="mt-1 text-xs text-muted"><?= U::e(S::time($s['resolved_at'], 'M j, g:i A')) ?></p></div></li><?php endforeach ?></ul><?php endif ?></section>
