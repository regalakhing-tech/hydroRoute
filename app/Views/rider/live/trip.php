<?php
// Original rider trip design: the assigned trip's next stop, arrival with GPS, cash, empties and results.
use App\Core\Session;
use App\Services\Workspace;
use App\UI\LiveForm as F;
use App\UI\OrderStatus as S;
use App\UI\UI as U;

$live = Workspace::get()->load(static fn (Workspace $w): array => $w->trip(null));
if (isset($live['error']) || empty($live['trip'])): ?>
<div class="mx-auto max-w-4xl space-y-4"><?= U::heading("Today’s trip") ?><section class="rounded-2xl bg-water p-4 text-white md:p-5"><div class="flex items-center gap-3"><span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-white/15"><?= U::icon('truck','size-6') ?></span><div><h2 class="text-lg font-bold">No trip assigned</h2><p class="text-sm"><?= U::e($live['error'] ?? 'When the station dispatches a trip to you, its stops appear here in visiting order.') ?></p></div></div></section><?= U::btn('Refresh', '/rider', 'primary', 'refresh', ['class' => 'w-full']) ?></div>
<?php return; endif;
$t = $live['trip']; $stops = $live['stops'];
$open = array_values(array_filter($stops, fn($s) => in_array($s['state'], ['pending','arrived'], true)));
$stop = $open[0] ?? null; $next = $open[1] ?? null;
$done = count($stops) - count($open);
?>
<div data-rider-track="<?= U::e($t['id']) ?>" data-token="<?= U::e(Session::csrfToken()) ?>" hidden></div>
<div class="mx-auto max-w-4xl space-y-4">
<?= U::heading("Today’s trip") ?>
<section class="rounded-2xl bg-water p-4 text-white md:p-5">
    <div class="flex items-center justify-between gap-3">
        <div class="flex min-w-0 items-center gap-3"><span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-white/15"><?= U::icon('truck','size-6') ?></span><div class="min-w-0"><h2 class="text-lg font-bold">Trip <?= (int) $t['trip_no'] ?></h2><p class="flex items-center gap-1.5 truncate text-sm"><?= U::icon('store','size-4 shrink-0') ?><?= U::e($live['station']['name'] ?? '') ?></p></div></div>
        <span class="shrink-0 rounded-full bg-white px-3 py-1 text-xs font-bold text-water"><?= $done ?> of <?= count($stops) ?> stops</span>
    </div>
    <div class="mt-4 flex gap-1.5" role="progressbar" aria-label="Trip progress" aria-valuemin="0" aria-valuemax="<?= count($stops) ?>" aria-valuenow="<?= $done ?>" aria-valuetext="<?= $done ?> of <?= count($stops) ?> stops completed"><?php foreach ($stops as $i => $s): ?><span class="h-2 flex-1 rounded-full <?= $i < $done ? 'bg-white' : 'bg-white/30' ?>"></span><?php endforeach ?></div>
    <p class="mt-3 flex items-center gap-1.5 text-sm"><?= U::icon('calendar','size-4') ?><?= U::e(S::time($t['dispatched_at'], 'l, F j, Y')) ?> · <span data-location-status>Checking location…</span></p>
