<?php if (!($data['preview'] ?? false)) { require __DIR__ . '/live/overview.php'; return; } ?>
<?php use App\UI\UI as U; $s = $data['sample']; ?>
<?= U::heading('Station overview', 'Monday, November 2, 2026', U::badge('Open until 6:00 PM', 'green')) ?>
<div class="mb-5 grid grid-cols-2 gap-3 md:gap-4 xl:grid-cols-4">
    <?= U::stat('Orders today', '24', 'orders', 'blue', 'Refill orders received today', 'Today') ?>
    <?= U::stat('Collected payments', 'PHP 3,450', 'credit', 'green', 'Cash verified by the station', 'Cash') ?>
    <?= U::stat('Available drivers', '3', 'truck', 'violet', 'Riders ready for the next trip', 'Riders') ?>
    <?= U::stat('In the queue', (string)count($s['orders']), 'clock', 'amber', 'Orders waiting for action', 'Queue') ?>
</div>
<div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_340px]">
<section class="panel p-4 md:p-5"><div class="mb-4 flex flex-wrap items-center justify-between gap-3"><h2 class="section-title flex items-center gap-2.5"><span class="title-icon"><?= U::icon('orders','size-5') ?></span>Order queue</h2><?= U::btn('View full queue','/station-admin/orders','ghost','chevron-right',['class'=>'btn-sm']) ?></div>
<?= U::table(['Order','Customer','Barangay','Containers','Priority','Status','Action'],array_map(fn($o)=>['<strong>'.U::e($o[0]).'</strong>',U::e($o[1]),U::e($o[2]),$o[3],'<span class="inline-flex min-w-9 justify-center rounded-md bg-ink px-2 py-0.5 text-xs font-bold text-white">'.$o[4].'</span>',U::badge($o[5],$o[5]==='Ready'?'green':'blue'),U::btn('View','/station-admin/orders?order='.$o[0],'secondary','eye')],array_slice($s['orders'],0,3))) ?></section>
<aside class="panel p-4 md:p-5"><h2 class="section-title flex items-center gap-2.5"><span class="title-icon"><?= U::icon('chart','size-5') ?></span>Priority breakdown</h2><p class="mt-1 mb-4 text-sm text-muted">HMT-104, Maria Santos</p>
<dl class="space-y-2"><?php foreach([['alert','Urgency',40],['clock','Waiting',10],['user','Customer type',10]] as [$icon,$l,$v]): ?><div class="flex items-center justify-between rounded-lg bg-mist px-3 py-2.5"><dt class="flex items-center gap-2 text-sm"><?= U::icon($icon,'size-4 text-water') ?><?= U::e($l) ?></dt><dd class="font-semibold"><?= $v ?></dd></div><?php endforeach ?><div class="flex items-center justify-between rounded-lg bg-water px-3 py-2.5 font-bold text-white"><dt class="flex items-center gap-2"><?= U::icon('check','size-4') ?>Total score</dt><dd>60</dd></div></dl>
<?= U::btn('Manage priority rules','/station-admin/settings/order-rules','ghost','settings',['class'=>'mt-4 w-full']) ?></aside>
</div>
<section class="panel mt-5 p-4 md:p-5"><div class="mb-4 flex flex-wrap items-center justify-between gap-3"><h2 class="section-title flex items-center gap-2.5"><span class="title-icon"><?= U::icon('truck','size-5') ?></span>Ready for dispatch</h2><div class="flex flex-wrap gap-2"><?= U::btn('Manage schedule','/station-admin/schedules','ghost','calendar',['class'=>'btn-sm']) ?><?= U::btn('Build a trip','/station-admin/deliveries#dispatch','primary','truck',['class'=>'btn-sm']) ?></div></div>
<?= U::table(['Order','Customer','Barangay','Containers','Ready since'],array_map(fn($o)=>['<strong>'.U::e($o[0]).'</strong>',U::e($o[1]),U::e($o[2]),$o[3],'<span class="inline-flex items-center gap-1.5">'.U::icon('clock','size-4 text-muted').U::e($o[6]).'</span>'],array_slice($s['orders'],2))) ?></section>
