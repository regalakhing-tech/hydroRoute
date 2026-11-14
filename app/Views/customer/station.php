<?php use App\UI\UI as U;
$stationId = !isset($_GET['station']) ? 'guintas' : (is_string($_GET['station']) ? $_GET['station'] : '');
$station = null;
foreach ($data['sample']['stations'] as $candidate) { if ($candidate['id'] === $stationId) { $station = $candidate; break; } }
if (!$station): ?>
<?= U::heading('Station not found', 'Choose a station from the current list.') ?>
<?= U::btn('Find stations', '/customer/stations', 'primary', 'arrow-left') ?>
<?php return; endif; ?>

<div class="mb-5"><?= U::btn('Find stations', '/customer/stations', 'ghost', 'arrow-left') ?></div>
<?= U::heading($station['name'], 'Local water refills in '.$station['area'].', Hamtic, Antique.') ?>
<section class="panel p-5 md:p-6 grid gap-6 xl:grid-cols-[180px_minmax(0,1fr)_280px] items-center"><?= U::productImage('h-44 w-44 rounded-xl bg-foam object-contain') ?><div><p class="font-semibold text-lg">Brgy. <?= U::e($station['area']) ?>, Hamtic, Antique</p><p class="muted mt-1">National Road, Brgy. <?= U::e($station['area']) ?></p><div class="my-4"><?= U::badge($station['status'], $station['tone']) ?></div><?= U::btn('Message station', '/customer/messages?station='.$station['id'], 'secondary', 'message') ?></div><div class="xl:border-l border-line xl:pl-6"><h2 class="font-bold mb-3">Business hours</h2><dl class="space-y-1 text-sm"><?php foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $day): ?><div class="flex justify-between gap-3"><dt class="muted"><?= $day ?></dt><dd><?= U::e($day === 'Sunday' ? $station['sunday'] : $station['hours']) ?></dd></div><?php endforeach; ?></dl></div></section>
<?php if (!$station['available']): ?><section class="panel p-6 mt-5"><?= U::title('Ordering currently unavailable','orders','') ?><p class="muted my-4"><?= U::e($station['next']) ?></p><?= U::btn('Find an available station', '/customer/stations', 'primary') ?></section><?php return; endif; ?>
<?php $quote = \App\UI\Products::quote([]); ?>
<form action="/customer/checkout" method="get" class="mt-5 grid gap-5 xl:grid-cols-[minmax(0,1fr)_340px]" data-order-builder>
<input type="hidden" name="station" value="<?= U::e($station['id']) ?>">
<section class="panel p-5 md:p-6"><?= U::title('Choose your water','file','') ?><p class="muted mb-6">Select products and quantities for delivery.</p><div class="space-y-4">
<?php foreach ($quote['items'] as $id => $product): ?>
<article class="flex flex-wrap items-center gap-5 border border-line rounded-xl p-4">
<?= U::productImage('h-28 w-28 rounded-lg bg-foam', $id) ?>
<div class="flex-1"><h3 class="font-bold text-lg"><?= U::e($product['name']) ?></h3><p class="muted"><?= U::e($product['description']) ?></p><p class="text-xl font-bold mt-4"><?= U::money($product['price']) ?></p><p class="muted">Per <?= $product['per'] ?></p></div>
<div class="flex items-center rounded-lg border border-line overflow-hidden">
<?= U::btn('−', '', 'secondary', '', ['data-step'=>-1,'data-counter'=>$id.'-quantity','aria-label'=>'Remove '.$product['name']]) ?>
<input class="w-14 text-center py-3 bg-white" id="<?= $id ?>-quantity" name="<?= $id ?>" type="number" min="0" max="20" value="<?= $product['quantity'] ?>" data-unit-price="<?= $product['price'] ?>" aria-label="<?= U::e($product['name']) ?> quantity">
<?= U::btn('+', '', 'secondary', '', ['data-step'=>1,'data-counter'=>$id.'-quantity','aria-label'=>'Add '.$product['name']]) ?>
</div></article><?php endforeach; ?></div></section>
<aside class="panel p-5 md:p-6 self-start"><?= U::title('Your order','orders','') ?><p class="muted my-5">Refills use your returnable containers. Bottled water and new jugs need no empty container exchange.</p><div class="border-y border-line py-4 flex justify-between text-lg"><span>Total amount</span><strong data-order-total><?= U::money($quote['total']) ?></strong></div><div class="space-y-3 my-5"><p class="rounded-lg bg-foam p-3">Cash on delivery</p><a href="/customer/addresses" class="block rounded-lg border border-line p-3">Home &middot; Guintas, Hamtic</a><p class="muted">Delivery fee (PHP 5–15 by distance) is added at checkout. Delivery time is confirmed by the station.</p></div><?= U::btn('Continue to order', '', 'primary', 'arrow-right', ['type'=>'submit','class'=>'w-full']) ?></aside></form>
