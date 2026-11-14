<?php use App\UI\UI as U; use App\Core\Session; $tab='/signup'; require __DIR__.'/customer-tabs.php'; $old = Session::takeInput(); ?>
<h1 class="font-heading text-[28px] font-bold leading-tight tracking-tight sm:text-[32px]">Create your account</h1>
<p class="mt-2 text-muted">Order water deliveries from stations around Hamtic. We will email you a code to confirm your address.</p>
<form method="post" action="/signup" data-signup-form class="mt-6 space-y-4">
    <input type="hidden" name="_token" value="<?= U::e(Session::csrfToken()) ?>">
    <div class="grid grid-cols-2 gap-3"><?= U::field('First name','first_name',(string)($old['first_name'] ?? ''),'text',['icon'=>'user','required'=>true,'maxlength'=>'60','autocomplete'=>'given-name']) ?><?= U::field('Last name','last_name',(string)($old['last_name'] ?? ''),'text',['icon'=>'user','required'=>true,'maxlength'=>'60','autocomplete'=>'family-name']) ?></div>
    <?= U::field('Email address','email',(string)($old['email'] ?? ''),'email',['icon'=>'mail','required'=>true,'maxlength'=>'254','autocomplete'=>'email','placeholder'=>'you@example.com']) ?>
    <?= U::field('Mobile number','mobile',(string)($old['mobile'] ?? ''),'tel',['icon'=>'phone','required'=>true,'autocomplete'=>'tel','pattern'=>'(09[0-9]{9}|\\+639[0-9]{9})','placeholder'=>'09XX XXX XXXX','title'=>'Use 09 followed by 9 digits, or +639 followed by 9 digits.']) ?>
    <label class="flex items-start gap-2 text-sm text-muted"><input class="mt-1 shrink-0" type="checkbox" required name="consent" value="yes"><span>I agree that HydroRoute and the stations I order from may use these details to process my orders and deliveries.</span></label>
    <?= U::btn('Send verification code','','primary','mail',['type'=>'submit','class'=>'w-full min-h-12 text-base']) ?>
</form>
<p class="mt-6 text-center text-sm text-muted">Already have an account? <a href="/login/customer" class="auth-link">Log in</a></p>
