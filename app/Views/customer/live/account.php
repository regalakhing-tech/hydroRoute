<?php
// Original account design, saving the profile, preferences and password through the account services.
use App\Services\Workspace;
use App\UI\LiveForm as F;
use App\UI\UI as U;

$live = Workspace::get()->load(static fn (Workspace $w): array => $w->account());
if (isset($live['error'])): ?><?= U::heading('Account') ?><section class="panel p-6" role="alert"><?= U::e($live['error']) ?></section><?php return; endif;
$p = $live['profile']; $home = $live['addresses'][0] ?? null;
$parts = preg_split('/\s+/u', trim((string) $p['display_name']), 2) ?: [''];
[$firstName, $lastName] = [$parts[0] ?? '', $parts[1] ?? ''];
$initials = mb_strtoupper(mb_substr($firstName, 0, 1) . mb_substr($lastName, 0, 1)) ?: '?';
$phone = $p['phone'] && preg_match('/^\+63(9\d{9})$/', (string) $p['phone'], $m) ? '0' . $m[1] : '';
$logoutHref = '/logout?token=' . App\Core\Session::csrfToken();
$profileFields = ['expected_version' => $p['version']];
?>
<?= U::heading('Account') ?>
<section class="relative mb-5 overflow-hidden rounded-2xl bg-linear-to-br from-cyan-600 to-teal-700 p-4 text-white shadow-md md:p-5">
    <span class="pointer-events-none absolute -right-8 -top-10 size-36 rounded-full bg-white/10" aria-hidden="true"></span>
    <div class="relative flex items-center gap-3.5"><span class="flex size-14 shrink-0 items-center justify-center rounded-full bg-white text-lg font-bold text-water"><?= U::e($initials) ?></span><div class="min-w-0"><h2 class="truncate text-lg font-bold"><?= U::e($p['display_name'] ?: 'Customer') ?></h2><p class="flex items-center gap-1.5 text-sm"><?= U::icon('pin','size-4 shrink-0') ?>Customer, Hamtic, Antique</p></div></div>
    <dl class="relative mt-4 grid grid-cols-3 gap-2 text-center">
        <?php foreach ([['orders','Orders',(string) $live['orders']],['pin','Addresses',(string) count($live['addresses'])],['check','Member since',$live['since']]] as [$icon,$label,$value]): ?><div class="rounded-xl bg-white/15 px-2 py-2.5 ring-1 ring-white/20"><dt class="flex items-center justify-center gap-1 text-[11px]"><?= U::icon($icon,'size-3.5') ?><?= U::e($label) ?></dt><dd class="text-lg font-bold"><?= U::e($value) ?></dd></div><?php endforeach ?>
    </dl>
