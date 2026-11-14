<?php use App\UI\UI as U; $sample = $data['sample']; $home = $sample['addresses'][0]; ?>
<?= U::heading('Account') ?>
<section class="relative mb-5 overflow-hidden rounded-2xl bg-linear-to-br from-cyan-600 to-teal-700 p-4 text-white shadow-md md:p-5">
    <span class="pointer-events-none absolute -right-8 -top-10 size-36 rounded-full bg-white/10" aria-hidden="true"></span>
    <div class="relative flex items-center gap-3.5"><span class="flex size-14 shrink-0 items-center justify-center rounded-full bg-white text-lg font-bold text-water">MS</span><div class="min-w-0"><h2 class="truncate text-lg font-bold">Maria Santos</h2><p class="flex items-center gap-1.5 text-sm"><?= U::icon('pin','size-4 shrink-0') ?>Customer, Hamtic, Antique</p></div></div>
    <dl class="relative mt-4 grid grid-cols-3 gap-2 text-center">
        <?php foreach ([['orders','Orders',(string)count($sample['orders'])],['pin','Addresses',(string)count($sample['addresses'])],['check','Member since','2026']] as [$icon,$label,$value]): ?><div class="rounded-xl bg-white/15 px-2 py-2.5 ring-1 ring-white/20"><dt class="flex items-center justify-center gap-1 text-[11px]"><?= U::icon($icon,'size-3.5') ?><?= U::e($label) ?></dt><dd class="text-lg font-bold"><?= U::e($value) ?></dd></div><?php endforeach ?>
    </dl>
</section>
<div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_340px]">
<div class="space-y-5">
    <form class="panel space-y-4 p-4 md:p-6" data-local-form data-persist="customer-account" data-success="Profile details saved in this browser.">
        <?= U::title('Personal information','user') ?>
        <div class="grid gap-4 sm:grid-cols-2"><?= U::field('First name', 'first_name', 'Maria', 'text', ['icon'=>'user','required' => true, 'maxlength' => '60', 'autocomplete' => 'given-name']) ?><?= U::field('Last name', 'last_name', 'Santos', 'text', ['icon'=>'user','required' => true, 'maxlength' => '60', 'autocomplete' => 'family-name']) ?></div>
        <div class="grid gap-4 sm:grid-cols-2"><?= U::field('Email address', 'email', 'maria.santos@example.com', 'email', ['icon'=>'mail','required' => true, 'autocomplete' => 'email']) ?><?= U::field('Mobile number', 'phone', '09171234567', 'tel', ['icon'=>'phone','required' => true, 'pattern' => '[0-9+ \(\)\-]{10,20}', 'autocomplete' => 'tel']) ?></div>
        <?= U::btn('Save changes', '', 'primary', 'check', ['type' => 'submit', 'class' => 'w-full sm:w-auto']) ?>
    </form>
    <form class="panel space-y-3 p-4 md:p-6" data-local-form data-persist="customer-preferences" data-success="Preferences saved in this browser.">
        <?= U::title('Notifications','bell','mb-1') ?>
        <?php foreach ([['order_updates','truck','Order updates','Confirmation, preparation and delivery progress.',true],['station_updates','store','Station announcements','Service hours and local station updates.',false]] as [$name,$icon,$label,$copy,$on]): ?>
        <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-line bg-white p-3.5 has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-water">
            <span class="title-icon"><?= U::icon($icon,'size-5') ?></span>
            <span class="min-w-0 flex-1"><span class="block text-sm font-semibold"><?= U::e($label) ?></span><span class="block text-xs text-muted"><?= U::e($copy) ?></span></span>
            <input type="checkbox" name="<?= U::e($name) ?>" value="on" class="peer sr-only" <?= $on ? 'checked' : '' ?>>
            <span class="relative h-6 w-11 shrink-0 rounded-full bg-slate-300 transition-colors after:absolute after:left-0.5 after:top-0.5 after:size-5 after:rounded-full after:bg-white after:shadow after:transition-transform peer-checked:bg-water peer-checked:after:translate-x-5" aria-hidden="true"></span>
        </label>
        <?php endforeach ?>
        <?= U::btn('Save preferences', '', 'secondary', 'check', ['type' => 'submit', 'class' => 'w-full sm:w-auto']) ?>
    </form>
</div>
<aside class="space-y-5">
    <section class="panel p-4 md:p-5">
        <?= U::title('Delivery address','home','mb-3') ?>
        <div class="rounded-xl border border-water/30 bg-foam/60 p-3.5"><p class="flex items-center justify-between gap-2"><strong><?= U::e($home['label']) ?></strong><?= U::badge('Default','blue') ?></p><p class="mt-2 flex items-start gap-2 text-sm"><?= U::icon('pin','mt-0.5 size-4 shrink-0 text-water') ?><?= U::e($home['address']) ?></p><p class="mt-1 flex items-start gap-2 text-sm text-muted"><?= U::icon('file','mt-0.5 size-4 shrink-0') ?><?= U::e($home['note']) ?></p></div>
        <?= U::btn('Manage addresses', '/customer/addresses', 'secondary', 'pin', ['class'=>'mt-3 w-full']) ?>
    </section>
    <nav class="panel overflow-hidden p-0" aria-label="Account shortcuts">
        <?php foreach ([['/customer/orders','orders','My orders'],['/customer/notifications','bell','Notifications'],['/customer/messages','message','Messages']] as [$href,$icon,$label]): ?><a href="<?= U::e($href) ?>" class="flex items-center gap-3 border-b border-line px-4 py-3 text-sm font-semibold last:border-b-0 hover:bg-mist"><span class="title-icon size-8"><?= U::icon($icon,'size-4') ?></span><span class="flex-1"><?= U::e($label) ?></span><?= U::icon('chevron-right','size-4 text-muted') ?></a><?php endforeach ?>
    </nav>
    <section class="panel space-y-3 p-4 md:p-5">
        <?= U::title('Account security','shield') ?>
        <p class="text-sm text-muted">Password and identity features will be available when account services are connected.</p>
        <div class="grid gap-2"><?= U::btn('Password options', '', 'secondary', 'lock', ['data-toast' => 'Password changes are unavailable in the UI preview. No password is stored.', 'data-demo-action' => true]) ?><?= U::btn('Log out', $logoutHref, 'danger', 'logout', ['data-logout' => 'customer']) ?></div>
    </section>
</aside>
</div>
