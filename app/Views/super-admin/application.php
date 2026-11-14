<?php use App\UI\UI as U; use App\Core\Session; $a = $data['sample']['application'] ?? null; ?>
<div class="mb-4"><?= U::btn('Back to station applications','/super-admin/applications','ghost','arrow-left') ?></div>
<?php if (!$a): ?>
<?= U::heading('Application not found','It may have been removed, or the link is incomplete.') ?>
<?php return; endif; $status = $a['status'] ?? 'Pending'; $tone = ['Pending'=>'amber','Approved'=>'green','Rejected'=>'red'][$status] ?? 'blue'; $token = U::e(Session::csrfToken()); ?>
<?= U::heading('Review station application','Check the registration details before recording a decision.') ?>
<section class="mb-5 grid gap-4 rounded-xl bg-mist p-6 md:grid-cols-[1.5fr_1fr_1fr]"><div><p class="muted">Registration · <?= U::e($a['ref'] ?? $a['id']) ?></p><h2 class="text-2xl font-bold"><?= U::e($a['name']) ?></h2></div><div><p class="muted mb-1">Status</p><span id="application-status"><?= U::badge($status,$tone) ?></span></div><div><p class="muted">Applied</p><p class="text-xl"><?= U::e($a['date']) ?></p></div></section>
<div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_380px]">
<section class="panel overflow-hidden"><?= U::title('Station details','store','bg-mist px-5 py-4') ?><dl class="divide-y divide-line px-5"><?php foreach(['Business name'=>$a['name'],'Barangay'=>$a['area'],'Municipality'=>$a['municipality'] ?? 'Hamtic, Antique','Street address'=>$a['address'] ?? '','Business contact'=>$a['phone'] ?? '','Business email'=>$a['email'] ?? ''] as $label=>$value): ?><div class="grid gap-1 py-3 sm:grid-cols-[180px_1fr]"><dt class="muted"><?= $label ?></dt><dd class="break-words"><?= U::e($value) ?></dd></div><?php endforeach; ?></dl><?= U::title('Station administrator','user','bg-mist px-5 py-4') ?><dl class="divide-y divide-line px-5 pb-3"><?php foreach(['Name'=>$a['applicant'],'Role at the station'=>$a['applicant_role'] ?? '','Mobile number'=>$a['applicant_phone'] ?? '','Email address'=>$a['applicant_email'] ?? ''] as $label=>$value): ?><div class="grid gap-1 py-3 sm:grid-cols-[180px_1fr]"><dt class="muted"><?= $label ?></dt><dd class="break-words"><?= U::e($value) ?></dd></div><?php endforeach; ?></dl></section>
<div class="space-y-5 lg:sticky lg:top-6 lg:self-start">
<?php if ($status === 'Pending'): ?>
<form method="post" action="/super-admin/application/decision" class="panel panel-raised space-y-4 p-5">
    <input type="hidden" name="_token" value="<?= $token ?>"><input type="hidden" name="id" value="<?= U::e($a['id']) ?>">
    <?= U::title('Decision','check','') ?>
    <ul class="space-y-2 text-sm"><?php foreach (['The station is within the municipality of Hamtic.','A business contact number is supplied.','The administrator’s name and email are real.'] as $check): ?><li class="flex items-start gap-2"><?= U::icon('check','mt-0.5 size-4 shrink-0 text-water') ?><?= U::e($check) ?></li><?php endforeach ?></ul>
    <?= U::textarea('Reason or note','reason','',['rows'=>'4','maxlength'=>'500','placeholder'=>'Required when rejecting. Optional when approving.']) ?>
    <p class="rounded-lg bg-foam p-3 text-sm">Approving lists the station on HydroRoute and emails <strong><?= U::e($a['applicant_email'] ?? 'the administrator') ?></strong> a link to set their station admin password.</p>
    <div class="grid gap-2 sm:grid-cols-2"><?= U::btn('Approve station','','primary','check',['type'=>'submit','name'=>'decision','value'=>'approve']) ?><?= U::btn('Reject','','danger','close',['type'=>'submit','name'=>'decision','value'=>'reject']) ?></div>
</form>
<?php elseif ($status === 'Approved'): ?>
<section class="panel space-y-4 p-5"><div class="flex items-start gap-3"><span class="title-icon bg-emerald-100 text-emerald-700"><?= U::icon('check','size-5') ?></span><div><h2 class="section-title">Approved and listed</h2><p class="mt-1 text-sm text-muted">The station appears on the HydroRoute home page. Its administrator logs in at the Station Admin login.</p></div></div>
<?php if (!empty($a['reason'])): ?><p class="rounded-lg bg-mist p-3 text-sm"><?= U::e($a['reason']) ?></p><?php endif ?>
<form method="post" action="/super-admin/application/invite"><input type="hidden" name="_token" value="<?= $token ?>"><input type="hidden" name="id" value="<?= U::e($a['id']) ?>"><?= U::btn('Send invitation again','','secondary','mail',['type'=>'submit','class'=>'w-full']) ?></form>
<p class="text-xs text-muted">Use this if the administrator lost the email or the link expired.</p></section>
<?php else: ?>
<section class="panel space-y-3 p-5"><div class="flex items-start gap-3"><span class="title-icon bg-rose-100 text-rose-700"><?= U::icon('close','size-5') ?></span><div><h2 class="section-title">Rejected</h2><p class="mt-1 text-sm text-muted">The applicant may submit a corrected registration.</p></div></div><p class="rounded-lg bg-mist p-3 text-sm"><?= U::e($a['reason'] ?? '') ?></p></section>
<?php endif ?>
</div></div>
