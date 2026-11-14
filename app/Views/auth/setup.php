<?php use App\UI\UI as U; use App\Core\Session; $link = $data['link']; ?>
<span class="role-badge w-fit"><?= U::icon('lock','size-3.5') ?>Account setup</span>
<h1 class="mt-4 font-heading text-[28px] font-bold leading-tight tracking-tight sm:text-[32px]">Set your password</h1>
<p class="mt-2 text-muted">Choose the password you will use to log in to HydroRoute. This link works once.</p>
<form method="post" action="/account/setup" class="mt-6 space-y-4">
    <input type="hidden" name="_token" value="<?= U::e(Session::csrfToken()) ?>">
    <input type="hidden" name="token_hash" value="<?= U::e($link['token_hash']) ?>">
    <input type="hidden" name="type" value="<?= U::e($link['type']) ?>">
    <div><label class="label" for="password">New password</label><div class="relative"><?= U::icon('lock','pointer-events-none absolute left-3.5 top-1/2 size-4.5 -translate-y-1/2 text-muted') ?><input class="field pl-10 pr-12" name="password" id="password" type="password" required minlength="8" maxlength="72" autocomplete="new-password" placeholder="At least 8 characters"><button class="absolute inset-y-0 right-3 flex items-center text-muted hover:text-ink" type="button" data-toggle-password="password" aria-label="Show password"><?= U::icon('eye') ?></button></div></div>
    <?= U::field('Confirm password','confirm_password','','password',['icon'=>'lock','required'=>true,'minlength'=>'8','maxlength'=>'72','autocomplete'=>'new-password']) ?>
    <?= U::btn('Save password and continue','','primary','check',['type'=>'submit','class'=>'w-full min-h-12 text-base']) ?>
</form>
<p class="mt-6 text-center text-sm text-muted">Link expired? Ask the person who invited you to send a new one.</p>
