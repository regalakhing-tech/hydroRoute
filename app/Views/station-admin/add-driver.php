<?php use App\UI\UI as U; use App\Core\Session; use App\Services\Registry; $old = Session::takeInput(); $v = static fn (string $key, string $default = ''): string => (string) ($old[$key] ?? $default); ?>
<?= U::heading('Add driver','Create a rider account for your station.',U::btn('Back to drivers','/station-admin/drivers','secondary','arrow-left')) ?>
<div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_20rem]">
<form method="post" action="/station-admin/drivers" class="panel panel-raised overflow-hidden">
    <input type="hidden" name="_token" value="<?= U::e(Session::csrfToken()) ?>">
    <section class="p-5 md:p-7" aria-labelledby="driver-heading">
        <div class="mb-5 flex items-start gap-3"><span class="title-icon"><?= U::icon('user','size-5') ?></span><div><h2 id="driver-heading" class="section-title">Driver information</h2><p class="mt-0.5 text-sm text-muted">Who will deliver for your station.</p></div></div>
        <div class="grid gap-x-5 gap-y-5 sm:grid-cols-2">
            <?= U::field('First name','first_name',$v('first_name'),'text',['icon'=>'user','required'=>true,'maxlength'=>60,'autocomplete'=>'off']) ?>
            <?= U::field('Last name','last_name',$v('last_name'),'text',['icon'=>'user','required'=>true,'maxlength'=>60,'autocomplete'=>'off']) ?>
            <?= U::field('Mobile number','phone',$v('phone'),'tel',['icon'=>'phone','required'=>true,'pattern'=>'(09[0-9]{9}|\\+639[0-9]{9})','placeholder'=>'09XX XXX XXXX','title'=>'Use 09 followed by 9 digits.']) ?>
            <?= U::field('Email address','email',$v('email'),'email',['icon'=>'mail','required'=>true,'maxlength'=>254,'placeholder'=>'driver@example.com','autocomplete'=>'off','hint'=>'The driver logs in with this email.']) ?>
        </div>
    </section>
    <section class="border-t border-line p-5 md:p-7" aria-labelledby="vehicle-heading">
        <div class="mb-5 flex items-start gap-3"><span class="title-icon"><?= U::icon('truck','size-5') ?></span><div><h2 id="vehicle-heading" class="section-title">Vehicle details</h2><p class="mt-0.5 text-sm text-muted">Used to plan how many containers fit in one trip.</p></div></div>
        <div class="grid gap-x-5 gap-y-5 sm:grid-cols-3">
            <?= U::select('Vehicle type','vehicle',array_combine(Registry::VEHICLES, Registry::VEHICLES),$v('vehicle','Motorcycle with sidecar')) ?>
            <?= U::field('Plate number','plate',$v('plate'),'text',['icon'=>'tag','required'=>true,'maxlength'=>20,'pattern'=>'[A-Za-z0-9 \-]{2,20}','placeholder'=>'ABC 1234']) ?>
            <?= U::field('Container capacity','capacity',$v('capacity','15'),'number',['icon'=>'bottle','required'=>true,'min'=>1,'max'=>100,'hint'=>'Containers per trip.']) ?>
        </div>
    </section>
    <section class="border-t border-line p-5 md:p-7" aria-labelledby="login-heading">
        <div class="mb-5 flex items-start gap-3"><span class="title-icon"><?= U::icon('lock','size-5') ?></span><div><h2 id="login-heading" class="section-title">Login password</h2><p class="mt-0.5 text-sm text-muted">Set it now, then give the driver their email and password in person.</p></div></div>
        <div class="grid gap-x-5 gap-y-5 sm:grid-cols-2">
            <div><label class="label" for="password">Password</label><div class="relative"><?= U::icon('lock','pointer-events-none absolute left-3.5 top-1/2 size-4.5 -translate-y-1/2 text-muted') ?><input class="field pl-10 pr-12" name="password" id="password" type="password" required minlength="8" maxlength="72" autocomplete="new-password" placeholder="At least 8 characters" aria-describedby="password-hint"><button class="absolute inset-y-0 right-3 flex items-center text-muted hover:text-ink" type="button" data-toggle-password="password" aria-label="Show password"><?= U::icon('eye') ?></button></div><p class="hint" id="password-hint">The driver can use it right away at Rider login.</p></div>
            <?= U::field('Confirm password','confirm_password','','password',['icon'=>'lock','required'=>true,'minlength'=>8,'maxlength'=>72,'autocomplete'=>'new-password']) ?>
        </div>
    </section>
    <div class="flex flex-col gap-4 border-t border-line bg-mist/80 p-5 md:flex-row md:items-center md:justify-between md:px-7">
        <label class="flex items-start gap-3 text-sm"><input type="checkbox" name="verified" value="yes" required class="mt-0.5 shrink-0"><span>I have checked the driver’s details and their license.</span></label>
        <div class="flex flex-col-reverse gap-2 sm:flex-row"><?= U::btn('Cancel','/station-admin/drivers','ghost') ?><?= U::btn('Create driver account','','primary','check',['type'=>'submit']) ?></div>
    </div>
</form>
<aside class="h-fit space-y-5 rounded-xl border border-line bg-white p-5 md:p-6 xl:sticky xl:top-6">
    <div><p class="text-sm text-muted">Station assignment</p><p class="mt-1 flex items-center gap-2 font-semibold"><?= U::icon('store','size-4.5 text-water') ?><?= U::e($info['caption']) ?></p></div>
    <ol class="relative space-y-4 border-t border-line pt-5 before:absolute before:bottom-2 before:left-4 before:top-7 before:w-px before:bg-line">
        <?php foreach ([['lock','You set the driver’s password here.'],['user','Give the driver their email and password.'],['truck','They log in at Rider login and see only the trips you assign to them.']] as [$icon, $text]): ?>
        <li class="relative flex items-start gap-3"><span class="relative z-10 flex size-8 shrink-0 items-center justify-center rounded-full border border-line bg-white text-water"><?= U::icon($icon,'size-4') ?></span><span class="pt-1 text-sm"><?= U::e($text) ?></span></li>
        <?php endforeach ?>
    </ol>
</aside>
</div>
