<?php use App\UI\UI as U; ?>
<a href="/login/customer" class="mb-6 flex items-center gap-2 text-sm font-semibold text-water"><?= U::icon('arrow-left','size-4') ?>Back to login</a>
<div class="mb-7"><span class="role-badge"><?= U::icon('shield','size-4') ?>Account recovery</span><h1 class="mt-5 font-heading text-3xl font-extrabold tracking-tight">Forgot your password?</h1><p class="mt-3 text-muted">Enter your account email to preview the password reset flow.</p></div>
<form data-auth-form data-auth-recovery class="space-y-5"><?= U::field('Email address','email','','email',['icon'=>'mail','required'=>true,'autocomplete'=>'email','placeholder'=>'you@example.com']) ?><?= U::btn('Send reset link','','primary','mail',['type'=>'submit','class'=>'w-full']) ?></form>
<p class="mt-5 text-sm text-muted">Preview only. No reset email will be sent.</p>

