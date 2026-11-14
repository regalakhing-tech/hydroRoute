<?php
use App\UI\UI as U;
require __DIR__ . '/common.php';
if (!is_array($setup) || !is_array($setup['station'] ?? null)) return;
$products = $setup['products'];
$selectedId = is_string($_GET['product'] ?? null) ? $_GET['product'] : (string) ($products[0]['id'] ?? 'new');
if (($old['_form'] ?? '') === 'product') $selectedId = (string) ($old['product_id'] ?? 'new');
$product = null;
foreach ($products as $candidate) if ((string) $candidate['id'] === $selectedId) $product = $candidate;
$new = $selectedId === 'new' || $selectedId === '';
?>
<?= U::heading('Products', 'Manage your station catalogue, prices and package load.') ?>
<div class="grid gap-5 xl:grid-cols-2">
<section class="panel h-fit p-4 md:p-5">
<div class="mb-4 flex flex-wrap items-center justify-between gap-3"><?= U::title('Product catalogue', 'bottle') ?><?= U::btn('Add product', '/station-admin/products?product=new#product-editor', 'secondary', 'plus') ?></div>
<?php if ($products === []): ?><p class="text-muted" role="status">No products have been configured.</p><?php endif ?>
<?php foreach ($products as $item): ?>
<article class="flex flex-wrap items-center gap-4 border-t border-line py-4">
<div class="min-w-0 flex-1"><h3 class="font-bold"><?= U::e((string) $item['name']) ?></h3><p class="text-sm text-muted"><?= U::e((string) $item['pack_text']) ?></p><p class="mt-1 font-bold text-water">PHP <?= U::e($money($item['price_centavos'])) ?></p><div class="mt-2"><?= U::badge($bool($item['active']) ? 'Active' : 'Inactive', $bool($item['active']) ? 'green' : 'slate') ?></div></div>
<?= U::btn('Edit', '/station-admin/products?product=' . rawurlencode((string) $item['id']) . '#product-editor', 'secondary', 'edit', ['aria-label' => 'Edit ' . $item['name']]) ?>
</article><?php endforeach ?>
</section>
<?php if (!$new && $product === null): ?>
<section class="panel p-5" role="alert"><h2 class="font-bold">Product unavailable</h2><p>Choose a product from your station catalogue.</p></section>
<?php else:
    $product ??= ['id' => '', 'name' => '', 'price_centavos' => null, 'pack_text' => '', 'description' => '', 'image_key' => '', 'returnable' => false, 'load_units' => null, 'active' => true, 'version' => null];
    $start('products/save', 'product', $product['version'], ['product_id' => $product['id']], ['id' => 'product-editor', 'class' => 'panel h-fit scroll-mt-24 space-y-4 p-4 md:p-5']);
?>
<?= U::title($new ? 'Add product' : 'Edit product', 'edit') ?>
<?= $field('Product name', 'name', $value('product', 'name', $product['name']), 'text', ['required' => true, 'maxlength' => 100]) ?>
<?= $field('Price per package (PHP)', 'price', $value('product', 'price', $product['price_centavos'] === null ? '' : $money($product['price_centavos'])), 'number', ['required' => true, 'min' => 0, 'step' => '0.01', 'inputmode' => 'decimal']) ?>
<?= $field('Package description', 'pack_text', $value('product', 'pack_text', $product['pack_text']), 'text', ['required' => true, 'maxlength' => 100, 'hint' => 'Describe what one ordered package contains.']) ?>
<?= U::textarea('Description', 'description', (string) $value('product', 'description', $product['description']), ['maxlength' => 500]) ?>
<?= U::select('Product image', 'image_key', ['' => 'Choose image', 'water-jug' => 'Water jug', 'faucet-container' => 'Faucet container', 'water-bottle' => 'Water bottle'], (string) $value('product', 'image_key', $product['image_key']), ['required' => true]) ?>
<?= $field('Rider load units per package', 'load_units', $value('product', 'load_units', $product['load_units']), 'number', ['min' => 1, 'step' => '1', 'hint' => 'Leave blank until the package load is configured.']) ?>
<?= $check('Requires an empty container return', 'returnable', $bool($value('product', 'returnable', $product['returnable']))) ?>
<?= $check('Active in the catalogue', 'active', $bool($value('product', 'active', $product['active']))) ?>
<p class="text-sm text-muted">Clear Active in the catalogue to deactivate this product, then save.</p>
<?= $save($new ? 'Add product' : 'Save product') ?></form>
<?php endif ?></div>
