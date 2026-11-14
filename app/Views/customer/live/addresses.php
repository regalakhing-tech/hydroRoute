<?php
// Original addresses design, saving to the account; the map pin fills in the barangay and street.
use App\Services\Workspace;
use App\UI\LiveForm as F;
use App\UI\UI as U;

$live = Workspace::get()->load(static fn (Workspace $w): array => $w->addresses());
$addresses = $live['addresses'] ?? [];
$editId = is_string($_GET['edit'] ?? null) ? $_GET['edit'] : '';
$edit = current(array_filter($addresses, static fn (array $a): bool => $a['id'] === $editId)) ?: null;
$errors = is_array($_SESSION['form_errors'] ?? null) ? $_SESSION['form_errors'] : []; unset($_SESSION['form_errors']);
$err = static fn (string $f): string => isset($errors[$f]) ? '<p class="mt-1 text-sm font-semibold text-rose-700" role="alert">' . U::e($errors[$f]) . '</p>' : '';
?>
<?= U::heading('Addresses', '', U::btn('Add address', '/customer/addresses#address-form', 'primary', 'plus')) ?>
<div class="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_400px]">
<section class="panel p-4 md:p-6">
    <h2 class="section-title mb-4 flex items-center gap-2.5"><span class="title-icon"><?= U::icon('pin','size-5') ?></span>Saved addresses</h2>
    <?php if (isset($live['error'])): ?><p class="mb-3 text-sm" role="alert"><?= U::e($live['error']) ?></p><?php endif ?>
    <div class="grid gap-3 md:grid-cols-2">
    <?php foreach ($addresses as $address): ?>
        <article class="flex flex-col rounded-xl border bg-white p-4 <?= $address['is_default'] ? 'border-water ring-1 ring-water/30' : 'border-line' ?>">
            <div class="flex items-center gap-3">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-lg <?= $address['is_default'] ? 'bg-water text-white' : 'bg-foam text-water' ?>"><?= U::icon(strcasecmp($address['label'], 'Home') === 0 ? 'home' : 'store', 'size-5') ?></span>
                <h3 class="flex-1 font-bold"><?= U::e($address['label']) ?></h3>
                <?php if ($address['is_default']) echo U::badge('Default', 'blue'); ?>
            </div>
            <p class="mt-3 flex items-start gap-2 text-sm"><?= U::icon('pin', 'mt-0.5 size-4 shrink-0 text-water') ?><?= U::e($address['address']) ?></p>
            <p class="mt-1.5 flex items-start gap-2 text-sm text-muted"><?= U::icon('file', 'mt-0.5 size-4 shrink-0') ?><?= U::e($address['landmark'] !== '' ? $address['landmark'] : ($address['latitude'] === null ? 'No map pin yet: pickup only' : 'Map pin saved')) ?></p>
            <div class="mt-4 flex gap-2 border-t border-line pt-3">
                <?= U::btn('Edit', '/customer/addresses?edit=' . $address['id'] . '#address-form', 'ghost', 'edit', ['class' => 'btn-sm flex-1']) ?>
                <?php if (!$address['is_default']): ?><?= F::open('/customer/addresses/default', ['address_id' => $address['id'], 'expected_version' => $address['version']], ['class' => 'flex-1']) ?><?= U::btn('Make default', '', 'secondary', 'check', ['type' => 'submit', 'class' => 'btn-sm w-full']) ?></form><?php endif ?>
                <?= F::open('/customer/addresses/delete', ['address_id' => $address['id'], 'expected_version' => $address['version']]) ?><?= U::btn('', '', 'ghost', 'close', ['type' => 'submit', 'class' => 'btn-sm', 'aria-label' => 'Remove ' . $address['label'], 'data-confirm' => 'Remove this address? Past orders keep their delivery details.']) ?></form>
            </div>
        </article>
    <?php endforeach; ?>
        <a href="/customer/addresses#address-form" class="flex min-h-44 flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-line p-4 text-center transition hover:border-water hover:bg-foam/60"><span class="flex size-11 items-center justify-center rounded-full bg-foam text-water"><?= U::icon('plus', 'size-5') ?></span><span class="font-bold text-water"><?= $addresses === [] ? 'Add your first address' : 'Add another address' ?></span><span class="text-xs text-muted">Pin it on the map so riders find your gate.</span></a>
    </div>
