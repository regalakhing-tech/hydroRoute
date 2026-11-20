<?php
// Original rider trip design: the assigned trip's next stop, arrival with GPS, cash, empties and results.
use App\Core\Session;
use App\Services\Workspace;
use App\UI\LiveForm as F;
use App\UI\OrderStatus as S;
use App\UI\UI as U;

$live = Workspace::get()->load(static fn (Workspace $w): array => $w->trip(null));
if (isset($live['error']) || empty($live['trip'])): ?>
<?php if (!isset($live['error'])): ?><div data-poll-url="/rider/trips/current" data-poll-version="none" data-poll-seconds="20" hidden></div><?php endif ?>
<div class="mx-auto max-w-4xl space-y-4"><?= U::heading("Today’s trip") ?><section class="rounded-2xl bg-water p-5 text-white"><div class="flex items-start gap-3.5"><span class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-white/15"><?= U::icon('truck','size-6') ?></span><div><h2 class="text-lg font-bold">No trip assigned</h2><p class="mt-0.5 text-sm text-white/90"><?= U::e($live['error'] ?? 'When the station dispatches a trip to you, its stops appear here in visiting order.') ?></p></div></div>
<?php if (!isset($live['error'])): ?><p class="mt-4 inline-flex items-center gap-2 rounded-full bg-white/15 px-3 py-1.5 text-xs font-semibold"><span class="size-2 animate-pulse rounded-full bg-emerald-300"></span>Waiting for a trip · this page updates by itself</p><?php endif ?></section>
<section class="panel p-4"><h3 class="flex items-center gap-2 font-semibold"><?= U::icon('check','size-4 text-water') ?>Before your next trip</h3><ul class="mt-2 space-y-1.5 text-sm text-muted"><li class="flex gap-2"><span class="text-water">•</span>Turn on location so arrivals confirm the delivery fee.</li><li class="flex gap-2"><span class="text-water">•</span>Bring change and count the empty containers on board.</li><li class="flex gap-2"><span class="text-water">•</span>Hand over cash and empties at the station after each trip.</li></ul></section>
<div class="grid grid-cols-2 gap-2.5"><?= U::btn('Refresh', '/rider', 'primary', 'refresh') ?><?= U::btn('History', '/rider/history', 'secondary', 'clock') ?></div></div>
<?php return; endif;
$t = $live['trip']; $stops = $live['stops'];
$open = array_values(array_filter($stops, fn($s) => in_array($s['state'], ['pending','arrived'], true)));
$stop = $open[0] ?? null; $next = $open[1] ?? null;
$done = count($stops) - count($open);
// Road route on OpenStreetMap; live.js asks /rider/trips/route (destination chosen by the server) and redraws as the rider moves.
$routeMap = '<section class="panel space-y-3 p-4" data-route-map data-route-url="/rider/trips/route"><div class="flex flex-wrap items-center justify-between gap-2"><h2 class="flex items-center gap-2 font-semibold">' . U::icon('pin', 'size-4 text-water') . ($stop === null ? 'Road back to the station' : 'Road map to this stop') . '</h2><span class="text-sm text-muted" data-route-summary aria-live="polite">Finding the road route…</span></div>'
    . '<div id="route-canvas" data-keep data-route-canvas class="h-64 overflow-hidden rounded-xl border border-line bg-foam md:h-80" role="img" aria-label="Road route map"></div>'
    . '<details class="text-sm"><summary class="cursor-pointer font-semibold text-water">Turn-by-turn directions</summary><ol data-route-steps class="mt-2 list-decimal space-y-1 pl-5 text-muted"></ol></details>'
    . '<p class="text-xs text-muted">Roads from OpenStreetMap. Follow traffic signs and local conditions; the map updates as you move.</p></section>';