</section>
<?php if ($stop === null): ?>
<section class="panel space-y-3 p-4 md:p-6"><h2 class="text-xl font-bold">All stops have a result</h2><p class="text-sm text-muted">Return to the station, hand over the cash and empty containers, then close the trip.</p><?= F::button('/rider/trips/close', ['trip_id' => $t['id'], 'expected_version' => $t['version']], 'I am back · close trip', 'primary', 'check', null, 'rider-action w-full') ?></section>
<?php else: $o = $stop['order']; $a = $stop['attempt']; $due = ((int) ($a['amount_due_centavos'] ?? $o['amount_due_centavos'])) / 100; ?>
<section class="panel space-y-4 p-4 md:p-6">
    <div>
        <div class="flex items-center justify-between gap-3"><p class="flex items-center gap-2 text-sm font-semibold text-water"><?= U::icon('user','size-4') ?>Next delivery · stop <?= (int) $stop['sequence'] ?></p><span id="delivery-status"><?= U::badge($stop['state'] === 'arrived' ? 'Arrived' : 'Assigned', $stop['state'] === 'arrived' ? 'amber' : 'blue') ?></span></div>
        <h2 class="mt-1.5 text-2xl font-bold tracking-tight"><?= U::e($o['customer_name'] ?? '') ?></h2>
        <p class="mt-2 flex items-start gap-2 text-sm"><?= U::icon('pin','mt-0.5 size-4 shrink-0 text-water') ?><?= U::e($o['address_text'] ?? '') ?></p>
        <p class="mt-1 flex items-start gap-2 text-sm text-muted"><?= U::icon('home','mt-0.5 size-4 shrink-0') ?><?= U::e(($o['landmark'] ?? '') !== '' ? $o['landmark'] : number_format((float) $stop['station_km'], 1) . ' km from the station') ?><?php if ($o['latitude'] !== null): ?> · <span data-distance-to="<?= U::e($o['latitude'] . ',' . $o['longitude']) ?>"></span><?php endif ?></p>
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
<?php if ($next): ?><section class="panel flex items-center gap-3 p-4">
    <span class="title-icon size-10"><?= U::icon('arrow-right','size-5') ?></span>
    <div class="min-w-0"><p class="text-xs font-semibold text-muted">Next stop after this</p><h2 class="truncate font-bold"><?= U::e($next['order']['customer_name'] ?? '') ?></h2><p class="truncate text-sm text-muted"><?= U::e($next['order']['address_text'] ?? '') ?><?= ($next['order']['landmark'] ?? '') !== '' ? ', ' . U::e($next['order']['landmark']) : '' ?></p></div>
</section><?php endif ?>
<dialog id="rider-stop-address" aria-labelledby="rider-address-title"><div class="flex items-start justify-between gap-4"><h2 id="rider-address-title" class="section-title flex items-center gap-2.5"><span class="title-icon"><?= U::icon('pin','size-5') ?></span>Delivery address</h2><button type="button" data-dialog-close aria-label="Close address"><?= U::icon('close') ?></button></div><h3 class="mt-4 font-bold"><?= U::e($o['customer_name'] ?? '') ?></h3><p class="mt-1"><?= U::e($o['address_text'] ?? '') ?></p><p class="mt-1 text-sm text-muted"><?= U::e($o['landmark'] ?? '') ?></p><p class="mt-1 text-sm"><?= U::e(implode(', ', array_map(fn($i) => $i['quantity'] . ' × ' . $i['name'], $o['items'] ?? []))) ?></p><?php if (($o['note'] ?? '') !== ''): ?><p class="mt-2 text-sm">Note: <?= U::e($o['note']) ?></p><?php endif ?></dialog>
<?php if ($stop['state'] === 'arrived'): ?><dialog id="rider-problem" aria-labelledby="rider-problem-title"><div class="flex items-start justify-between gap-4"><h2 id="rider-problem-title" class="section-title">Report a problem</h2><button type="button" data-dialog-close aria-label="Close"><?= U::icon('close') ?></button></div><?= F::open('/rider/trips/result', ['stop_id' => $stop['id'], 'expected_version' => $stop['version'], 'result' => 'failed'], ['class' => 'mt-4 space-y-3']) ?><?= U::select('Reason', 'failure_reason', ['Customer unavailable' => 'Customer unavailable', 'Address not found' => 'Address not found', 'Access problem' => 'Access problem', 'Customer refused' => 'Customer refused'], 'Customer unavailable') ?><?= U::field('Details (optional)', 'failure_detail', '', 'text', ['maxlength' => '380']) ?><?= U::btn('Record failed delivery', '', 'danger', 'alert', ['type' => 'submit', 'class' => 'w-full', 'data-confirm' => 'Record a failed delivery? The station must approve another try.']) ?></form></dialog><?php endif ?>
<?php endif ?>
</div>
