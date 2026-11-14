<?php if (!($data['preview'] ?? false)) { require __DIR__ . '/live/account.php'; return; } ?>
<?php use App\UI\UI as U; $s=$data['sample']; ?>
<div class="mx-auto max-w-3xl space-y-4"><?= U::heading('My account') ?>
<section class="flex items-center gap-3.5 rounded-2xl bg-water p-4 text-white"><span class="flex size-14 shrink-0 items-center justify-center rounded-full bg-white text-lg font-bold text-water">JR</span><div class="min-w-0 flex-1"><h2 class="truncate text-lg font-bold"><?= U::e($s['name']) ?></h2><p class="flex items-center gap-1.5 truncate text-sm"><?= U::icon('store','size-4 shrink-0') ?><?= U::e($s['station']) ?></p></div><span class="rounded-full bg-white px-2.5 py-1 text-xs font-bold text-emerald-700">Active</span></section>
<form class="panel space-y-4 p-4 md:p-6" data-local-form data-persist="rider-profile" data-success="Rider profile saved in this browser.">
    <h2 class="section-title flex items-center gap-2.5"><span class="title-icon"><?= U::icon('user','size-5') ?></span>Personal information</h2>
    <?= U::field('Full name','full_name',$s['name'],'text',['icon'=>'user','required'=>true]) ?><?= U::field('Mobile number','phone',$s['phone'],'tel',['icon'=>'phone','required'=>true,'pattern'=>'[0-9+ \(\)\-]{10,16}']) ?><?= U::field('Email address','email',$s['email'],'email',['icon'=>'mail','required'=>true]) ?>
    <h2 class="section-title flex items-center gap-2.5 border-t border-line pt-4"><span class="title-icon"><?= U::icon('truck','size-5') ?></span>Station and vehicle</h2>
    <dl class="divide-y divide-line rounded-xl border border-line bg-white text-sm">
        <?php foreach ([['store','Assigned station',$s['station']],['truck','Vehicle',$s['vehicle']],['file','Plate number',$s['plate']]] as [$icon,$label,$value]): ?><div class="flex items-center justify-between gap-3 px-3.5 py-3"><dt class="flex items-center gap-2 text-muted"><?= U::icon($icon,'size-4') ?><?= U::e($label) ?></dt><dd class="text-right font-semibold"><?= U::e($value) ?></dd></div><?php endforeach ?>
    </dl>
    <p class="text-xs text-muted">Contact your station administrator to update your assignment.</p>
    <?= U::btn('Save profile','','primary','check',['type'=>'submit','class'=>'w-full sm:w-auto']) ?>
</form>
<?= U::btn('Log out',$logoutHref,'danger','logout',['class'=>'w-full sm:w-auto','data-logout'=>'rider']) ?></div>
