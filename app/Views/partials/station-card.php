<?php use App\UI\UI as U; use App\UI\Products; /** @var array $station */ ?>
<article data-search-row class="lift flex w-[84%] shrink-0 flex-col rounded-2xl border border-line bg-white p-5 sm:w-80 <?= U::e($cardClass ?? '') ?>">
    <div class="flex items-start justify-between gap-3">
        <?= U::badge($station['status'], $station['tone']) ?>
        <span class="flex size-12 shrink-0 items-center justify-center rounded-full bg-foam"><?= U::productImage('h-9 w-7') ?></span>
    </div>
    <h3 class="mt-3 font-heading text-xl font-bold leading-snug"><?= U::e($station['name']) ?></h3>
    <p class="mt-1 flex items-center gap-1.5 text-sm text-muted"><?= U::icon('pin','size-4 shrink-0') ?>Brgy. <?= U::e($station['area']) ?>, Hamtic</p>
    <dl class="mt-5 space-y-2 border-t border-line pt-4 text-sm">
        <div class="flex justify-between gap-4"><dt class="flex items-center gap-2 text-muted"><?= U::icon('calendar','size-4 shrink-0') ?>Mon to Sat</dt><dd class="whitespace-nowrap text-right font-medium"><?= U::e($station['hours']) ?></dd></div>
        <div class="flex justify-between gap-4"><dt class="flex items-center gap-2 text-muted"><?= U::icon('clock','size-4 shrink-0') ?>Sunday</dt><dd class="whitespace-nowrap text-right font-medium"><?= U::e($station['sunday']) ?></dd></div>
        <div class="flex justify-between gap-4"><dt class="flex items-center gap-2 text-muted"><?= U::icon('tag','size-4 shrink-0') ?>Container refill</dt><dd class="text-right font-medium"><?= U::money(Products::get('round')['price']) ?></dd></div>
    </dl>
    <div class="mt-auto pt-5"><?= U::btn($station['available'] ? 'Order water' : 'View station', '/customer/station?station='.$station['id'], $station['available'] ? 'primary' : 'ghost', $station['available'] ? 'bottle' : 'store', ['class'=>'w-full']) ?></div>
</article>
