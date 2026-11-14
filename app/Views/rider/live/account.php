<?php
// Original rider account design with the rider's registration from the station.
use App\Services\Workspace;
use App\UI\UI as U;

$live = Workspace::get()->load(static fn (Workspace $w): array => $w->riderAccount());
$d = $live['driver'] ?? null; $u = $data['user'] ?? [];
$name = (string) ($d['full_name'] ?? $u['name'] ?? 'Rider'); $words = preg_split('/\s+/', trim($name)) ?: ['R'];
$phone = ($d['phone'] ?? '') && preg_match('/^\+63(9\d{2})(\d{3})(\d{4})$/', (string) $d['phone'], $m) ? '0' . $m[1] . ' ' . $m[2] . ' ' . $m[3] : (string) ($d['phone'] ?? '');
$logoutHref = '/logout?token=' . App\Core\Session::csrfToken();
?>
<div class="mx-auto max-w-3xl space-y-4"><?= U::heading('My account') ?>
<section class="flex items-center gap-3.5 rounded-2xl bg-water p-4 text-white"><span class="flex size-14 shrink-0 items-center justify-center rounded-full bg-white text-lg font-bold text-water"><?= U::e(mb_strtoupper(mb_substr($words[0], 0, 1) . mb_substr($words[1] ?? '', 0, 1))) ?></span><div class="min-w-0 flex-1"><h2 class="truncate text-lg font-bold"><?= U::e($name) ?></h2><p class="flex items-center gap-1.5 truncate text-sm"><?= U::icon('store','size-4 shrink-0') ?><?= U::e($live['station'] ?? '') ?></p></div><span class="rounded-full bg-white px-2.5 py-1 text-xs font-bold <?= ($d['active'] ?? true) ? 'text-emerald-700' : 'text-rose-700' ?>"><?= ($d['active'] ?? true) ? 'Active' : 'Inactive' ?></span></section>
<section class="panel space-y-4 p-4 md:p-6">
    <h2 class="section-title flex items-center gap-2.5"><span class="title-icon"><?= U::icon('user','size-5') ?></span>Personal information</h2>
    <?= U::field('Full name','full_name',$name,'text',['icon'=>'user','readonly'=>true]) ?><?= U::field('Mobile number','phone',$phone,'tel',['icon'=>'phone','readonly'=>true]) ?><?= U::field('Email address','email',(string) ($d['email'] ?? $u['email'] ?? ''),'email',['icon'=>'mail','readonly'=>true]) ?>
    <h2 class="section-title flex items-center gap-2.5 border-t border-line pt-4"><span class="title-icon"><?= U::icon('truck','size-5') ?></span>Station and vehicle</h2>
    <dl class="divide-y divide-line rounded-xl border border-line bg-white text-sm">
        <?php foreach ([['store','Assigned station',$live['station'] ?? ''],['truck','Vehicle',(string) ($d['vehicle'] ?? '')],['file','Plate number',(string) ($d['plate_number'] ?? '')],['bottle','Capacity',isset($d['capacity']) ? $d['capacity'] . ' containers' : '']] as [$icon,$label,$value]): ?><div class="flex items-center justify-between gap-3 px-3.5 py-3"><dt class="flex items-center gap-2 text-muted"><?= U::icon($icon,'size-4') ?><?= U::e($label) ?></dt><dd class="text-right font-semibold"><?= U::e($value) ?></dd></div><?php endforeach ?>
    </dl>
    <p class="text-xs text-muted">Your station administrator manages these details and your password. Contact them to update your assignment.</p>
</section>
<?= U::btn('Log out',$logoutHref,'danger','logout',['class'=>'w-full sm:w-auto','data-logout'=>'rider']) ?></div>
