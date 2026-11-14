<?php use App\UI\UI as U; use App\Core\Session; $token = U::e(Session::csrfToken()); ?>
<span class="role-badge w-fit"><?= U::icon('mail','size-3.5') ?>Step 2 of 2</span>
<h1 class="mt-4 font-heading text-[28px] font-bold leading-tight tracking-tight sm:text-[32px]">Check your email</h1>
<p class="mt-2 text-muted">Enter the 6-digit code we sent to <strong class="text-ink"><?= U::e($data['email']) ?></strong>, then choose your password. Your account is created only after the code is confirmed.</p>
<form method="post" action="/signup/verify" class="mt-6 space-y-4">
    <input type="hidden" name="_token" value="<?= $token ?>">
    <div><label class="label" for="code">Verification code</label><input class="field text-center font-heading text-2xl tracking-[0.5em]" id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required placeholder="000000" title="Enter the 6 digits from the email."></div>
    <div><label class="label" for="password">Password</label><div class="relative"><?= U::icon('lock','pointer-events-none absolute left-3.5 top-1/2 size-4.5 -translate-y-1/2 text-muted') ?><input class="field pl-10 pr-12" name="password" id="password" type="password" required minlength="8" maxlength="72" autocomplete="new-password" placeholder="At least 8 characters"><button class="absolute inset-y-0 right-3 flex items-center text-muted hover:text-ink" type="button" data-toggle-password="password" aria-label="Show password"><?= U::icon('eye') ?></button></div></div>
    <?= U::field('Confirm password','confirm_password','','password',['icon'=>'lock','required'=>true,'minlength'=>'8','maxlength'=>'72','autocomplete'=>'new-password']) ?>
    <?= U::btn('Create account','','primary','check',['type'=>'submit','class'=>'w-full min-h-12 text-base']) ?>
</form>
<form method="post" action="/signup/resend" class="mt-4 flex flex-wrap items-center justify-center gap-x-2 gap-y-1 text-sm text-muted">
    <input type="hidden" name="_token" value="<?= $token ?>">
    <span>Did not get it? Check your spam folder or</span><button type="submit" class="auth-link">send a new code</button>
</form>
<p class="mt-3 text-center text-sm text-muted"><a href="/signup" class="auth-link">Use a different email</a></p>
