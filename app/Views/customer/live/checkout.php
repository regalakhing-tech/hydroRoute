<?php
// Original checkout design, now placing real orders: prices, fee and total come from the database quote.
use App\Controllers\LiveController;
use App\Core\Session;
use App\Services\Workspace;
use App\UI\OrderStatus as S;
use App\UI\UI as U;

$live = Workspace::get()->load(static fn (Workspace $w): array => $w->checkout($_GET));
if (isset($live['error']) || !empty($live['missing'])): ?>
<?= U::heading('Station not found', $live['error'] ?? 'Choose a station from the current list.') ?>
<?= U::btn('Find stations', '/customer/stations', 'primary', 'arrow-left') ?>
<?php return; endif;
$station = $live['station']; $avail = $live['availability']; $form = $live['form'];
$q = ($live['quote']['ok'] ?? false) === true ? $live['quote']['data'] : null;
$quoteError = $live['quote'] !== null && $q === null ? (current(array_filter($live['quote']['field_errors'] ?? [], 'is_string')) ?: 'Check your order details.') : '';
$errors = is_array($_SESSION['form_errors'] ?? null) ? $_SESSION['form_errors'] : []; unset($_SESSION['form_errors']);
if (empty($avail['accepting_asap']) && empty($avail['can_book'])): ?><?= U::heading('Ordering currently unavailable', ($avail['public_notice'] ?? '') ?: 'This station is not taking orders right now.') ?><?= U::btn('Back to station', '/customer/station?station=' . $station['id'], 'primary') ?><?php return; endif;
if ($live['items'] === []): ?>
<?= U::heading('Your basket is empty', 'Choose at least one product to continue.') ?>
<?= U::btn('Choose products', '/customer/station?station=' . $station['id'], 'primary') ?>
<?php return; endif;
$mode = $form['fulfillment'];
$schedule = empty($avail['accepting_asap']) ? 'scheduled' : $form['schedule'];
$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
[$date, $time] = $form['requested_at'] !== '' ? explode('T', $form['requested_at']) : [$now->format('Y-m-d'), ''];
// 5-minute slots per Manila date; one customer per slot. fulfillment.js rebuilds the list when the date or mode changes.
$slotDays = [];
foreach ($avail['preview'] ?? [] as $day) foreach ($day['windows'] ?? [] as $w) $slotDays[$day['date']][] = [substr($w['opens_at'], 0, 5), substr($w['closes_at'], 0, 5), substr($w['order_cutoff'] ?? $w['closes_at'], 0, 5), substr($w['delivery_cutoff'] ?? $w['closes_at'], 0, 5)];
$booked = $live['booked'] ?? ['slots' => [], 'refills_left' => []];
$taken = array_flip($booked['slots']);
$slots = [];
foreach ($slotDays[$date] ?? [] as [$open, $close, $orderCut, $deliveryCut]) {
    for ($t = (int) ceil(strtotime("$date $open") / 300) * 300; $t < strtotime("$date $close") && date('H:i', $t) <= ($mode === 'delivery' ? $deliveryCut : $orderCut); $t += 300) {
        if ("$date " . date('H:i', $t) <= $now->format('Y-m-d H:i')) continue;
        $slots[date('H:i', $t)] = isset($taken[$date . 'T' . date('H:i', $t)]);
    }
}
ksort($slots);
$refillsLeft = $booked['refills_left'][$date] ?? null;
$addressOptions = [];
foreach ($live['addresses'] as $a) $addressOptions[$a['id']] = $a['label'] . ' — ' . $a['address'] . ($a['latitude'] === null ? ' (no map pin)' : '');
if ($addressOptions === []) $addressOptions = ['' => 'No saved address yet'];
$fee = $mode === 'pickup' ? 0 : (isset($q['fee_centavos']) ? $q['fee_centavos'] / 100 : ($live['fees'][$form['address_id']] ?? 0));
// When the quote is refused (shown below), the summary still lists the basket at catalogue prices.
$lines = $q['items'] ?? array_map(static fn (string $id, int $n): array => $live['products'][$id] + ['quantity' => $n, 'unit_price_centavos' => $live['products'][$id]['price_centavos'],
    'line_total_centavos' => $n * $live['products'][$id]['price_centavos']], array_keys($live['items']), $live['items']);
