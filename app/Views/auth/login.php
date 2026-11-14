<?php use App\UI\UI as U; use App\Core\Session; $role=$data['role']; $info=$data['roleInfo']; $old=Session::takeInput(); ?>
<?php if ($role==='customer'): $tab='/login/customer'; require __DIR__.'/customer-tabs.php'; else: ?>
<div class="mb-6 flex items-center justify-between gap-3"><span class="role-badge"><?= U::icon($info['icon'],'size-3.5') ?><?= U::e($info['label']) ?> account</span><a class="auth-link text-sm" href="/login">Change</a></div>
<?php endif ?>
<h1 class="font-heading text-[28px] font-bold leading-tight tracking-tight sm:text-[32px]">Welcome back</h1>
<p class="mt-2 text-muted"><?= U::e($info['description']) ?></p>
<form method="post" action="/login/<?= U::e($role) ?>" data-login-role="<?= U::e($role) ?>" class="mt-6 space-y-5">
    <input type="hidden" name="_token" value="<?= U::e(Session::csrfToken()) ?>">
    <?= U::field('Email address','email',(string)($old['email'] ?? ''),'email',['icon'=>'mail','required'=>true,'autocomplete'=>'username','placeholder'=>'you@example.com']) ?>
    <div><div class="mb-1.5 flex items-center justify-between gap-3"><label class="text-sm font-semibold" for="password">Password</label><a href="/forgot-password" class="auth-link text-sm">Forgot password?</a></div><div class="relative"><?= U::icon('lock','pointer-events-none absolute left-3.5 top-1/2 size-4.5 -translate-y-1/2 text-muted') ?><input class="field pl-10 pr-12" name="password" id="password" type="password" required autocomplete="current-password" placeholder="Enter your password"><button class="absolute inset-y-0 right-3 flex items-center text-muted hover:text-ink" type="button" data-toggle-password="password" aria-label="Show password"><?= U::icon('eye') ?></button></div></div>
    <?= U::btn('Log in','','primary','login',['type'=>'submit','class'=>'w-full min-h-12 text-base']) ?>
</form>
<p class="mt-6 text-center text-sm text-muted"><?php if($role==='customer'): ?>New to HydroRoute? <a class="auth-link" href="/signup">Create an account</a><?php elseif($role==='station-admin'): ?>Bringing your station online? <a class="auth-link" href="/register-station">Register a station</a><?php elseif($role==='rider'): ?>Your station admin creates your rider account and emails you a link to set your password.<?php else: ?>Administrator access is provided by the platform owner.<?php endif ?></p>
<?php if ($role==='customer'): ?><p class="mt-3 text-center text-sm text-muted"><a class="auth-link" href="/login">Choose another account type</a></p><?php endif ?>
