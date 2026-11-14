<?php use App\UI\UI as U; ?>
<h1 class="font-heading text-[28px] font-bold leading-tight tracking-tight sm:text-[30px]">Log in to HydroRoute</h1>
<p class="mt-2 text-muted">Choose the account you use.</p>
<ul class="mt-6 space-y-2.5">
<?php foreach ($data['roles'] as $role => $info): ?>
    <li><a href="/login/<?= U::e($role) ?>" class="group flex items-center gap-4 rounded-xl border border-line bg-white p-4 transition-colors hover:border-water/40 hover:bg-mist">
        <span class="flex size-11 shrink-0 items-center justify-center rounded-lg bg-foam text-water"><?= U::icon($info['icon'],'size-5.5') ?></span>
        <span class="min-w-0 flex-1"><span class="block font-semibold"><?= U::e($info['label']) ?></span><span class="block text-sm text-muted"><?= U::e($info['description']) ?></span></span>
        <?= U::icon('chevron-right','size-5 shrink-0 text-muted group-hover:text-water') ?>
    </a></li>
<?php endforeach ?>
</ul>
<p class="mt-6 text-sm text-muted">New to HydroRoute? <a class="auth-link" href="/signup">Create a customer account</a></p>
<p class="mt-2 text-sm text-muted">Want to look around first? Explore the <a class="auth-link" href="/customer">customer</a>, <a class="auth-link" href="/rider">rider</a>, <a class="auth-link" href="/station-admin">station admin</a> or <a class="auth-link" href="/super-admin">super admin</a> preview.</p>