</section>
<section class="panel scroll-mt-24 p-4 md:p-6" id="address-form">
    <h2 class="section-title mb-4 flex items-center gap-2.5"><span class="title-icon"><?= U::icon($edit ? 'edit' : 'plus','size-5') ?></span><?= $edit ? 'Update ' . U::e($edit['label']) : 'Add or update address' ?></h2>
    <?= F::open('/customer/addresses/save', $edit ? ['address_id' => $edit['id'], 'expected_version' => $edit['version']] : [], ['class' => 'space-y-4', 'data-pin-picker' => true, 'data-boundaries' => '/assets/data/hamtic-barangays.json', 'data-reverse' => '/customer/addresses/reverse']) ?>
        <?= U::field('Address label', 'label', $edit['label'] ?? '', 'text', ['icon' => 'tag', 'required' => true, 'maxlength' => '40', 'placeholder' => 'e.g. Home']) ?><?= $err('label') ?>
        <div data-pin-map class="h-56 w-full rounded-xl border border-line bg-mist" role="application" aria-label="Map: tap to place your address pin"></div>
        <p data-pin-status class="text-xs text-muted" role="status">Tap the map or use your current location. Your barangay and street are filled in from the pin; drag the pin to your exact gate.</p>
        <button type="button" data-pin-device class="btn btn-secondary w-full"><?= U::icon('pin', 'size-4.5 shrink-0') ?><span>Use my current location</span></button>
        <div class="grid grid-cols-2 gap-3"><?= U::field('Latitude', 'latitude', (string) ($edit['latitude'] ?? ''), 'text', ['inputmode' => 'decimal', 'placeholder' => '10.70']) ?><?= U::field('Longitude', 'longitude', (string) ($edit['longitude'] ?? ''), 'text', ['inputmode' => 'decimal', 'placeholder' => '121.98']) ?></div><input type="hidden" name="pin_source" value="<?= U::e($edit['pin_source'] ?? '') ?>"><?= $err('latitude') ?>
        <div><label class="label" for="barangay_id">Barangay</label><select class="field" id="barangay_id" name="barangay_id" required><option value="">Choose barangay</option><?php foreach ($live['barangays'] ?? [] as $b): ?><option value="<?= U::e($b['id']) ?>" data-psgc="<?= U::e($b['psgc_code'] ?? '') ?>"<?= ($edit['barangay_id'] ?? '') === $b['id'] ? ' selected' : '' ?>><?= U::e($b['name']) ?></option><?php endforeach ?></select><?= $err('barangay_id') ?></div>
        <?= U::field('Street, purok or house number', 'street', $edit['street'] ?? '', 'text', ['icon' => 'home', 'required' => true, 'maxlength' => '150']) ?><?= $err('street') ?>
        <?= U::textarea('Landmark or delivery instructions', 'landmark', $edit['landmark'] ?? '', ['maxlength' => '200', 'rows' => 2, 'placeholder' => 'e.g. Near the barangay hall']) ?>
        <label class="flex items-center gap-2 text-sm"><input name="is_default" type="checkbox" value="1" class="accent-cyan-600" <?= !empty($edit['is_default']) || $addresses === [] ? 'checked' : '' ?>>Use as my default address</label>
        <?= U::btn('Save address', '', 'primary', 'check', ['type' => 'submit', 'class' => 'w-full']) ?>
        <p class="flex items-center gap-2 text-xs text-muted"><?= U::icon('shield', 'size-4 shrink-0') ?>Saved to your account. Map data &copy; OpenStreetMap contributors.</p>
    </form>
</section>
</div>
