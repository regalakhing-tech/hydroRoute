<?php
use App\Services\Workspace;
use App\UI\LiveForm as F;
use App\UI\UI as U;

$live = Workspace::get()->load(static fn (Workspace $w): array => $w->account());
?>
<?= U::heading('Account', 'Your profile, notifications and sign-in.') ?>
<?php if (isset($live['error'])): ?><section class="panel p-6" role="alert"><?= U::e($live['error']) ?></section><?php return; endif;
$p = $live['profile'];
$phone = $p['phone'] && preg_match('/^\+63(9\d{9})$/', $p['phone'], $m) ? '0' . $m[1] : '';
$errors = is_array($_SESSION['form_errors'] ?? null) ? $_SESSION['form_errors'] : []; unset($_SESSION['form_errors']);
$err = static fn (string $f): string => isset($errors[$f]) ? '<p class="mt-1 text-sm font-semibold text-rose-700" role="alert">' . U::e($errors[$f]) . '</p>' : '';
?>
<div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_380px]"><div class="space-y-5">
<?= F::open('/customer/account/profile', ['expected_version' => $p['version']], ['class' => 'panel space-y-4 p-4 md:p-6']) ?><?= U::title('Personal information', 'user') ?>
<div class="grid gap-4 sm:grid-cols-2"><div><?= U::field('Full name', 'display_name', $p['display_name'], 'text', ['icon' => 'user', 'required' => true, 'minlength' => '2', 'maxlength' => '120', 'autocomplete' => 'name']) ?><?= $err('display_name') ?></div><div><?= U::field('Mobile number', 'phone', $phone, 'tel', ['icon' => 'phone', 'required' => true, 'placeholder' => '0917 123 4567', 'autocomplete' => 'tel']) ?><?= $err('phone') ?></div></div>
<div><label class="label" for="email">Email address</label><input class="field bg-mist" id="email" value="<?= U::e($live['email']) ?>" readonly aria-describedby="email-hint"><p class="hint" id="email-hint">Your sign-in email cannot be changed here.</p></div>
<fieldset class="space-y-2"><legend class="label">Notifications</legend><label class="flex items-center gap-3 text-sm"><input type="checkbox" name="order_updates" value="1" <?= $p['order_updates'] ? 'checked' : '' ?> class="accent-cyan-600">Order updates (confirmed, ready, on the way, delivered)</label><label class="flex items-center gap-3 text-sm"><input type="checkbox" name="station_updates" value="1" <?= $p['station_updates'] ? 'checked' : '' ?> class="accent-cyan-600">Station notices (closures and schedule changes)</label><p class="hint">Your order history and status page always stay up to date.</p></fieldset>
<?= U::btn('Save changes', '', 'primary', 'check', ['type' => 'submit', 'class' => 'w-full sm:w-auto']) ?></form></div>
<aside class="space-y-5">
<?= F::open('/customer/account/password', [], ['class' => 'panel space-y-3 p-4 md:p-6']) ?><?= U::title('Change password', 'lock') ?>
<?= U::field('Current password', 'current_password', '', 'password', ['required' => true, 'autocomplete' => 'current-password']) ?><?= U::field('New password', 'password', '', 'password', ['required' => true, 'minlength' => '8', 'autocomplete' => 'new-password']) ?><?= U::field('Confirm new password', 'confirm_password', '', 'password', ['required' => true, 'minlength' => '8', 'autocomplete' => 'new-password']) ?>
<?= U::btn('Change password', '', 'secondary', 'lock', ['type' => 'submit', 'class' => 'w-full']) ?></form>
<section class="panel space-y-3 p-4 md:p-6"><?= U::title('Sign out everywhere', 'logout') ?><p class="text-sm text-muted">Signs this account out on every phone and computer. Other devices stop within about an hour, at their next sign-in check.</p><?= F::button('/customer/account/logout-all', [], 'Sign out on all devices', 'secondary', 'logout', 'Sign out on all devices, including this one?', 'w-full') ?></section>
</aside></div>
