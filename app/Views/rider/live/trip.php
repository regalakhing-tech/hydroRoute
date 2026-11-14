<?php
use App\Core\Session;
use App\Services\Workspace;
use App\UI\LiveForm as F;
use App\UI\OrderStatus as S;
use App\UI\UI as U;

$live = Workspace::get()->load(static fn (Workspace $w): array => $w->trip(null));
if (isset($live['error'])): ?><?= U::heading('My trip') ?><section class="panel p-6" role="alert"><?= U::e($live['error']) ?></section><?php return; endif;
if (empty($live['trip'])): ?>
<?= U::heading('My trip', 'No trip is assigned to you right now.') ?>
<section class="panel p-6 text-center"><p class="text-muted">When the station dispatches a trip to you, its stops appear here in visiting order.</p><div class="mt-4 flex justify-center gap-2"><?= U::btn('Refresh', '/rider', 'primary', 'refresh') ?><?= U::btn('History', '/rider/history', 'secondary', 'clock') ?></div></section>
<?php return; endif;
$t = $live['trip']; $stops = $live['stops'];
$open = array_values(array_filter($stops, static fn (array $s): bool => in_array($s['state'], ['pending', 'arrived'], true)));
$current = $open[0] ?? null; $doneCount = count($stops) - count($open);
?>
<div data-rider-track="<?= U::e($t['id']) ?>" data-token="<?= U::e(Session::csrfToken()) ?>" hidden></div>
<?= U::heading('Trip ' . (int) $t['trip_no'], ($live['station']['name'] ?? '') . ' · ' . $doneCount . ' of ' . count($stops) . ' stops done') ?>
<p class="mb-4 flex items-start gap-2 rounded-xl border border-line bg-foam p-3 text-sm"><?= U::icon('pin', 'size-4 mt-0.5 shrink-0 text-water') ?><span><span data-location-status>Checking your location…</span> During this trip only, your phone shares its position about every 30 seconds so the station can follow the delivery and confirm the distance fee. If location is off, you can still record each stop with a reason.</span></p>
<?php if ($current !== null): $o = $current['order']; $a = $current['attempt']; ?>
<section class="panel mb-5 p-4 md:p-5" aria-labelledby="stop-title"><div class="flex items-start justify-between gap-3"><div><p class="text-xs font-semibold uppercase tracking-wide text-water">Stop <?= (int) $current['sequence'] ?><?= $current['manual_front_reason'] ? ' · moved first: ' . U::e($current['manual_front_reason']) : '' ?></p><h2 id="stop-title" class="mt-1 text-xl font-bold"><?= U::e($o['customer_name'] ?? '') ?></h2></div><?php if (!empty($o['contact_phone'])): ?><?= U::btn('Call', 'tel:' . $o['contact_phone'], 'secondary', 'phone') ?><?php endif ?></div>
<p class="mt-2"><?= U::e($o['address_text'] ?? '') ?></p><?php if (!empty($o['landmark'])): ?><p class="text-sm text-muted">Landmark: <?= U::e($o['landmark']) ?></p><?php endif ?>
<p class="mt-1 text-sm text-muted"><?= U::e(number_format((float) $current['station_km'], 1)) ?> km from the station<?php if ($o['latitude'] !== null): ?> · <span data-distance-to="<?= U::e($o['latitude'] . ',' . $o['longitude']) ?>"></span><?php endif ?></p>
<ul class="mt-3 rounded-lg bg-mist p-3 text-sm"><?php foreach ($o['items'] ?? [] as $i): ?><li><?= (int) $i['quantity'] ?> × <?= U::e($i['name']) ?></li><?php endforeach ?><li class="mt-1 text-muted">Empty containers to collect: <?= (int) $o['expected_empties'] ?></li></ul>
<?php if ($o['note'] !== ''): ?><p class="mt-2 rounded-lg bg-foam p-3 text-sm">Note: <?= U::e($o['note']) ?></p><?php endif ?>
<?php if ($current['state'] === 'pending'): ?>
<p class="mt-4 text-lg">To collect: <strong><?= S::pesos($o['amount_due_centavos']) ?></strong> <span class="text-sm text-muted">(confirmed on arrival)</span></p>
<?= F::open('/rider/trips/arrived', ['stop_id' => $current['id']], ['class' => 'mt-3 space-y-3']) ?><input type="hidden" name="latitude" data-geo="latitude"><input type="hidden" name="longitude" data-geo="longitude"><input type="hidden" name="accuracy" data-geo="accuracy">
<details class="text-sm"><summary class="cursor-pointer font-semibold text-muted">Location not working?</summary><div class="mt-2"><label class="label" for="fallback">Why location is unavailable</label><select class="field" id="fallback" name="fallback_reason" data-geo-fallback><option value="Location permission denied">Location permission denied</option><option value="No GPS signal">No GPS signal</option><option value="Phone battery saver">Phone battery saver</option></select><p class="hint">The quoted fee stays when the distance cannot be measured.</p></div></details>
<?= U::btn('I have arrived', '', 'primary', 'pin', ['type' => 'submit', 'class' => 'w-full']) ?></form>
<?php else: ?>
<p class="mt-4 text-lg">Collect: <strong><?= S::pesos($a['amount_due_centavos'] ?? $o['amount_due_centavos']) ?></strong></p>
<?php if (($a['fee_decision'] ?? '') === 'lowered'): ?><p class="text-sm text-emerald-700">The delivery fee was lowered after measuring the distance.</p><?php elseif (($a['fee_decision'] ?? '') === 'flagged'): ?><p class="text-sm text-amber-700">The measured distance is longer than quoted. Collect the quoted amount; the station will review it.</p><?php endif ?>
<?= F::open('/rider/trips/result', ['stop_id' => $current['id'], 'expected_version' => $current['version'], 'result' => 'delivered'], ['class' => 'mt-3 grid gap-3 sm:grid-cols-3 sm:items-end']) ?><div><label class="label" for="tendered">Cash received (PHP)</label><input class="field" id="tendered" name="tendered" inputmode="decimal" required pattern="\d{1,7}(\.\d{1,2})?" placeholder="<?= U::e(number_format(($a['amount_due_centavos'] ?? $o['amount_due_centavos']) / 100, 2, '.', '')) ?>"></div><div><label class="label" for="empties">Empties collected</label><input class="field" id="empties" name="empties" type="number" min="0" max="9999" required value="<?= (int) $o['expected_empties'] ?>"></div><?= U::btn('Delivered', '', 'primary', 'check', ['type' => 'submit', 'data-confirm' => 'Record this order as delivered and paid? Give the change shown next.']) ?></form>
<details class="mt-4 rounded-lg border border-line p-3"><summary class="cursor-pointer font-semibold text-rose-700">Could not deliver</summary><?= F::open('/rider/trips/result', ['stop_id' => $current['id'], 'expected_version' => $current['version'], 'result' => 'failed'], ['class' => 'mt-3 space-y-3']) ?><?= U::select('Reason', 'failure_reason', ['Customer unavailable' => 'Customer unavailable', 'Address not found' => 'Address not found', 'Access problem' => 'Access problem', 'Customer refused' => 'Customer refused'], 'Customer unavailable') ?><?= U::field('Details (optional)', 'failure_detail', '', 'text', ['maxlength' => '380']) ?><?= U::btn('Record failed attempt', '', 'secondary', 'alert', ['type' => 'submit', 'data-confirm' => 'Record a failed delivery? The station must approve another try.']) ?></form></details>
<?php endif ?></section>
<?php else: ?>
<section class="panel mb-5 p-5"><h2 class="font-bold">All stops have a result</h2><p class="mt-1 text-sm text-muted">Return to the station, hand over the cash and empties, then close the trip.</p><?= F::open('/rider/trips/close', ['trip_id' => $t['id'], 'expected_version' => $t['version']], ['class' => 'mt-3']) ?><?= U::btn('I am back · close trip', '', 'primary', 'check', ['type' => 'submit', 'class' => 'w-full']) ?></form></section>
<?php endif ?>
<section class="panel p-4 md:p-5"><?= U::title('All stops', 'truck', 'mb-3') ?><ol class="space-y-2"><?php foreach ($stops as $s): ?><li class="flex items-center gap-3 rounded-lg border border-line p-3 text-sm <?= $current && $s['id'] === $current['id'] ? 'border-water bg-foam' : '' ?>"><span class="flex size-7 shrink-0 items-center justify-center rounded-full <?= in_array($s['state'], ['delivered', 'failed'], true) ? 'bg-water text-white' : 'bg-mist' ?>"><?= (int) $s['sequence'] ?></span><span class="min-w-0 flex-1"><strong><?= U::e($s['order']['customer_name'] ?? '') ?></strong><span class="block truncate text-xs text-muted"><?= U::e($s['order']['address_text'] ?? '') ?></span></span><?= U::badge(ucfirst($s['state']), ['delivered' => 'green', 'failed' => 'red', 'arrived' => 'amber'][$s['state']] ?? 'gray') ?></li><?php endforeach ?></ol></section>
