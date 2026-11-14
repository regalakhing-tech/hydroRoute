<?php
// Original station page design with the station's real products, hours and the fee for the customer's default address.
use App\Services\Workspace;
use App\UI\OrderStatus as S;
use App\UI\UI as U;

$live = Workspace::get()->load(static fn (Workspace $w): array => $w->station($_GET['station'] ?? null));
if (isset($live['error']) || !empty($live['missing'])): ?>
<?= U::heading('Station not found', 'Choose a station from the current list.') ?>
<?= U::btn('Find stations', '/customer/stations', 'primary', 'arrow-left') ?>
<?php return; endif;
$station = $live['station']; $av = $live['availability']; $q = $live['quote']; $address = $live['address'];
$open = !empty($av['accepting_asap']); $canBook = !empty($av['can_book']);
$area = $station['barangay'] ?: 'Hamtic';
$hours = [];
foreach ($av['preview'] ?? [] as $day) {
    $hours[(new DateTimeImmutable($day['date']))->format('l')] = ($day['windows'] ?? []) === [] ? 'Closed'
        : implode(', ', array_map(static fn (array $w): string => date('g:i A', strtotime($w['opens_at'])) . ' – ' . date('g:i A', strtotime($w['closes_at'])), $day['windows']));
}
$status = $open ? 'Open now' . (!empty($av['next_close_at']) ? ' until ' . S::time($av['next_close_at'], 'g:i A') : '') : ($av['label'] ?? 'Closed');
?>
<div class="mb-5"><?= U::btn('Find stations', '/customer/stations', 'ghost', 'arrow-left') ?></div>
<?= U::heading($station['name'], 'Local water refills in ' . $area . ', Hamtic, Antique.') ?>
<section class="panel p-5 md:p-6 grid gap-6 xl:grid-cols-[180px_minmax(0,1fr)_280px] items-center"><?= U::productImage('h-44 w-44 rounded-xl bg-foam object-contain') ?><div><p class="font-semibold text-lg">Brgy. <?= U::e($area) ?>, Hamtic, Antique</p><p class="muted mt-1"><?= U::e($station['address']) ?></p><div class="my-4"><?= U::badge($status, $open ? 'green' : 'amber') ?></div><?php if (($av['public_notice'] ?? '') !== ''): ?><p class="mb-4 text-sm text-amber-800"><?= U::e($av['public_notice']) ?></p><?php endif ?><?= U::btn('Message station', '/customer/messages?station=' . $station['id'], 'secondary', 'message') ?></div><div class="xl:border-l border-line xl:pl-6"><h2 class="font-bold mb-3">Business hours</h2><dl class="space-y-1 text-sm"><?php foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $day): ?><div class="flex justify-between gap-3"><dt class="muted"><?= $day ?></dt><dd><?= U::e($hours[$day] ?? 'Closed') ?></dd></div><?php endforeach; ?></dl></div></section>
<?php if (!$open && !$canBook): ?><section class="panel p-6 mt-5"><?= U::title('Ordering currently unavailable', 'orders', '') ?><p class="muted my-4"><?= !empty($av['next_open_at']) ? 'Next opening ' . U::e(S::time($av['next_open_at'], 'F j, Y \a\t g:i A')) . '.' : 'This station is not taking orders right now.' ?></p><?= U::btn('Find an available station', '/customer/stations', 'primary') ?></section><?php return; endif; ?>
<form action="/customer/checkout" method="get" class="mt-5 grid gap-5 xl:grid-cols-[minmax(0,1fr)_340px]" data-order-builder>
<input type="hidden" name="station" value="<?= U::e($station['id']) ?>">
<section class="panel p-5 md:p-6"><?= U::title('Choose your water', 'file', '') ?><p class="muted mb-6">Select products and quantities for delivery.</p><div class="space-y-4">
<?php if ($live['products'] === []): ?><p class="muted">This station has no products available right now.</p><?php endif ?>
<?php foreach ($live['products'] as $p): $fid = 'p-' . $p['id']; ?>
<article class="flex flex-wrap items-center gap-5 border border-line rounded-xl p-4">
<img src="<?= U::e(S::image($p['image_key'])) ?>" alt="<?= U::e($p['name']) ?>" class="object-contain h-28 w-28 rounded-lg bg-foam" width="256" height="384" loading="lazy">
<div class="flex-1"><h3 class="font-bold text-lg"><?= U::e($p['name']) ?></h3><p class="muted"><?= U::e(($p['description'] ?? '') !== '' ? $p['description'] : $p['pack_text']) ?></p><p class="text-xl font-bold mt-4"><?= S::pesos($p['price_centavos']) ?></p><p class="muted">Per <?= U::e($p['pack_text'] !== '' ? $p['pack_text'] : 'item') ?><?= $p['load_units'] === null ? ' · pickup only' : '' ?></p></div>
<div class="flex items-center rounded-lg border border-line overflow-hidden">
<?= U::btn('−', '', 'secondary', '', ['data-step' => -1, 'data-counter' => $fid, 'aria-label' => 'Remove ' . $p['name']]) ?>
<input class="w-14 text-center py-3 bg-white" id="<?= U::e($fid) ?>" name="<?= U::e($p['id']) ?>" type="number" min="0" max="999" value="0" data-unit-price="<?= ((int) $p['price_centavos']) / 100 ?>" aria-label="<?= U::e($p['name']) ?> quantity">
<?= U::btn('+', '', 'secondary', '', ['data-step' => 1, 'data-counter' => $fid, 'aria-label' => 'Add ' . $p['name']]) ?>
</div></article><?php endforeach; ?></div></section>
<aside class="panel p-5 md:p-6 self-start"><?= U::title('Your order', 'orders', '') ?><p class="muted my-5">Refills use your returnable containers. Bottled water and new jugs need no empty container exchange.</p><div class="border-y border-line py-4 flex justify-between text-lg"><span>Total amount</span><strong data-order-total><?= U::money(0) ?></strong></div><div class="space-y-3 my-5"><p class="rounded-lg bg-foam p-3">Cash on delivery</p>
<a href="/customer/addresses" class="block rounded-lg border border-line p-3"><?= $address ? U::e($address['label']) . ' &middot; ' . U::e($address['address']) : 'Add a delivery address' ?></a>
<p class="muted"><?php if ($q !== null && !empty($q['eligible'])): ?>Delivery fee to <?= U::e($address['label']) ?>: <?= S::pesos($q['fee_centavos']) ?> (about <?= U::e($q['distance_display']) ?> km), added at checkout.<?php elseif ($q !== null): ?><?= U::e($q['message'] ?? 'Delivery is not available to this address.') ?> Pickup is still available.<?php elseif ($address !== null): ?>Add a map pin to your address to see the delivery fee. Pickup is always available.<?php else: ?>Delivery fee (PHP 5–15 by distance) is added at checkout.<?php endif ?> Delivery time is confirmed by the station.</p></div><?= U::btn('Continue to order', '', 'primary', 'arrow-right', ['type' => 'submit', 'class' => 'w-full']) ?></aside></form>
