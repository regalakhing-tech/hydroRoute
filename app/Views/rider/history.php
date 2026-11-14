<?php use App\UI\UI as U; $s=$data['sample']; $done = count(array_filter($s['history'], static fn($h) => $h['status'] === 'Delivered')); $failed = count($s['history']) - $done; $refills = array_sum(array_column(array_filter($s['history'], static fn($h) => $h['status'] === 'Delivered'), 'quantity')); ?>
<div class="mx-auto max-w-4xl space-y-4"><?= U::heading('Delivery history') ?>
<section class="rounded-2xl bg-water p-4 text-white">
    <p class="flex items-center gap-1.5 text-sm"><?= U::icon('calendar','size-4') ?>Monday, November 2, 2026</p>
    <dl class="mt-3 grid grid-cols-3 divide-x divide-white/20 text-center">
        <div><dt class="text-xs">Delivered</dt><dd class="mt-0.5 flex items-center justify-center gap-1.5 text-2xl font-bold"><?= U::icon('check','size-5') ?><?= $done ?></dd></div>
        <div><dt class="text-xs">Failed</dt><dd class="mt-0.5 flex items-center justify-center gap-1.5 text-2xl font-bold"><?= U::icon('alert','size-5') ?><?= $failed ?></dd></div>
        <div><dt class="text-xs">Refills</dt><dd class="mt-0.5 flex items-center justify-center gap-1.5 text-2xl font-bold"><?= U::icon('bottle','size-5') ?><?= $refills ?></dd></div>
    </dl>
</section>
<div class="flex items-center justify-between gap-3"><label class="flex items-center gap-2 text-sm font-semibold" for="history-status"><?= U::icon('filter','size-4 text-water') ?>Show</label><select id="history-status" class="field min-h-10 w-auto rounded-full py-2 pl-4 pr-9 text-sm" data-filter-target="rider-history"><option value="all">All attempts</option><option value="Delivered">Delivered</option><option value="Customer unavailable">Customer unavailable</option></select></div>
<div id="rider-history" class="space-y-2.5"><?php foreach($s['history'] as $i=>$item): $ok = $item['status']==='Delivered'; ?><details class="group overflow-hidden rounded-2xl border bg-white <?= $ok?'border-line':'border-rose-200' ?>" data-search-row data-filter-value="<?= U::e($item['status']) ?>" <?= $i===0?'open':'' ?>>
    <summary class="flex cursor-pointer list-none items-center gap-3 p-3.5 [&::-webkit-details-marker]:hidden">
        <span class="flex size-11 shrink-0 items-center justify-center rounded-xl <?= $ok?'bg-emerald-600 text-white':'bg-rose-600 text-white' ?>"><?= U::icon($ok?'check':'alert','size-5') ?></span>
        <div class="min-w-0 flex-1">
            <p class="flex items-center justify-between gap-2"><strong class="whitespace-nowrap"><?= U::e($item['id']) ?></strong><span class="flex items-center gap-1 whitespace-nowrap text-xs text-muted"><?= U::icon('clock','size-3.5') ?><?= U::e($item['time']) ?></span></p>
            <p class="truncate text-sm text-muted"><?= U::e($item['name']) ?>, <?= U::e($item['area']) ?></p>
            <p class="mt-1.5 text-xs font-semibold <?= $ok?'text-emerald-700':'text-rose-700' ?>"><?= U::e($item['status']) ?></p>
        </div>
        <?= U::icon('chevron','size-5 shrink-0 text-muted transition-transform group-open:rotate-180') ?>
    </summary>
    <dl class="grid grid-cols-3 gap-2 border-t border-line bg-mist p-3 text-center"><?php foreach ([['bottle','Refills',$item['quantity']],['refresh','Expected',$item['quantity']],['check','Collected',$item['empties']]] as [$icon,$label,$value]): ?><div class="rounded-xl bg-white py-2.5"><dt class="flex flex-col items-center gap-1 text-xs text-muted"><?= U::icon($icon,'size-4 text-water') ?><?= U::e($label) ?></dt><dd class="text-lg font-bold"><?= (int)$value ?></dd></div><?php endforeach ?></dl>
</details><?php endforeach; ?><p data-empty hidden class="rounded-2xl border border-line bg-white p-4 text-center text-sm text-muted">No matching delivery attempts.</p></div></div>
