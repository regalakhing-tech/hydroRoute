<?php if (!($data['preview'] ?? false)) { require __DIR__ . '/live/addresses.php'; return; } ?>
<?php use App\UI\UI as U; ?>
<?= U::heading('Addresses', '', U::btn('Add address', '#address-form', 'primary', 'plus')) ?>
<div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_360px]">
<section class="panel p-4 md:p-6">
    <h2 class="section-title mb-4 flex items-center gap-2.5"><span class="title-icon"><?= U::icon('pin','size-5') ?></span>Saved addresses</h2>
    <div class="grid gap-3 md:grid-cols-2">
    <?php foreach ($data['sample']['addresses'] as $address): ?>
        <article class="flex flex-col rounded-xl border bg-white p-4 <?= $address['default'] ? 'border-water ring-1 ring-water/30' : 'border-line' ?>">
            <div class="flex items-center gap-3">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-lg <?= $address['default'] ? 'bg-water text-white' : 'bg-foam text-water' ?>"><?= U::icon($address['label'] === 'Home' ? 'home' : 'store', 'size-5') ?></span>
                <h3 class="flex-1 font-bold"><?= U::e($address['label']) ?></h3>
                <?php if ($address['default']) echo U::badge('Default', 'blue'); ?>
            </div>
            <p class="mt-3 flex items-start gap-2 text-sm"><?= U::icon('pin', 'mt-0.5 size-4 shrink-0 text-water') ?><?= U::e($address['address']) ?></p>
            <p class="mt-1.5 flex items-start gap-2 text-sm text-muted"><?= U::icon('file', 'mt-0.5 size-4 shrink-0') ?><?= U::e($address['note']) ?></p>
            <div class="mt-4 flex gap-2 border-t border-line pt-3">
                <?= U::btn('Edit', '#address-form', 'ghost', 'edit', ['class' => 'btn-sm flex-1']) ?>
                <?php if (!$address['default']): ?><?= U::btn('Make default', '', 'secondary', 'check', ['class' => 'btn-sm flex-1', 'data-toast' => 'Default preference selected for this preview. Save the address form to keep your own details locally.']) ?><?php endif ?>
            </div>
        </article>
    <?php endforeach; ?>
    </div>
</section>
<section class="panel scroll-mt-24 p-4 md:p-6" id="address-form">
    <h2 class="section-title mb-4 flex items-center gap-2.5"><span class="title-icon"><?= U::icon('plus','size-5') ?></span>Add or update address</h2>
    <form data-local-form data-persist="customer-address" data-success="Address details saved in this browser." class="space-y-4">
        <?= U::field('Address label', 'label', '', 'text', ['icon' => 'tag', 'required' => true, 'maxlength' => '40', 'placeholder' => 'e.g. Home']) ?>
        <?= U::select('Barangay', 'barangay', ['' => 'Choose barangay', 'Guintas' => 'Guintas', 'Poblacion' => 'Poblacion', 'Riverside' => 'Riverside'], '', ['required' => true]) ?>
        <?= U::field('Street, purok or house number', 'street', '', 'text', ['icon' => 'home', 'required' => true, 'maxlength' => '150']) ?>
        <?= U::textarea('Landmark or delivery instructions', 'landmark', '', ['maxlength' => '200', 'rows' => 2, 'placeholder' => 'e.g. Near the barangay hall']) ?>
        <label class="flex items-center gap-2 text-sm"><input name="default" type="checkbox" class="accent-cyan-600">Use as my default address</label>
        <?= U::btn('Save address', '', 'primary', 'check', ['type' => 'submit', 'class' => 'w-full']) ?>
        <p class="flex items-center gap-2 text-xs text-muted"><?= U::icon('shield', 'size-4 shrink-0') ?>Saved in this browser only.</p>
    </form>
</section>
</div>
