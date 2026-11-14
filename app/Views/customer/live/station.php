<?php
use App\Services\Workspace;
use App\UI\OrderStatus as S;
use App\UI\UI as U;

$live = Workspace::get()->load(static fn (Workspace $w): array => $w->station($_GET['station'] ?? null));
if (isset($live['error']) || !empty($live['missing'])): ?>
<?= U::heading('Station not found', $live['error'] ?? 'Choose a station from the current list.') ?><?= U::btn('Find stations', '/customer/stations', 'primary', 'arrow-left') ?>
<?php return; endif;
$st = $live['station']; $av = $live['availability']; $q = $live['quote'];
$accepting = !empty($av['accepting_asap']); $canBook = !empty($av['can_book']);
?>
<div class="mb-5"><?= U::btn('Find stations', '/customer/stations', 'ghost', 'arrow-left') ?></div>
<?= U::heading($st['name'], 'Brgy. ' . ($st['barangay'] ?: 'Hamtic') . ', Hamtic, Antique') ?>
<section class="panel grid items-start gap-6 p-5 md:p-6 xl:grid-cols-[minmax(0,1fr)_320px]"><div>
<div class="flex flex-wrap items-center gap-3"><?= U::badge($av['label'] ?? 'Closed', $accepting ? 'green' : 'amber') ?><?php if (!$accepting && !empty($av['next_open_at'])): ?><span class="text-sm text-muted">Opens <?= U::e(S::time($av['next_open_at'], 'D M j, g:i A')) ?></span><?php endif ?></div>
<?php if (($av['public_notice'] ?? '') !== ''): ?><p class="mt-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-900"><?= U::e($av['public_notice']) ?></p><?php endif ?>
<p class="mt-3"><?= U::e($st['address']) ?></p><?php if ($st['phone']): ?><p class="mt-1 text-sm"><a class="font-semibold text-water" href="tel:<?= U::e($st['phone']) ?>"><?= U::e($st['phone']) ?></a></p><?php endif ?>
<?php if (($st['description'] ?? '') !== ''): ?><p class="mt-3 text-sm text-muted"><?= U::e($st['description']) ?></p><?php endif ?>
<p class="mt-3 text-sm"><span class="text-muted">Delivers to:</span> <?= $live['served'] === [] ? 'All of Hamtic within its delivery distance' : U::e(implode(', ', $live['served'])) ?></p>
<div class="mt-4 flex flex-wrap gap-2"><?= U::btn('Message station', '/customer/messages?station=' . $st['id'], 'secondary', 'message') ?><?= U::btn('Request a reservation', '/customer/messages?station=' . $st['id'], 'ghost', 'calendar') ?></div></div>
<div class="xl:border-l xl:border-line xl:pl-6"><h2 class="mb-3 font-bold">Next 7 days</h2><dl class="space-y-1 text-sm"><?php foreach ($av['preview'] ?? [] as $day): ?><div class="flex justify-between gap-3"><dt class="text-muted"><?= U::e((new DateTimeImmutable($day['date']))->format('D, M j')) ?></dt><dd class="text-right"><?= ($day['windows'] ?? []) === [] ? 'Closed' : U::e(implode(', ', array_map(static fn (array $w): string => date('g:i A', strtotime($w['opens_at'])) . '–' . date('g:i A', strtotime($w['closes_at'])), $day['windows']))) ?></dd></div><?php endforeach ?></dl>
<div class="mt-4 rounded-lg bg-foam p-3 text-sm"><?php if ($live['address'] === null): ?>Add an address to see your delivery fee. <a class="font-semibold text-water" href="/customer/addresses">Add address</a>
<?php elseif ($q === null): ?>Your default address (<?= U::e($live['address']['label']) ?>) has no map pin, so delivery cannot be priced. <a class="font-semibold text-water" href="/customer/addresses">Add a pin</a> or choose pickup.
<?php elseif (!empty($q['eligible'])): ?>Delivery to <strong><?= U::e($live['address']['label']) ?></strong>: about <?= U::e($q['distance_display']) ?> km, fee <strong><?= S::pesos($q['fee_centavos']) ?></strong>.
<?php else: ?><?= U::e($q['message'] ?? 'Delivery is not available to your default address.') ?> Pickup is still available.<?php endif ?></div></div></section>
<?php if (!$accepting && !$canBook): ?><section class="panel mt-5 p-6"><?= U::title('Ordering currently unavailable', 'orders', '') ?><p class="my-4 text-muted">This station is not taking orders right now.</p><?= U::btn('Find another station', '/customer/stations', 'primary') ?></section><?php return; endif; ?>
<form action="/customer/checkout" method="get" class="mt-5 grid gap-5 xl:grid-cols-[minmax(0,1fr)_340px]">
<input type="hidden" name="station" value="<?= U::e($st['id']) ?>">
<section class="panel p-5 md:p-6"><?= U::title('Choose your water', 'bottle', '') ?><p class="mb-5 text-sm text-muted">Prices are set by the station. Refills need your empty container in exchange.</p>
<?php if ($live['products'] === []): ?><p class="rounded-lg bg-foam p-4 text-sm">This station has no products available right now.</p><?php endif ?>
<div class="space-y-3"><?php foreach ($live['products'] as $p): $fid = 'p-' . $p['id']; ?>
<article class="flex flex-wrap items-center gap-4 rounded-xl border border-line p-4"><img src="<?= U::e(S::image($p['image_key'])) ?>" alt="" class="h-20 w-20 rounded-lg bg-foam object-contain" width="80" height="80" loading="lazy"><div class="min-w-0 flex-1"><h3 class="text-lg font-bold"><?= U::e($p['name']) ?></h3><p class="text-sm text-muted"><?= U::e($p['pack_text']) ?><?= ($p['description'] ?? '') !== '' ? ' · ' . U::e($p['description']) : '' ?></p><p class="mt-2 text-lg font-bold"><?= S::pesos($p['price_centavos']) ?></p><?php if ($p['load_units'] === null): ?><p class="text-xs text-amber-700">Pickup only at this station</p><?php endif ?></div>
<div class="flex items-center overflow-hidden rounded-lg border border-line"><?= U::btn('−', '', 'secondary', '', ['data-step' => '-1', 'data-counter' => $fid, 'aria-label' => 'Fewer ' . $p['name']]) ?><input class="w-14 bg-white py-3 text-center" id="<?= U::e($fid) ?>" name="qty[<?= U::e($p['id']) ?>]" type="number" min="0" max="999" value="0" aria-label="<?= U::e($p['name']) ?> quantity"><?= U::btn('+', '', 'secondary', '', ['data-step' => '1', 'data-counter' => $fid, 'aria-label' => 'More ' . $p['name']]) ?></div></article>
<?php endforeach ?></div></section>
<aside class="panel self-start p-5 md:p-6"><?= U::title('Your order', 'orders', '') ?><p class="my-4 text-sm text-muted">Next you choose delivery or pickup, the time and your address. The station system calculates the total before you place the order.</p><?= U::btn('Continue to checkout', '', 'primary', 'arrow-right', ['type' => 'submit', 'class' => 'w-full']) ?></aside></form>