?>
<div data-rider-track="<?= U::e($t['id']) ?>" data-token="<?= U::e(Session::csrfToken()) ?>" hidden></div>
<div class="mx-auto max-w-4xl space-y-4">
<?= U::heading("Today’s trip") ?>
<section class="rounded-2xl bg-water p-4 text-white md:p-5">
    <div class="flex items-start gap-3">
        <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-white/15"><?= U::icon('truck','size-6') ?></span>
        <div class="min-w-0 flex-1"><div class="flex items-center justify-between gap-2"><h2 class="text-lg font-bold">Trip <?= (int) $t['trip_no'] ?></h2><span class="shrink-0 rounded-full bg-white px-3 py-1 text-xs font-bold text-water"><?= $done ?> of <?= count($stops) ?> stops</span></div>
        <p class="mt-0.5 flex items-start gap-1.5 text-sm text-white/90"><?= U::icon('store','mt-0.5 size-4 shrink-0') ?><span class="break-words"><?= U::e($live['station']['name'] ?? '') ?></span></p></div>
    </div>
    <div class="mt-4 flex gap-1.5" role="progressbar" aria-label="Trip progress" aria-valuemin="0" aria-valuemax="<?= count($stops) ?>" aria-valuenow="<?= $done ?>" aria-valuetext="<?= $done ?> of <?= count($stops) ?> stops completed"><?php foreach ($stops as $i => $s): ?><span class="h-2 flex-1 rounded-full <?= $i < $done ? 'bg-white' : 'bg-white/30' ?>"></span><?php endforeach ?></div>
    <div class="mt-3 flex flex-wrap gap-1.5 text-xs font-semibold">
        <span class="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-2.5 py-1"><?= U::icon('calendar','size-3.5') ?><?= U::e(S::time($t['dispatched_at'], 'D, M j · g:i A')) ?></span>
        <span class="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-2.5 py-1"><?= U::icon('bottle','size-3.5') ?><?= (int) $t['load_units'] ?> of <?= (int) $t['capacity'] ?> containers</span>
        <span class="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-2.5 py-1"><?= U::icon('pin','size-3.5') ?><span data-location-status>Checking location…</span></span>
        <span class="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-2.5 py-1"><?= U::icon('truck','size-3.5') ?><span data-travelled>0.0 km travelled</span></span>
    </div>
</section>
<?php if ($stop === null): ?>
<section class="panel space-y-3 p-4 md:p-6"><h2 class="text-xl font-bold">All stops have a result</h2><p class="text-sm text-muted">Return to the station, hand over the cash and empty containers, then close the trip.</p><?= F::button('/rider/trips/close', ['trip_id' => $t['id'], 'expected_version' => $t['version']], 'I am back · close trip', 'primary', 'check', null, 'rider-action w-full') ?></section>
<?= $routeMap ?>
<?php else: $o = $stop['order']; $a = $stop['attempt']; $due = ((int) ($a['amount_due_centavos'] ?? $o['amount_due_centavos'])) / 100; ?>
<section class="panel space-y-4 p-4 md:p-6">
    <div>
        <div class="flex items-center justify-between gap-3"><p class="flex items-center gap-2 text-sm font-semibold text-water"><?= U::icon('user','size-4') ?>Next delivery · stop <?= (int) $stop['sequence'] ?></p><span id="delivery-status"><?= U::badge($stop['state'] === 'arrived' ? 'Arrived' : 'Assigned', $stop['state'] === 'arrived' ? 'amber' : 'blue') ?></span></div>
        <h2 class="mt-1.5 text-2xl font-bold tracking-tight"><?= U::e($o['customer_name'] ?? '') ?></h2>
        <p class="mt-2 flex items-start gap-2 text-sm"><?= U::icon('pin','mt-0.5 size-4 shrink-0 text-water') ?><?= U::e($o['address_text'] ?? '') ?></p>
        <p class="mt-1 flex items-start gap-2 text-sm text-muted"><?= U::icon('home','mt-0.5 size-4 shrink-0') ?><?= U::e(($o['landmark'] ?? '') !== '' ? $o['landmark'] : number_format((float) $stop['station_km'], 1) . ' km from the station') ?><?php if ($o['latitude'] !== null): ?><span data-distance-to="<?= U::e($o['latitude'] . ',' . $o['longitude']) ?>" class="whitespace-nowrap empty:hidden before:mr-1 before:content-['·']"></span><?php endif ?></p>
    </div>
    <div class="grid grid-cols-2 gap-2.5"><?= U::btn('Call', 'tel:' . ($o['contact_phone'] ?? ''), 'secondary', 'phone') ?><?= U::btn('Address','','secondary','pin',['data-dialog-open'=>'rider-stop-address']) ?></div>
    <dl class="grid grid-cols-3 gap-2 text-center">
        <?php foreach ([['bottle','Items',(string)(int)$o['total_units']],['credit','To collect',U::money($due)],['refresh','Empties',(string)(int)$o['expected_empties']]] as [$icon,$label,$value]): ?><div class="flex flex-col items-center justify-center gap-1 rounded-xl bg-foam px-1.5 py-3"><dt class="flex flex-col items-center gap-1 text-xs text-muted"><?= U::icon($icon,'size-5 text-water') ?><?= U::e($label) ?></dt><dd class="whitespace-nowrap text-base font-bold"><?= U::e($value) ?></dd></div><?php endforeach ?>
    </dl>
