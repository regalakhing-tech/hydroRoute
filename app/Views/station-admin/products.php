<?php use App\UI\UI as U;
$products = \App\UI\Products::all();
$id = $_GET['product'] ?? 'round';
$product = \App\UI\Products::get($id);
if (!$product): ?>
<?= U::heading('Product not found', 'Choose a product from the catalogue.') ?>
<?= U::btn('View catalogue', '/station-admin/products') ?>
<?php return; endif; ?>
<?= U::heading('Products', 'Manage water bottles and container refills.', U::badge(count($products).' active products', 'green')) ?>
<div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_340px]">
<section class="panel p-4 md:p-5"><?= U::title('Product catalogue','bottle','mb-3') ?>
<?php foreach ($products as $key => $item): ?>
<article class="flex flex-wrap items-center gap-4 border-t border-line py-4">
<span class="flex size-20 shrink-0 items-center justify-center rounded-xl bg-foam"><?= U::productImage('h-16 w-14', $key) ?></span>
<div class="min-w-40 flex-1"><h3 class="font-bold"><?= U::e($item['name']) ?></h3><p class="text-sm text-muted"><?= U::e($item['description']) ?></p><div class="mt-1.5 flex flex-wrap items-center gap-2"><span class="inline-flex items-center gap-1 text-lg font-bold text-water"><?= U::icon('tag','size-4') ?><?= U::money($item['price']) ?></span><?= U::badge('Available', 'green') ?></div></div>
<?= U::btn('Edit', '/station-admin/products?product='.$key.'#product-editor', $key===$id ? 'primary' : 'secondary', 'edit', ['class'=>'btn-sm','aria-label'=>'Edit '.$item['name']]) ?>
</article><?php endforeach; ?>
<div class="mt-3 flex items-start gap-3 rounded-xl bg-foam p-3.5"><span class="title-icon bg-white"><?= U::icon('refresh','size-5') ?></span><div><h3 class="font-bold">Container policy</h3><p class="text-sm text-muted">Refill prices assume an empty container exchange. Bottled water and new jugs need no return. Delivery fee: PHP 5 under 1 km, PHP 10 for 1–3 km, PHP 15 beyond 3 km.</p></div></div></section>
<form id="product-editor" class="panel h-fit scroll-mt-24 space-y-4 self-start p-4 md:p-5" data-local-form data-persist="station-product-v2-<?= U::e($id) ?>" data-success="Product settings saved in this browser. Catalogue prices remain the confirmed prices.">
<?= U::title('Edit '.$product['name'],'edit') ?>
<input type="hidden" name="product" value="<?= U::e($id) ?>">
<?= U::field('Product name', 'name', $product['name'], 'text', ['icon'=>'bottle','required'=>true,'maxlength'=>80]) ?>
<?= U::field('Price (PHP)', 'price', (string)$product['price'], 'number', ['icon'=>'tag','required'=>true,'min'=>1,'max'=>1000,'step'=>'0.50']) ?>
<?= U::select('Availability','availability',['available'=>'Available','unavailable'=>'Unavailable'],'available') ?>
<?= U::textarea('Description','description',$product['description'],['maxlength'=>250]) ?>
<?= U::btn('Save product','','primary','check',['type'=>'submit','class'=>'w-full']) ?>
</form></div>
