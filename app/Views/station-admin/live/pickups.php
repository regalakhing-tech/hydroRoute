<?php
use App\Services\Workspace;
use App\UI\LiveForm as F;
use App\UI\OrderStatus as S;
use App\UI\UI as U;

$live = Workspace::get()->load(static fn (Workspace $w): array => $w->queue());
?>
<?= U::heading('Pickup orders', 'Prepare water for collection, record the cash at the counter, then verify and complete.') ?>
<?php if (isset($live['error'])): ?><section class="panel p-6" role="alert"><?= U::e($live['error']) ?></section><?php return; endif;
$pick = static fn (array $rows): array => array_values(array_filter($rows, static fn (array $o): bool => $o['fulfillment'] === 'pickup'));
$waiting = $pick(array_merge($live['queue'], $live['preparing'])); $ready = $pick($live['ready']); $done = $pick($live['awaiting_completion']); ?>
<div class="mb-5 grid grid-cols-2 gap-3 xl:grid-cols-3"><?= U::stat('Being prepared', (string) count($waiting), 'clock', 'amber', 'Confirmed or preparing') ?><?= U::stat('Ready for pickup', (string) count($ready), 'check', 'green', 'Customer can collect now') ?><?= U::stat('Collected', (string) count($done), 'credit', 'blue', 'Verify cash, then complete') ?></div>
<section class="panel mb-5 p-4 md:p-5"><?= U::title('Ready for pickup', 'store', 'mb-3') ?><?php if ($ready === []): ?><p class="p-4 text-center text-muted">No pickup is ready.</p><?php endif ?>
<?php foreach ($ready as $o): ?><div class="mb-3 grid gap-3 rounded-lg border border-line p-3 md:grid-cols-[1fr_auto] md:items-end"><div><p><a class="font-bold text-water" href="/station-admin/orders?order=<?= U::e($o['id']) ?>"><?= U::e($o['order_no']) ?></a> · <?= U::e($o['customer_name'] ?? '') ?> · <?= (int) $o['total_units'] ?> item(s)</p><p class="text-sm">Collect <strong><?= S::pesos($o['total_centavos']) ?></strong> in cash.</p></div>
<?= F::open('/station-admin/pickups/collect', ['order_id' => $o['id'], 'expected_version' => $o['version'], 'back' => '/station-admin/pickups'], ['class' => 'flex flex-wrap items-end gap-2']) ?><div class="w-32"><label class="label" for="t-<?= U::e($o['id']) ?>">Cash received</label><input class="field" id="t-<?= U::e($o['id']) ?>" name="tendered" inputmode="decimal" required pattern="\d{1,7}(\.\d{1,2})?" placeholder="<?= U::e(number_format($o['total_centavos'] / 100, 2, '.', '')) ?>"></div><div class="w-28"><label class="label" for="e-<?= U::e($o['id']) ?>">Empties back</label><input class="field" id="e-<?= U::e($o['id']) ?>" name="empties" type="number" min="0" max="9999" required value="0"></div><?= U::btn('Record pickup', '', 'primary', 'check', ['type' => 'submit', 'class' => 'btn-sm', 'data-confirm' => 'Record this pickup as collected and paid?']) ?></form></div><?php endforeach ?></section>
<div class="grid gap-5 xl:grid-cols-2">
<section class="panel p-4 md:p-5"><?= U::title('Being prepared', 'clock', 'mb-3') ?><?php if ($waiting === []): ?><p class="text-sm text-muted">None.</p><?php endif ?><?php foreach ($waiting as $o): ?><div class="flex flex-wrap items-center justify-between gap-2 border-t border-line py-2 text-sm"><span><a class="font-semibold text-water" href="/station-admin/orders?order=<?= U::e($o['id']) ?>"><?= U::e($o['order_no']) ?></a> · <?= U::e($o['customer_name'] ?? '') ?> · <?= S::badge($o['status'], 'pickup') ?></span><?= $o['status'] === 'preparing'
    ? F::button('/station-admin/orders/ready', ['order_id' => $o['id'], 'expected_version' => $o['version'], 'back' => '/station-admin/pickups'], 'Mark ready', 'primary', 'check')
    : F::button('/station-admin/orders/start', ['order_id' => $o['id'], 'expected_version' => $o['version'], 'back' => '/station-admin/pickups'], 'Start preparing', 'secondary', 'check') ?></div><?php endforeach ?></section>
<section class="panel p-4 md:p-5"><?= U::title('Collected', 'credit', 'mb-3') ?><?php if ($done === []): ?><p class="text-sm text-muted">None.</p><?php endif ?><?php foreach ($done as $o): ?><p class="border-t border-line py-2 text-sm"><a class="font-semibold text-water" href="/station-admin/orders?order=<?= U::e($o['id']) ?>"><?= U::e($o['order_no']) ?></a> · <?= U::e($o['customer_name'] ?? '') ?> · open the order to verify cash and complete it</p><?php endforeach ?></section>
</div>
