<?php use App\UI\UI as U; $s=$data['sample']; $stop=$s['stop']; ?>
<div class="mx-auto max-w-4xl space-y-4">
<?= U::heading("Today’s trip") ?>
<section class="rounded-2xl bg-water p-4 text-white md:p-5">
    <div class="flex items-center justify-between gap-3">
        <div class="flex min-w-0 items-center gap-3"><span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-white/15"><?= U::icon('truck','size-6') ?></span><div class="min-w-0"><h2 class="text-lg font-bold"><?= U::e($s['trip']) ?></h2><p class="flex items-center gap-1.5 truncate text-sm"><?= U::icon('store','size-4 shrink-0') ?><?= U::e($s['station']) ?></p></div></div>
        <span class="shrink-0 rounded-full bg-white px-3 py-1 text-xs font-bold text-water"><?= (int)$s['completed'] ?> of <?= (int)$s['total'] ?> stops</span>
    </div>
    <div class="mt-4 flex gap-1.5" role="progressbar" aria-label="Trip progress" aria-valuemin="0" aria-valuemax="<?= (int)$s['total'] ?>" aria-valuenow="<?= (int)$s['completed'] ?>" aria-valuetext="<?= (int)$s['completed'] ?> of <?= (int)$s['total'] ?> stops completed"><?php for ($i = 0; $i < $s['total']; $i++): ?><span class="h-2 flex-1 rounded-full <?= $i < $s['completed'] ? 'bg-white' : 'bg-white/30' ?>"></span><?php endfor ?></div>
    <p class="mt-3 flex items-center gap-1.5 text-sm"><?= U::icon('calendar','size-4') ?>Monday, November 2, 2026</p>
</section>

<section class="panel space-y-4 p-4 md:p-6">
    <div>
        <div class="flex items-center justify-between gap-3"><p class="flex items-center gap-2 text-sm font-semibold text-water"><?= U::icon('user','size-4') ?>Next delivery</p><span id="delivery-status"><?= U::badge('Assigned') ?></span></div>
        <h2 class="mt-1.5 text-2xl font-bold tracking-tight"><?= U::e($stop['name']) ?></h2>
        <p class="mt-2 flex items-start gap-2 text-sm"><?= U::icon('pin','mt-0.5 size-4 shrink-0 text-water') ?><?= U::e($stop['address']) ?></p>
        <p class="mt-1 flex items-start gap-2 text-sm text-muted"><?= U::icon('home','mt-0.5 size-4 shrink-0') ?><?= U::e($stop['landmark']) ?></p>
    </div>
    <div class="grid grid-cols-2 gap-2.5"><?= U::btn('Call','','secondary','phone',['data-demo-action'=>true,'data-toast'=>'Sample customer contact. Calling is unavailable in this preview.']) ?><?= U::btn('Address','','secondary','pin',['data-dialog-open'=>'rider-stop-address']) ?></div>
    <dl class="grid grid-cols-3 gap-2 text-center">
        <?php foreach ([['bottle','Refills',(string)(int)$stop['quantity']],['credit','To collect',U::money($stop['amount'])],['refresh','Empties',(string)(int)$stop['empties']]] as [$icon,$label,$value]): ?><div class="flex flex-col items-center justify-center gap-1 rounded-xl bg-foam px-1.5 py-3"><dt class="flex flex-col items-center gap-1 text-xs text-muted"><?= U::icon($icon,'size-5 text-water') ?><?= U::e($label) ?></dt><dd class="whitespace-nowrap text-base font-bold"><?= U::e($value) ?></dd></div><?php endforeach ?>
    </dl>
    <div class="space-y-3 rounded-xl border border-line bg-white p-3.5">
        <p class="flex items-center gap-2 text-sm font-semibold"><?= U::icon('credit','size-4.5 text-water') ?>Collect payment<span class="ml-auto font-bold text-water"><?= U::money($stop['amount']) ?></span></p>
        <div class="grid grid-cols-2 gap-3"><?= U::field('Cash received','cash-received','100.00','number',['id'=>'cash-received','class'=>'no-spin','min'=>$stop['amount'],'step'=>'0.01','data-amount-due'=>$stop['amount'],'inputmode'=>'decimal']) ?><div><label class="label" for="cash-change">Change</label><output id="cash-change" class="flex min-h-11 items-center rounded-lg bg-foam px-3.5 text-lg font-bold" aria-live="polite"><?= number_format(100 - $stop['amount'], 2) ?></output></div></div>
    </div>
    <div class="flex items-center justify-between gap-3 rounded-xl border border-line bg-white p-3.5"><label class="flex items-center gap-2 text-sm font-semibold" for="empties-collected"><?= U::icon('refresh','size-4.5 text-water') ?>Empties collected</label><div class="flex shrink-0 items-center gap-1 rounded-full border border-line bg-mist p-1"><?= U::btn('','','ghost','minus',['data-step'=>'-1','data-counter'=>'empties-collected','aria-label'=>'Collect one fewer empty container','class'=>'size-9 min-h-9 rounded-full border-0 px-0']) ?><input id="empties-collected" name="empties" class="no-spin w-10 rounded bg-transparent text-center text-lg font-bold focus-visible:outline-offset-1" type="number" min="0" max="20" value="<?= (int)$stop['empties'] ?>" inputmode="numeric"><?= U::btn('','','primary','plus',['data-step'=>'1','data-counter'=>'empties-collected','aria-label'=>'Collect one more empty container','class'=>'size-9 min-h-9 rounded-full px-0']) ?></div></div>
    <div class="grid gap-2.5 sm:grid-cols-2"><?= U::btn('Mark delivered','','primary','check',['class'=>'rider-action w-full','data-status'=>'Delivered','data-status-target'=>'delivery-status']) ?><?= U::btn('Report a problem','/rider/problem','danger','alert',['class'=>'rider-action w-full']) ?></div>
    <p class="text-xs text-muted">Sample trip. Status changes stay in this preview.</p>
</section>

<section class="panel flex items-center gap-3 p-4">
    <span class="title-icon size-10"><?= U::icon('arrow-right','size-5') ?></span>
    <div class="min-w-0"><p class="text-xs font-semibold text-muted">Next stop after this</p><h2 class="truncate font-bold"><?= U::e($s['next']['name']) ?></h2><p class="truncate text-sm text-muted"><?= U::e($s['next']['address']) ?>, <?= U::e($s['next']['landmark']) ?></p></div>
</section>
</div>

<dialog id="rider-stop-address" aria-labelledby="rider-address-title"><div class="flex items-start justify-between gap-4"><h2 id="rider-address-title" class="section-title flex items-center gap-2.5"><span class="title-icon"><?= U::icon('pin','size-5') ?></span>Delivery address</h2><button type="button" data-dialog-close aria-label="Close address"><?= U::icon('close') ?></button></div><h3 class="mt-4 font-bold"><?= U::e($stop['name']) ?></h3><p class="mt-1"><?= U::e($stop['address']) ?></p><p class="mt-1 text-sm text-muted"><?= U::e($stop['landmark']) ?></p><p class="mt-4 text-xs text-muted">Fictional sample location for the rider preview.</p></dialog>
