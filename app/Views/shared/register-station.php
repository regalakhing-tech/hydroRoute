<?php use App\UI\UI as U; use App\Core\Session; $old = Session::takeInput(); $v = static fn (string $key): string => (string) ($old[$key] ?? ''); $done = $data['submitted'] ?? null;
$steps = [['file','Send your details','Station and administrator information, in one form.'],['shield','Registry review','The HydroRoute registry checks that your station is in Hamtic.'],['mail','Login link by email','Your administrator sets a password and opens the station dashboard.']]; ?>
<div class="bg-linear-to-b from-foam via-mist to-mist">
<div class="mx-auto max-w-6xl px-5 py-8 md:px-10 md:py-12">
    <a href="/" class="mb-6 inline-flex items-center gap-2 rounded-md text-sm font-semibold text-water hover:underline md:mb-8"><?= U::icon('arrow-left','size-4') ?>Back to home</a>
<?php if ($done): ?>
    <section class="panel panel-raised mx-auto max-w-2xl p-8 text-center md:p-12" aria-live="polite">
        <span class="mx-auto flex size-16 items-center justify-center rounded-full bg-emerald-100 text-emerald-700"><?= U::icon('check','size-8') ?></span>
        <h1 class="mt-5 font-heading text-3xl font-bold tracking-tight text-balance">Registration sent</h1>
        <p class="mx-auto mt-3 max-w-md leading-relaxed text-muted"><strong class="text-ink"><?= U::e($done['station']) ?></strong> is now waiting for review by the HydroRoute registry administrator.</p>
        <ol class="mx-auto mt-8 max-w-md space-y-3 text-left text-sm">
            <?php foreach ([['shield','The administrator checks your station details.'],['mail','When approved, we email '.$done['email'].' a link to set the station admin password.'],['store','Your station then appears on the HydroRoute home page.']] as [$icon, $text]): ?>
            <li class="flex items-start gap-3 rounded-xl bg-mist p-3"><span class="title-icon size-9 shrink-0 bg-white"><?= U::icon($icon,'size-4.5') ?></span><span class="pt-1.5"><?= U::e($text) ?></span></li>
            <?php endforeach ?>
        </ol>
        <div class="mt-8 flex flex-wrap justify-center gap-3"><?= U::btn('Back to home','/','primary','home') ?><?= U::btn('Register another station','/register-station','secondary','plus') ?></div>
    </section>
