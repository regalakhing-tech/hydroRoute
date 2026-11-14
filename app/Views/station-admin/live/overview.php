<?php
// Original station overview design with this station's live numbers.
use App\Services\Workspace;
use App\UI\OrderStatus as S;
use App\UI\UI as U;

$live = Workspace::get()->load(static fn (Workspace $w): array => $w->overview());
if (isset($live['error'])): ?><?= U::heading('Station overview') ?><section class="panel p-6" role="alert"><?= U::e($live['error']) ?></section><?php return; endif;
$c = $live['counts']; $av = $live['availability'];
$queue = array_merge($live['queue'], $live['ready_delivery']);
$top = $live['queue'][0] ?? null;
$badge = !empty($av['accepting_asap']) ? 'Open until ' . S::time($av['next_close_at'] ?? null, 'g:i A') : ($av['label'] ?? 'Closed');
$waiting = (int) $c['new'] + (int) $c['preparing'] + (int) $c['ready'];
?>
<?= U::heading('Station overview', (new DateTimeImmutable($live['today']))->format('l, F j, Y'), U::badge($badge, !empty($av['accepting_asap']) ? 'green' : 'amber')) ?>
<div class="mb-5 grid grid-cols-2 gap-3 md:gap-4 xl:grid-cols-4">
    <?= U::stat('Orders today', (string) $c['orders_today'], 'orders', 'blue', 'Refill orders received today', 'Today') ?>
    <?= U::stat('Collected payments', S::pesos($live['cash_verified_today']), 'credit', 'green', 'Cash verified by the station', 'Cash') ?>
    <?= U::stat('Available drivers', (string) $live['riders_free'], 'truck', 'violet', 'Riders ready for the next trip', 'Riders') ?>
    <?= U::stat('In the queue', (string) $waiting, 'clock', 'amber', 'Orders waiting for action', 'Queue') ?>
</div>
<div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_340px]">
<section class="panel p-4 md:p-5"><div class="mb-4 flex flex-wrap items-center justify-between gap-3"><h2 class="section-title flex items-center gap-2.5"><span class="title-icon"><?= U::icon('orders','size-5') ?></span>Order queue</h2><?= U::btn('View full queue','/station-admin/orders','ghost','chevron-right',['class'=>'btn-sm']) ?></div>
<?php if ($live['queue'] === []): ?><p class="p-6 text-center text-sm text-muted">No orders waiting right now.</p><?php else: ?>
<?= U::table(['Order','Customer','Barangay','Containers','Priority','Status','Action'],array_map(fn($o)=>['<strong>'.U::e($o['order_no']).'</strong>',U::e($o['customer_name'] ?? ''),U::e($o['fulfillment']==='pickup'?'Pickup':($o['barangay'] ?? '')),(int) $o['total_units'],'<span class="inline-flex min-w-9 justify-center rounded-md bg-ink px-2 py-0.5 text-xs font-bold text-white">'.(int) $o['score']['p'].'</span>',U::badge(S::label($o['status'],$o['fulfillment']),$o['status']==='ready'?'green':'blue'),U::btn('View','/station-admin/orders?order='.$o['id'],'secondary','eye')],array_slice($live['queue'],0,3))) ?><?php endif ?></section>
<aside class="panel p-4 md:p-5"><h2 class="section-title flex items-center gap-2.5"><span class="title-icon"><?= U::icon('chart','size-5') ?></span>Priority breakdown</h2>
<?php if ($top === null): ?><p class="mt-1 mb-4 text-sm text-muted">The next order's score appears here.</p><?php else: ?><p class="mt-1 mb-4 text-sm text-muted"><?= U::e($top['order_no']) ?>, <?= U::e($top['customer_name'] ?? '') ?></p>
<dl class="space-y-2"><?php foreach([['alert','Urgency',$top['score']['u']],['clock','Waiting',$top['score']['a']],['user','Customer type',$top['score']['c']]] as [$icon,$l,$v]): ?><div class="flex items-center justify-between rounded-lg bg-mist px-3 py-2.5"><dt class="flex items-center gap-2 text-sm"><?= U::icon($icon,'size-4 text-water') ?><?= U::e($l) ?></dt><dd class="font-semibold"><?= (int) $v ?></dd></div><?php endforeach ?><div class="flex items-center justify-between rounded-lg bg-water px-3 py-2.5 font-bold text-white"><dt class="flex items-center gap-2"><?= U::icon('check','size-4') ?>Total score</dt><dd><?= (int) $top['score']['p'] ?></dd></div></dl><?php endif ?>
<?= U::btn('Manage priority rules','/station-admin/settings/order-rules','ghost','settings',['class'=>'mt-4 w-full']) ?></aside>
</div>
<section class="panel mt-5 p-4 md:p-5"><div class="mb-4 flex flex-wrap items-center justify-between gap-3"><h2 class="section-title flex items-center gap-2.5"><span class="title-icon"><?= U::icon('truck','size-5') ?></span>Ready for dispatch</h2><div class="flex flex-wrap gap-2"><?= U::btn('Manage schedule','/station-admin/schedules','ghost','calendar',['class'=>'btn-sm']) ?><?= U::btn('Build a trip','/station-admin/deliveries#dispatch','primary','truck',['class'=>'btn-sm']) ?></div></div>
<?php if ($live['ready_delivery'] === []): ?><p class="p-6 text-center text-sm text-muted">No delivery order is ready.</p><?php else: ?>
<?= U::table(['Order','Customer','Barangay','Containers','Ready since'],array_map(fn($o)=>['<strong>'.U::e($o['order_no']).'</strong>',U::e($o['customer_name'] ?? ''),U::e($o['barangay'] ?? ''),(int) $o['total_units'],'<span class="inline-flex items-center gap-1.5">'.U::icon('clock','size-4 text-muted').U::e(S::time($o['queued_at'] ?? $o['created_at'], 'g:i A')).'</span>'],$live['ready_delivery'])) ?><?php endif ?></section>
