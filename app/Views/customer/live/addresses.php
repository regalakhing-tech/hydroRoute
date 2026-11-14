<?php
use App\Services\Workspace;
use App\UI\LiveForm as F;
use App\UI\UI as U;

$live = Workspace::get()->load(static fn (Workspace $w): array => $w->addresses());
?>
<?= U::heading('Addresses', 'Pin your address on the map so stations can work out the delivery fee.', U::btn('Add address', '/customer/addresses#address-form', 'primary', 'plus')) ?>
<?php if (isset($live['error'])): ?><section class="panel p-6" role="alert"><?= U::e($live['error']) ?></section><?php return; endif;
$editId = is_string($_GET['edit'] ?? null) ? $_GET['edit'] : '';
$edit = current(array_filter($live['addresses'], static fn (array $a): bool => $a['id'] === $editId)) ?: null;
$errors = is_array($_SESSION['form_errors'] ?? null) ? $_SESSION['form_errors'] : []; unset($_SESSION['form_errors']);
$err = static fn (string $f): string => isset($errors[$f]) ? '<p class="mt-1 text-sm font-semibold text-rose-700" role="alert">' . U::e($errors[$f]) . '</p>' : '';
$barangays = array_column($live['barangays'], 'name', 'id');
?>
<div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_420px]">
<section class="panel p-4 md:p-6"><?= U::title('Saved addresses', 'pin', 'mb-4') ?>
<?php if ($live['addresses'] === []): ?><p class="text-sm text-muted">No saved address yet. Add one with a map pin to order delivery. Pickup orders need no address.</p><?php endif ?>
<div class="grid gap-3 md:grid-cols-2"><?php foreach ($live['addresses'] as $a): ?>
<article class="flex flex-col rounded-xl border bg-white p-4 <?= $a['is_default'] ? 'border-water ring-1 ring-water/30' : 'border-line' ?>"><div class="flex items-center gap-3"><span class="flex size-10 shrink-0 items-center justify-center rounded-lg <?= $a['is_default'] ? 'bg-water text-white' : 'bg-foam text-water' ?>"><?= U::icon('pin', 'size-5') ?></span><h3 class="flex-1 font-bold"><?= U::e($a['label']) ?></h3><?= $a['is_default'] ? U::badge('Default', 'blue') : '' ?></div>
<p class="mt-3 text-sm"><?= U::e($a['address']) ?></p><?php if ($a['landmark'] !== ''): ?><p class="mt-1 text-sm text-muted">Landmark: <?= U::e($a['landmark']) ?></p><?php endif ?>
<p class="mt-2 text-xs <?= $a['latitude'] === null ? 'font-semibold text-amber-700' : 'text-muted' ?>"><?= $a['latitude'] === null ? 'No map pin: pickup only until you add one' : 'Pinned (' . U::e(['map' => 'map', 'device' => 'device location', 'manual' => 'typed coordinates'][$a['pin_source']] ?? 'pin') . ')' ?></p>
<div class="mt-4 flex flex-wrap gap-2 border-t border-line pt-3"><?= U::btn('Edit', '/customer/addresses?edit=' . $a['id'] . '#address-form', 'ghost', 'edit', ['class' => 'btn-sm']) ?><?php if (!$a['is_default']): ?><?= F::button('/customer/addresses/default', ['address_id' => $a['id'], 'expected_version' => $a['version']], 'Make default', 'secondary', 'check') ?><?php endif ?><?= F::button('/customer/addresses/delete', ['address_id' => $a['id'], 'expected_version' => $a['version']], 'Remove', 'ghost', 'close', 'Remove this address? Past orders keep their delivery details.') ?></div></article>
<?php endforeach ?></div></section>
<section class="panel scroll-mt-24 p-4 md:p-6" id="address-form"><?= U::title($edit ? 'Edit ' . $edit['label'] : 'Add an address', $edit ? 'edit' : 'plus', 'mb-4') ?>
<?= F::open('/customer/addresses/save', $edit ? ['address_id' => $edit['id'], 'expected_version' => $edit['version']] : [], ['class' => 'space-y-4', 'data-pin-picker' => true]) ?>
<div><?= U::field('Label', 'label', $edit['label'] ?? '', 'text', ['required' => true, 'maxlength' => '60', 'placeholder' => 'Home, Work, Mother\'s house']) ?><?= $err('label') ?></div>
<div><?= U::select('Barangay', 'barangay_id', ['' => 'Choose a barangay'] + $barangays, (string) ($edit['barangay_id'] ?? ''), ['required' => true]) ?><?= $err('barangay_id') ?></div>
<div><?= U::field('Street, purok or house', 'street', $edit['street'] ?? '', 'text', ['required' => true, 'minlength' => '3', 'maxlength' => '200', 'placeholder' => 'Purok 2, near the chapel']) ?><?= $err('street') ?></div>
<div><?= U::field('Landmark (optional)', 'landmark', $edit['landmark'] ?? '', 'text', ['maxlength' => '200', 'placeholder' => 'Blue gate beside the sari-sari store']) ?><?= $err('landmark') ?></div>
<fieldset class="space-y-2"><legend class="label">Map pin</legend><div data-pin-map class="h-64 w-full rounded-xl border border-line bg-mist" role="application" aria-label="Map: tap to place your address pin"></div><p data-pin-status class="text-sm text-muted" role="status">Tap the map to place your pin, or use your current location.</p><button type="button" data-pin-device class="btn btn-secondary btn-sm"><?= U::icon('pin', 'size-4') ?><span>Use my current location</span></button>
<div class="grid grid-cols-2 gap-3"><?= U::field('Latitude', 'latitude', $edit['latitude'] ?? '', 'text', ['inputmode' => 'decimal', 'placeholder' => '10.70']) ?><?= U::field('Longitude', 'longitude', $edit['longitude'] ?? '', 'text', ['inputmode' => 'decimal', 'placeholder' => '122.03']) ?></div><input type="hidden" name="pin_source" value="<?= U::e($edit['pin_source'] ?? '') ?>"><?= $err('latitude') ?>
<p class="hint">Leave the pin empty to save an address for pickup only. Map data &copy; OpenStreetMap contributors.</p></fieldset>
<label class="flex items-center gap-3 text-sm"><input type="checkbox" name="is_default" value="1" <?= !empty($edit['is_default']) || $live['addresses'] === [] ? 'checked' : '' ?> class="accent-cyan-600">Use as my default address</label>
<div class="flex flex-wrap gap-2"><?= U::btn('Save address', '', 'primary', 'check', ['type' => 'submit']) ?><?php if ($edit): ?><?= U::btn('Cancel', '/customer/addresses', 'ghost') ?><?php endif ?></div></form></section></div>