$subtotal = array_sum(array_column($lines, 'line_total_centavos')) / 100;
$returns = $q['expected_empties'] ?? array_sum(array_map(static fn (array $l): int => empty($l['returnable']) ? 0 : $l['quantity'], $lines));
$err = static fn (string $f): string => isset($errors[$f]) ? '<p class="mt-2 text-sm font-semibold text-rose-700" role="alert">' . U::e($errors[$f]) . '</p>' : '';
?>
<?= U::heading('Place your order', 'Order your water refills from ' . $station['name'] . '.') ?>
<form method="post" action="/customer/orders" data-fulfillment-root data-initial-mode="<?= $mode ?>" data-persist="live-checkout-<?= U::e($station['id']) ?>" data-live-quote="/customer/checkout/quote" data-submit-once class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_340px]">
<input type="hidden" name="_token" value="<?= U::e(Session::csrfToken()) ?>"><input type="hidden" name="request_key" value="<?= U::e(LiveController::key()) ?>"><input type="hidden" name="station" value="<?= U::e($station['id']) ?>">
<?php foreach ($live['items'] as $id => $n): ?><input type="hidden" name="<?= U::e($id) ?>" value="<?= (int) $n ?>"><?php endforeach ?>
<div class="space-y-5"><section class="panel p-5 flex items-center gap-5"><?= U::productImage('h-24 w-24 rounded-lg bg-foam') ?><div><h2 class="text-xl font-bold"><?= U::e($station['name']) ?></h2><p class="muted">Brgy. <?= U::e($station['barangay'] ?: 'Hamtic') ?>, Hamtic, Antique</p><div class="mt-3"><?= U::badge($avail['label'] ?? 'Closed', !empty($avail['accepting_asap']) ? 'green' : 'amber') ?></div></div></section>
<section class="panel p-5 md:p-6 space-y-7">
<fieldset><legend class="section-title mb-3">1. How would you like your water?</legend><div class="grid gap-3 sm:grid-cols-2"><?php foreach (['delivery' => ['Delivery', 'truck', 'Bring water to your saved address.'], 'pickup' => ['Pickup', 'store', 'Collect your water at the station.']] as $value => $option): ?><label class="relative flex gap-3 rounded-xl border border-line p-4 cursor-pointer has-[:checked]:border-water has-[:checked]:bg-foam"><input type="radio" name="fulfillment" value="<?= $value ?>" <?= $mode === $value ? 'checked' : '' ?> required class="accent-cyan-600 mt-1"><?= U::icon($option[1], 'size-6 text-water') ?><span><strong><?= $option[0] ?></strong><span class="block muted text-sm"><?= $option[2] ?></span></span></label><?php endforeach; ?></div></fieldset>
<div data-delivery-address><?= U::title('Delivery address', 'truck', '') ?><p class="muted mb-3">Choose where you want your order delivered.</p><?= U::select('Saved address', 'address', $addressOptions, $form['address_id'], ['required' => true, 'data-fees' => json_encode($live['fees'])]) ?><?= $err('address_id') ?><a href="/customer/addresses" class="inline-block mt-2 text-water font-semibold">Manage addresses</a></div>
<section data-pickup-location hidden class="rounded-xl bg-foam p-4"><?= U::title('Pickup location', 'file', '') ?><p class="font-semibold mt-2"><?= U::e($station['name']) ?></p><p><?= U::e($station['address']) ?></p><p class="muted mt-2">Bring your empty containers. Wait for the station to confirm your order is ready before collecting.</p></section>
<fieldset><legend class="section-title" data-option-title>2. Delivery option</legend><p class="muted mb-3"><span data-timing-question>When do you want your order delivered?</span></p><?= $err('schedule') ?>
<label class="flex gap-3 mb-3"><input type="radio" name="delivery" value="asap" <?= $schedule === 'asap' ? 'checked' : '' ?> <?= empty($avail['accepting_asap']) ? 'disabled' : '' ?> class="accent-cyan-600"><span><strong data-asap-title>Wait for your turn</strong><span class="block muted"><span data-asap-copy><?= empty($avail['accepting_asap']) ? 'Unavailable while the station is closed.' : 'No need to pick a time. The station delivers when your turn in line comes.' ?></span></span></span></label>
<label class="flex gap-3"><input type="radio" name="delivery" value="scheduled" <?= $schedule === 'scheduled' ? 'checked' : '' ?> class="accent-cyan-600"><span><strong data-schedule-title>Schedule delivery for later</strong><span class="block muted">Today or up to 7 days ahead, within the station's hours.</span></span></label>
<div class="grid sm:grid-cols-2 gap-3 mt-3"><?= U::field('Preferred date', 'date', $date, 'date', ['min' => $now->format('Y-m-d'), 'max' => $slotDays !== [] ? max(array_keys($slotDays)) : $now->modify('+6 days')->format('Y-m-d')]) ?><div class="min-w-0"><label class="label" for="time">Preferred time</label><select id="time" name="time" class="field" data-slot-select data-slot-days="<?= U::e(json_encode($slotDays)) ?>" data-booked="<?= U::e(json_encode($booked['slots'])) ?>" data-refills="<?= U::e(json_encode((object) $booked['refills_left'])) ?>"><option value="">Choose a time</option>
<?php $hour = null; foreach ($slots as $value => $isTaken): if (substr($value, 0, 2) !== $hour): ?><?= $hour !== null ? '</optgroup>' : '' ?><optgroup label="<?= U::e(date('g A', strtotime("$date $value"))) ?>"><?php $hour = substr($value, 0, 2); endif ?>
<option value="<?= U::e($value) ?>"<?= $value === $time && !$isTaken ? ' selected' : '' ?><?= $isTaken ? ' disabled' : '' ?>><?= U::e(date('g:i A', strtotime("$date $value")) . ($isTaken ? ' — taken' : '')) ?></option>
<?php endforeach ?><?= $hour !== null ? '</optgroup>' : '' ?></select></div></div>
<p class="hint" data-slot-hint>Each 5-minute time is for one customer only. Times marked “taken” are already booked.<span data-refills-left><?= $refillsLeft !== null ? ' ' . U::e((string) $refillsLeft) . ' refill' . ((int) $refillsLeft === 1 ? '' : 's') . ' left on this date.' : '' ?></span></p><?= $err('requested_at') ?></fieldset>
<div><?= U::title('3. Empty containers to return', 'bottle', 'mb-3') ?><p class="rounded-lg bg-foam p-3 text-sm">Please prepare <strong><?= (int) $returns ?></strong> empty container<?= $returns === 1 ? '' : 's' ?> for exchange (one for each refill in your order).</p></div>
<div><h2 class="section-title mb-3"><span data-notes-title>4. Delivery notes (optional)</span></h2><?= U::textarea('Instructions for your delivery team', 'notes', $form['note'], ['maxlength' => '200', 'placeholder' => 'Look for the blue gate. Please call upon arrival.']) ?><?= $err('note') ?></div>
<fieldset><legend class="section-title mb-3">5. Payment method</legend><label class="flex items-center gap-3 rounded-lg border border-line bg-foam p-4"><input type="radio" name="payment" value="cash" checked required class="accent-cyan-600"><span><strong><span data-payment-title>Cash on delivery</span></strong><span class="block muted"><span data-payment-copy>Pay when your order is delivered.</span></span></span></label><?= $err('phone') ?></fieldset>
</section></div>
<aside class="panel p-5 md:p-6 self-start"><?= U::title('Order summary', 'chart', '') ?><p class="muted">Please review your order details.</p><div class="my-5 flex gap-3 items-center"><?= U::productImage('h-20 w-20 rounded-lg bg-foam') ?><strong><?= U::e($station['name']) ?></strong></div>
<div class="space-y-4 border-y border-line py-5"><?php foreach ($lines as $item): ?>
<div class="flex gap-3 items-center"><img src="<?= U::e(S::image($item['image_key'] ?? null)) ?>" alt="" class="h-16 w-16 rounded-lg bg-foam object-contain" width="64" height="64" loading="lazy"><div class="flex-1"><h3 class="font-semibold"><?= U::e($item['name']) ?></h3><p class="muted"><?= (int) $item['quantity'] ?> &times; <?= S::pesos($item['unit_price_centavos']) ?></p></div><strong><?= S::pesos($item['line_total_centavos']) ?></strong></div>
<?php endforeach; ?><div class="flex justify-between gap-3"><span class="muted">Delivery fee</span><strong data-delivery-fee data-quote="fee"><?= $fee ? U::money($fee) : ($mode === 'pickup' ? 'Free (pickup)' : '—') ?></strong></div><p class="text-xs text-muted">One fee per delivery, from the station's distance table<?= isset($q['delivery']['distance_display']) ? ' (about ' . U::e($q['delivery']['distance_display']) . ' km)' : '' ?>.</p></div>
<div class="flex justify-between rounded-lg bg-foam p-4 mt-4 font-bold text-lg"><span>Total amount</span><span data-checkout-total data-quote="total" data-subtotal="<?= $subtotal ?>"><?= U::money($subtotal + $fee) ?></span></div>
<p data-quote-error class="mt-3 text-sm font-semibold text-rose-700 <?= $quoteError === '' ? 'hidden' : '' ?>" role="alert"><?= U::e($quoteError) ?></p>
<p class="my-5 rounded-lg bg-amber-50 p-4 text-sm text-amber-800"><span data-review-copy>Check your address before saving.</span> The station confirms the final amount; the delivery fee never goes up at your door.</p>
<?php $rules = $booked['rules'] ?? []; $cancelMinutes = (int) ($rules['cancel_minutes'] ?? 15); $tripSize = (int) ($rules['trip_containers'] ?? 0); $dailyRefills = $rules['daily_refills'] ?? null; ?>
<section class="mb-5 rounded-lg border border-line bg-mist p-4 text-sm" aria-labelledby="order-rules-title"><h3 id="order-rules-title" class="flex items-center gap-2 font-bold"><?= U::icon('file', 'size-4 text-water') ?>Good to know</h3>
<ul class="mt-2 space-y-1.5 text-muted">
<li class="flex gap-2"><span class="text-water">•</span><span>You can cancel within <strong class="text-ink"><?= $cancelMinutes ?> minute<?= $cancelMinutes === 1 ? '' : 's' ?></strong> after ordering, and only before the station starts preparing. After that, message the station.</span></li>
<li class="flex gap-2"><span class="text-water">•</span><span>Each pick-a-time slot is for one customer. Times marked “taken” are already booked.</span></li>
<?php if ($tripSize > 0): ?><li class="flex gap-2"><span class="text-water">•</span><span>One delivery trip carries up to <strong class="text-ink"><?= $tripSize ?> containers</strong> to one barangay. For more, place two orders or choose pickup.</span></li><?php endif ?>
<?php if ($dailyRefills !== null): ?><li class="flex gap-2"><span class="text-water">•</span><span>The station takes up to <strong class="text-ink"><?= (int) $dailyRefills ?> refills a day</strong>, first come, first served.</span></li><?php endif ?>
<li class="flex gap-2"><span class="text-water">•</span><span>Pay in cash when you receive or collect your water.</span></li>
</ul></section>
<?= U::btn('Place order', '', 'primary', '', ['type' => 'submit', 'class' => 'w-full']) ?><div class="mt-3"><?= U::btn('Back to station', '/customer/station?station=' . $station['id'], 'secondary', '', ['class' => 'w-full']) ?></div></aside></form>