<?php else: ?>
    <div class="grid gap-8 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)] lg:gap-12 xl:gap-16">
        <aside class="lg:sticky lg:top-24 lg:self-start">
            <h1 class="font-heading text-[2rem] font-bold leading-[1.1] tracking-tight text-balance md:text-[2.5rem]">Bring your station closer to your neighborhood.</h1>
            <p class="mt-4 max-w-prose leading-relaxed text-muted">Register your station and nominate its administrator. The HydroRoute registry reviews your details; once approved, your station appears on HydroRoute and the administrator receives a login link by email.</p>
            <figure class="mt-7 hidden overflow-hidden rounded-2xl bg-[#0d4a66] px-6 pt-6 lg:block"><img src="/assets/images/station-illustration.svg" alt="" class="mx-auto w-full max-w-xs" width="400" height="300"></figure>
            <h2 class="mt-8 text-sm font-semibold text-ink">How approval works</h2>
            <ol class="relative mt-4 space-y-5 before:absolute before:bottom-3 before:left-[1.125rem] before:top-3 before:w-px before:bg-line">
                <?php foreach ($steps as [$icon, $title, $text]): ?>
                <li class="relative flex gap-3.5"><span class="relative z-10 flex size-9 shrink-0 items-center justify-center rounded-full border border-line bg-white text-water"><?= U::icon($icon,'size-4.5') ?></span><div class="pt-1"><p class="text-sm font-semibold"><?= U::e($title) ?></p><p class="mt-0.5 text-sm leading-snug text-muted"><?= U::e($text) ?></p></div></li>
                <?php endforeach ?>
            </ol>
        </aside>

        <div class="min-w-0">
            <form method="post" action="/register-station" class="panel panel-raised overflow-hidden">
                <input type="hidden" name="_token" value="<?= U::e(Session::csrfToken()) ?>">
                <section class="p-6 md:p-8" aria-labelledby="station-heading">
                    <div class="mb-6 flex items-start gap-3"><span class="title-icon"><?= U::icon('store','size-5') ?></span><div><h2 id="station-heading" class="section-title">Station information</h2><p class="mt-0.5 text-sm text-muted">The details customers will use to find your station.</p></div></div>
                    <div class="grid gap-x-5 gap-y-5 sm:grid-cols-2">
                        <?= U::field('Station name','station_name',$v('station_name'),'text',['icon'=>'store','required'=>true,'maxlength'=>'120','placeholder'=>'Your water refilling station','autocomplete'=>'organization']) ?>
                        <?= U::field('Business contact number','business_phone',$v('business_phone'),'tel',['icon'=>'phone','required'=>true,'pattern'=>'(09[0-9]{9}|\\+639[0-9]{9})','placeholder'=>'09123456789','title'=>'Use 09 followed by 9 digits.','hint'=>'Customers call this number.']) ?>
                        <?= U::field('Barangay','barangay',$v('barangay'),'text',['icon'=>'pin','required'=>true,'maxlength'=>'80','list'=>'hamtic-barangays','placeholder'=>'For example, Guintas']) ?>
                        <?= U::field('Business email (optional)','business_email',$v('business_email'),'email',['icon'=>'mail','maxlength'=>'254','placeholder'=>'station@example.com']) ?>
                        <div class="sm:col-span-2"><?= U::textarea('Street, sitio or purok','business_address',$v('business_address'),['required'=>true,'maxlength'=>'300','rows'=>'2','placeholder'=>'Purok 2, beside the barangay hall','hint'=>'Include a landmark to help customers and riders find you.']) ?></div>
                    </div>
                    <datalist id="hamtic-barangays"><?php foreach (['Guintas','Poblacion','Bia-an','Buhang','Malandog','Bongbongan','Riverside'] as $barangay): ?><option value="<?= U::e($barangay) ?>"></option><?php endforeach ?></datalist>
                    <p class="mt-5 inline-flex items-center gap-2 rounded-lg bg-mist px-3 py-2 text-sm text-muted"><?= U::icon('pin','size-4 text-water') ?>Municipality and province: <span class="font-medium text-ink">Hamtic, Antique</span></p>
                </section>

                <section class="border-t border-line p-6 md:p-8" aria-labelledby="admin-heading">
                    <div class="mb-6 flex items-start gap-3"><span class="title-icon"><?= U::icon('user','size-5') ?></span><div><h2 id="admin-heading" class="section-title">Station administrator</h2><p class="mt-0.5 text-sm text-muted">This person manages the station’s daily operations and receives the login link.</p></div></div>
                    <div class="grid gap-x-5 gap-y-5 sm:grid-cols-2">
                        <?= U::field('Full name','administrator_name',$v('administrator_name'),'text',['icon'=>'user','required'=>true,'maxlength'=>'120','autocomplete'=>'name','placeholder'=>'Juana Dela Cruz']) ?>
                        <?= U::field('Email address','administrator_email',$v('administrator_email'),'email',['icon'=>'mail','required'=>true,'maxlength'=>'254','autocomplete'=>'email','placeholder'=>'you@example.com','hint'=>'The login link is sent here after approval.']) ?>
                        <?= U::field('Mobile number','administrator_mobile',$v('administrator_mobile'),'tel',['icon'=>'phone','required'=>true,'pattern'=>'(09[0-9]{9}|\\+639[0-9]{9})','placeholder'=>'09123456789','title'=>'Use 09 followed by 9 digits.','autocomplete'=>'tel']) ?>
                        <?= U::field('Your role at the station','business_role',$v('business_role'),'text',['icon'=>'shield','required'=>true,'maxlength'=>'80','placeholder'=>'Owner or authorized manager']) ?>
                    </div>
                    <div class="mt-6 flex items-start gap-3 rounded-xl bg-foam/70 p-4"><?= U::icon('lock','mt-0.5 size-5 shrink-0 text-water') ?><p class="text-sm"><span class="font-semibold">Your station’s operational records stay private.</span> <span class="text-muted">The registry administrator sees registration details only. Your orders and financial records belong to your station’s workspace.</span></p></div>
                </section>

                <div class="flex flex-col gap-5 border-t border-line bg-mist/80 p-6 md:flex-row md:items-center md:justify-between md:px-8">
                    <label class="flex max-w-md items-start gap-3 text-sm"><input type="checkbox" required name="authorized" value="yes" class="mt-0.5 shrink-0"><span>I am authorized to register this station in Hamtic, Antique, and the details above are correct.</span></label>
                    <?= U::btn('Submit registration','','primary','arrow-right',['type'=>'submit','class'=>'min-h-12 w-full px-6 text-base md:w-auto']) ?>
                </div>
            </form>
            <p class="mt-5 text-center text-sm text-muted md:text-left">Already approved? <a href="/login/station-admin" class="auth-link">Log in as Station Admin</a></p>
        </div>
    </div>
<?php endif ?>
</div></div>
