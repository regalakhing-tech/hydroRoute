<?php
use App\Services\Workspace;
use App\UI\LiveForm as F;
use App\UI\OrderStatus as S;
use App\UI\UI as U;

$id = is_string($_GET['trip'] ?? null) ? $_GET['trip'] : '';
$live = Workspace::get()->load(static fn (Workspace $w): array => $id === '' ? ['error_code' => 'not_found'] : $w->trip($id));
if (isset($live['error']) || isset($live['error_code']) || empty($live['trip'])): ?>
<?= U::heading('Trip not found', 'Choose a trip from the Deliveries page.') ?><?= U::btn('Deliveries', '/station-admin/deliveries', 'primary', 'arrow-left') ?>
<?php return; endif;
$t = $live['trip']; $stops = $live['stops']; $done = array_filter($stops, static fn (array $s): bool => in_array($s['state'], ['delivered', 'failed'], true));
$stateBadge = ['pending' => ['Pending', 'gray'], 'arrived' => ['Arrived', 'amber'], 'delivered' => ['Delivered', 'green'], 'failed' => ['Failed', 'red']];
?>
<?php if ($t['status'] === 'active'): ?><div data-poll-url="/station-admin/trips/status?trip=<?= U::e($t['id']) ?>" data-poll-version="<?= (int) $t['version'] ?>:<?= count($done) ?>" data-poll-seconds="30" hidden></div><?php endif ?>
<?= U::heading('Trip ' . (int) $t['trip_no'], ($t['rider_name'] ?? 'Rider') . ' · dispatched ' . S::time($t['dispatched_at']), U::btn('Back to deliveries', '/station-admin/deliveries', 'secondary', 'arrow-left')) ?>
<div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_340px]"><section class="panel p-4 md:p-5"><div class="mb-4 flex items-center justify-between"><?= U::title('Stops (SSTF order)', 'truck', '') ?><?= U::badge($t['status'] === 'active' ? 'On the road' : 'Closed', $t['status'] === 'active' ? 'blue' : 'green') ?></div>
<?= U::table(['#', 'Order', 'Customer', 'Distance', 'Result'], array_map(static function (array $s) use ($stateBadge): array {
    $a = $s['attempt']; $o = $s['order'];
    $result = U::badge(...$stateBadge[$s['state']]);
    if ($a) $result .= '<span class="mt-1 block text-xs text-muted">' . U::e(match ($a['fee_decision']) { 'lowered' => 'Fee lowered on arrival', 'flagged' => 'Fee flagged for review', 'fallback' => 'Quoted fee kept (no GPS)', default => 'Fee confirmed' }) . ($a['failure_reason'] ? ' · ' . U::e($a['failure_reason']) : '') . '</span>';
    return [(int) $s['sequence'] . ($s['manual_front_reason'] ? '<span class="block text-[11px] text-muted" title="' . U::e($s['manual_front_reason']) . '">moved first</span>' : ''),
        '<a class="font-semibold text-water" href="/station-admin/orders?order=' . U::e($o['id']) . '">' . U::e($o['order_no']) . '</a>', U::e($o['customer_name'] ?? '') . '<span class="block text-xs text-muted">' . U::e($o['barangay'] ?? '') . '</span>',
        U::e(number_format((float) $s['station_km'], 2)) . ' km from station', $result];
}, $stops)) ?></section>
<aside class="panel h-fit space-y-4 p-4 md:p-5"><?= U::title('Summary', 'chart', '') ?><dl class="space-y-2 text-sm"><div class="flex justify-between"><dt>Load</dt><dd><?= (int) $t['load_units'] ?> / <?= (int) $t['capacity'] ?></dd></div><div class="flex justify-between"><dt>Stops done</dt><dd><?= count($done) ?> / <?= count($stops) ?></dd></div><div class="flex justify-between"><dt>Planned stop distance</dt><dd><?= U::e(number_format((float) $t['planned_km'], 1)) ?> km</dd></div><?php if ($t['traveled_km'] !== null): ?><div class="flex justify-between"><dt>Travelled (GPS)</dt><dd><?= U::e(number_format((float) $t['traveled_km'], 1)) ?> km</dd></div><?php endif ?>
<?php if (!empty($live['last_location'])): $l = $live['last_location']; ?><div class="flex justify-between"><dt>Last position</dt><dd><?= U::e(S::time($l['recorded_at'], 'g:i A')) ?> (±<?= (int) $l['accuracy'] ?> m)</dd></div><?php endif ?></dl>
<p class="text-xs text-muted">Rider positions are kept for 30 days and are visible only to this station's admins.</p>
<?php if ($t['status'] === 'active'): ?><div class="border-t border-line pt-4"><p class="mb-2 text-sm">Close the trip when the rider is back. Closing with open stops returns those orders to ready, paused for review.</p><?= F::open('/station-admin/trips/close', ['trip_id' => $t['id'], 'expected_version' => $t['version']], ['class' => 'space-y-2']) ?><?= U::field('Reason (needed if stops are still open)', 'reason', '', 'text', ['maxlength' => '300']) ?><?= U::btn('Close trip', '', 'secondary', 'check', ['type' => 'submit', 'data-confirm' => 'Close this trip and free the rider?']) ?></form></div><?php endif ?>
</aside></div>