</section>
<div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_340px]">
<div class="space-y-5">
    <?= F::open('/customer/account/profile', $profileFields + ($p['order_updates'] ? ['order_updates' => 'on'] : []) + ($p['station_updates'] ? ['station_updates' => 'on'] : []), ['class' => 'panel space-y-4 p-4 md:p-6']) ?>
        <?= U::title('Personal information','user') ?>
        <div class="grid gap-4 sm:grid-cols-2"><?= U::field('First name', 'first_name', $firstName, 'text', ['icon'=>'user','required' => true, 'maxlength' => '60', 'autocomplete' => 'given-name']) ?><?= U::field('Last name', 'last_name', $lastName, 'text', ['icon'=>'user','maxlength' => '60', 'autocomplete' => 'family-name']) ?></div>
        <div class="grid gap-4 sm:grid-cols-2"><?= U::field('Email address', 'email', $live['email'], 'email', ['icon'=>'mail','readonly' => true, 'autocomplete' => 'email']) ?><?= U::field('Mobile number', 'phone', $phone, 'tel', ['icon'=>'phone','required' => true, 'pattern' => '[0-9+ \(\)\-]{10,20}', 'autocomplete' => 'tel']) ?></div>
        <?= U::btn('Save changes', '', 'primary', 'check', ['type' => 'submit', 'class' => 'w-full sm:w-auto']) ?>
    </form>
    <?= F::open('/customer/account/profile', $profileFields + ['display_name' => $p['display_name'], 'phone' => $phone], ['class' => 'panel space-y-3 p-4 md:p-6']) ?>
        <?= U::title('Notifications','bell','mb-1') ?>
        <?php foreach ([['order_updates','truck','Order updates','Confirmation, preparation and delivery progress.',(bool) $p['order_updates']],['station_updates','store','Station announcements','Service hours and local station updates.',(bool) $p['station_updates']]] as [$name,$icon,$label,$copy,$on]): ?>
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
        <?php if ($home): ?><div class="rounded-xl border border-water/30 bg-foam/60 p-3.5"><p class="flex items-center justify-between gap-2"><strong><?= U::e($home['label']) ?></strong><?= $home['is_default'] ? U::badge('Default','blue') : '' ?></p><p class="mt-2 flex items-start gap-2 text-sm"><?= U::icon('pin','mt-0.5 size-4 shrink-0 text-water') ?><?= U::e($home['address']) ?></p><?php if ($home['landmark'] !== ''): ?><p class="mt-1 flex items-start gap-2 text-sm text-muted"><?= U::icon('file','mt-0.5 size-4 shrink-0') ?><?= U::e($home['landmark']) ?></p><?php endif ?></div>
        <?php else: ?><p class="text-sm text-muted">No saved address yet.</p><?php endif ?>
        <?= U::btn('Manage addresses', '/customer/addresses', 'secondary', 'pin', ['class'=>'mt-3 w-full']) ?>
    </section>
    <nav class="panel overflow-hidden p-0" aria-label="Account shortcuts">
        <?php foreach ([['/customer/orders','orders','My orders'],['/customer/notifications','bell','Notifications'],['/customer/messages','message','Messages']] as [$href,$icon,$label]): ?><a href="<?= U::e($href) ?>" class="flex items-center gap-3 border-b border-line px-4 py-3 text-sm font-semibold last:border-b-0 hover:bg-mist"><span class="title-icon size-8"><?= U::icon($icon,'size-4') ?></span><span class="flex-1"><?= U::e($label) ?></span><?= U::icon('chevron-right','size-4 text-muted') ?></a><?php endforeach ?>
    </nav>
    <section class="panel space-y-3 p-4 md:p-5">
        <?= U::title('Account security','shield') ?>
        <p class="text-sm text-muted">Change your password or sign out of every device.</p>
        <div class="grid gap-2"><?= U::btn('Password options', '', 'secondary', 'lock', ['data-dialog-open' => 'password-dialog']) ?><?= U::btn('Log out', $logoutHref, 'danger', 'logout', ['data-logout' => 'customer']) ?></div>
    </section>
</aside>
</div>
<dialog id="password-dialog" class="w-full max-w-md rounded-xl border border-line p-6 text-ink" aria-labelledby="password-title">
    <div class="mb-4 flex justify-between"><h2 id="password-title" class="section-title">Password options</h2><button type="button" data-dialog-close aria-label="Close"><?= U::icon('close') ?></button></div>
    <?= F::open('/customer/account/password', [], ['class' => 'space-y-3']) ?><?= U::field('Current password', 'current_password', '', 'password', ['required' => true, 'autocomplete' => 'current-password']) ?><?= U::field('New password', 'password', '', 'password', ['required' => true, 'minlength' => '8', 'autocomplete' => 'new-password']) ?><?= U::field('Confirm new password', 'confirm_password', '', 'password', ['required' => true, 'minlength' => '8', 'autocomplete' => 'new-password']) ?><?= U::btn('Change password', '', 'primary', 'lock', ['type' => 'submit', 'class' => 'w-full']) ?></form>
    <div class="mt-4 border-t border-line pt-4"><?= F::button('/customer/account/logout-all', [], 'Sign out on all devices', 'secondary', 'logout', 'Sign out on every device, including this one?', 'w-full') ?></div>
</dialog>