<?php if ($stop['state'] === 'pending'): ?>
    <?= F::open('/rider/trips/arrived', ['stop_id' => $stop['id']], ['class' => 'space-y-2']) ?><input type="hidden" name="latitude" data-geo="latitude"><input type="hidden" name="longitude" data-geo="longitude"><input type="hidden" name="accuracy" data-geo="accuracy"><input type="hidden" name="fallback_reason" value="Location unavailable on the rider's phone">
    <?= U::btn('I have arrived','','primary','pin',['type'=>'submit','class'=>'rider-action w-full']) ?><p class="text-xs text-muted">Arrival uses your location once to confirm the delivery fee. It never adds a charge.</p></form>
<?php else: ?>
    <?= F::open('/rider/trips/result', ['stop_id' => $stop['id'], 'expected_version' => $stop['version'], 'result' => 'delivered'], ['class' => 'space-y-4']) ?>
    <div class="space-y-3 rounded-xl border border-line bg-white p-3.5">
        <p class="flex items-center gap-2 text-sm font-semibold"><?= U::icon('credit','size-4.5 text-water') ?>Collect payment<span class="ml-auto font-bold text-water"><?= U::money($due) ?></span></p>
        <?php if (($a['fee_decision'] ?? '') === 'lowered'): ?><p class="text-xs text-emerald-700">Delivery fee lowered after measuring the distance.</p><?php elseif (($a['fee_decision'] ?? '') === 'flagged'): ?><p class="text-xs text-amber-700">Collect the quoted amount; the station will review the distance.</p><?php endif ?>
        <div class="grid grid-cols-2 gap-3"><?= U::field('Cash received','tendered',number_format($due, 2, '.', ''),'number',['id'=>'cash-received','class'=>'no-spin','min'=>number_format($due, 2, '.', ''),'step'=>'0.01','data-amount-due'=>$due,'inputmode'=>'decimal','required'=>true]) ?><div><label class="label" for="cash-change">Change</label><output id="cash-change" class="flex min-h-11 items-center rounded-lg bg-foam px-3.5 text-lg font-bold" aria-live="polite">0.00</output></div></div>
    </div>
    <div class="flex items-center justify-between gap-3 rounded-xl border border-line bg-white p-3.5"><label class="flex items-center gap-2 text-sm font-semibold" for="empties-collected"><?= U::icon('refresh','size-4.5 text-water') ?>Empties collected</label><div class="flex shrink-0 items-center gap-1 rounded-full border border-line bg-mist p-1"><?= U::btn('','','ghost','minus',['data-step'=>'-1','data-counter'=>'empties-collected','aria-label'=>'Collect one fewer empty container','class'=>'size-9 min-h-9 rounded-full border-0 px-0']) ?><input id="empties-collected" name="empties" class="no-spin w-10 rounded bg-transparent text-center text-lg font-bold focus-visible:outline-offset-1" type="number" min="0" max="9999" value="<?= (int) $o['expected_empties'] ?>" inputmode="numeric"><?= U::btn('','','primary','plus',['data-step'=>'1','data-counter'=>'empties-collected','aria-label'=>'Collect one more empty container','class'=>'size-9 min-h-9 rounded-full px-0']) ?></div></div>
    <div class="grid gap-2.5 sm:grid-cols-2"><?= U::btn('Mark delivered','','primary','check',['type'=>'submit','class'=>'rider-action w-full','data-confirm'=>'Mark this order delivered and paid?']) ?><?= U::btn('Report a problem','','danger','alert',['class'=>'rider-action w-full','data-dialog-open'=>'rider-problem']) ?></div>
    </form>
<?php endif ?>
    <p class="text-xs text-muted">Your location is shared with your station only during this trip.</p>
</section>
<?php if ($o['latitude'] !== null): ?><?= $routeMap ?><?php endif ?>
<?php $pendingCount = count(array_filter($open, static fn (array $s): bool => $s['state'] === 'pending')); if ($pendingCount >= 2): ?>
<?= F::open('/rider/trips/replan', ['trip_id' => $t['id']], ['class' => 'panel flex flex-wrap items-center justify-between gap-3 p-4']) ?><input type="hidden" name="latitude" data-geo="latitude"><input type="hidden" name="longitude" data-geo="longitude">
<p class="text-sm text-muted"><strong class="block text-ink">Changed your route?</strong>Re-plan the <?= $pendingCount ?> remaining stops nearest-first from where you are now.</p>
<?= U::btn('Re-plan from here', '', 'secondary', 'refresh', ['type' => 'submit', 'data-confirm' => 'Re-plan the remaining stops from your current location?']) ?></form>
<?php endif ?>
<?php if ($next): ?><section class="panel flex items-center gap-3 p-4">
    <span class="title-icon size-10"><?= U::icon('arrow-right','size-5') ?></span>
    <div class="min-w-0"><p class="text-xs font-semibold text-muted">Next stop after this</p><h2 class="truncate font-bold"><?= U::e($next['order']['customer_name'] ?? '') ?></h2><p class="truncate text-sm text-muted"><?= U::e($next['order']['address_text'] ?? '') ?><?= ($next['order']['landmark'] ?? '') !== '' ? ', ' . U::e($next['order']['landmark']) : '' ?></p></div>
</section><?php endif ?>
<dialog id="rider-stop-address" aria-labelledby="rider-address-title"><div class="flex items-start justify-between gap-4"><h2 id="rider-address-title" class="section-title flex items-center gap-2.5"><span class="title-icon"><?= U::icon('pin','size-5') ?></span>Delivery address</h2><button type="button" data-dialog-close aria-label="Close address"><?= U::icon('close') ?></button></div><h3 class="mt-4 font-bold"><?= U::e($o['customer_name'] ?? '') ?></h3><p class="mt-1"><?= U::e($o['address_text'] ?? '') ?></p><p class="mt-1 text-sm text-muted"><?= U::e($o['landmark'] ?? '') ?></p><p class="mt-1 text-sm"><?= U::e(implode(', ', array_map(fn($i) => $i['quantity'] . ' × ' . $i['name'], $o['items'] ?? []))) ?></p><?php if (($o['note'] ?? '') !== ''): ?><p class="mt-2 text-sm">Note: <?= U::e($o['note']) ?></p><?php endif ?></dialog>
<?php if ($stop['state'] === 'arrived'): ?><dialog id="rider-problem" aria-labelledby="rider-problem-title"><div class="flex items-start justify-between gap-4"><h2 id="rider-problem-title" class="section-title">Report a problem</h2><button type="button" data-dialog-close aria-label="Close"><?= U::icon('close') ?></button></div><?= F::open('/rider/trips/result', ['stop_id' => $stop['id'], 'expected_version' => $stop['version'], 'result' => 'failed'], ['class' => 'mt-4 space-y-3']) ?><?= U::select('Reason', 'failure_reason', ['Customer unavailable' => 'Customer unavailable', 'Address not found' => 'Address not found', 'Access problem' => 'Access problem', 'Customer refused' => 'Customer refused'], 'Customer unavailable') ?><?= U::field('Details (optional)', 'failure_detail', '', 'text', ['maxlength' => '380']) ?><?= U::btn('Record failed delivery', '', 'danger', 'alert', ['type' => 'submit', 'class' => 'w-full', 'data-confirm' => 'Record a failed delivery? The station must approve another try.']) ?></form></dialog><?php endif ?>
<?php endif ?>
</div>
