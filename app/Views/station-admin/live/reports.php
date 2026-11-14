<?php
use App\Controllers\ReportController;
use App\Services\Workspace;
use App\UI\OrderStatus as S;
use App\UI\UI as U;

$kind = ['reports-sales' => 'sales', 'reports-orders' => 'orders', 'reports-deliveries' => 'deliveries'][$data['screen']] ?? 'sales';
[, $start, $end] = ReportController::range($_GET + ['kind' => $kind]);
$r = Workspace::get()->load(static fn (Workspace $w): array => $w->report($kind, $start, $end));
$query = http_build_query(['start' => $start, 'end' => $end]);
$day = static fn (string $d): string => (new DateTimeImmutable($d))->format('M j, Y');
$tabs = ['/station-admin/reports?' . $query => 'Sales', '/station-admin/reports/orders?' . $query => 'Orders', '/station-admin/reports/deliveries?' . $query => 'Deliveries'];
$path = ['sales' => '/station-admin/reports', 'orders' => '/station-admin/reports/orders', 'deliveries' => '/station-admin/reports/deliveries'][$kind];
?>
<?= U::heading('Station reports', 'This station only · ' . $day($start) . ' to ' . $day($end), U::btn('Export CSV', '/station-admin/reports/export?' . http_build_query(['kind' => $kind, 'start' => $start, 'end' => $end]), 'secondary', 'download') . U::btn('Print', '', 'ghost', 'file', ['data-print' => true, 'class' => 'print:hidden'])) ?>
<?= U::tabs($tabs, $path . '?' . $query) ?>
<form method="get" action="<?= U::e($path) ?>" class="mb-5 flex flex-wrap items-end gap-3 print:hidden"><?= U::field('From', 'start', $start, 'date', ['required' => true]) ?><?= U::field('To', 'end', $end, 'date', ['required' => true]) ?><?= U::btn('Show', '', 'secondary', 'calendar', ['type' => 'submit']) ?></form>
<?php if (isset($r['error'])): ?><section class="panel p-6" role="alert"><?= U::e($r['error']) ?></section><?php return; endif; ?>
<p class="mb-4 text-xs text-muted">Date basis: <?= U::e($r['date_basis']) ?> Dates are in Philippine time.</p>
<?php if ($kind === 'sales'): ?>
<div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-3"><?= U::stat('Completed order totals', S::pesos($r['completed_total_centavos']), 'chart', 'blue', $r['completed_orders'] . ' completed orders') ?><?= U::stat('Cash collected', S::pesos($r['cash_collected_centavos']), 'credit', 'green', 'By collection date') ?><?= U::stat('Cash verified', S::pesos($r['cash_verified_centavos']), 'check', 'teal', 'By verification date') ?><?= U::stat('Unverified now', S::pesos($r['unverified_now_centavos']), 'alert', 'amber', 'Waiting for checking') ?><?= U::stat('Delivery fees', S::pesos($r['delivery_fee_centavos']), 'truck', 'violet', 'Included in completed totals') ?></div>
<section class="panel p-4 md:p-5"><?= U::title('Daily', 'chart', 'mb-3') ?><?= U::table(['Date', 'Completed orders', 'Completed totals', 'Cash collected', 'Cash verified'], array_map(static fn (array $d): array => [U::e($day($d['date'])), (int) $d['completed_orders'], S::pesos($d['completed_total_centavos']), S::pesos($d['collected_centavos']), S::pesos($d['verified_centavos'])], $r['daily'])) ?>
<p class="mt-3 text-xs text-muted">Completed totals, collected cash and verified cash are separate measures; do not add them together. Delivery fees are part of the completed totals.</p></section>
<?php elseif ($kind === 'orders'): $st = $r['by_status']; ?>
<div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4"><?= U::stat('Orders placed', (string) $r['placed'], 'orders', 'blue') ?><?= U::stat('Completed', (string) ($st['completed'] ?? 0), 'check', 'green') ?><?= U::stat('Cancelled', (string) ($st['cancelled'] ?? 0), 'close', 'red') ?><?= U::stat('Delivery / pickup', ($r['by_fulfillment']['delivery'] ?? 0) . ' / ' . ($r['by_fulfillment']['pickup'] ?? 0), 'truck', 'violet') ?></div>
<section class="panel mb-5 p-4 md:p-5"><?= U::title('Daily', 'orders', 'mb-3') ?><?= U::table(['Date', 'Placed', 'Completed', 'Cancelled', 'Items (not cancelled)'], array_map(static fn (array $d): array => [U::e($day($d['date'])), (int) $d['placed'], (int) $d['completed'], (int) $d['cancelled'], (int) $d['units']], $r['daily'])) ?></section>
<div class="grid gap-5 lg:grid-cols-3"><section class="panel p-4 md:p-5"><?= U::title('By status', 'chart', 'mb-3') ?><?= U::table(['Status', 'Orders'], array_map(static fn (string $k, int $n): array => [U::e(S::label($k)), $n], array_keys($st), array_values($st))) ?></section>
<section class="panel p-4 md:p-5"><?= U::title('By product', 'bottle', 'mb-3') ?><?= U::table(['Product', 'Qty', 'Amount'], array_map(static fn (array $p): array => [U::e($p['name']), (int) $p['quantity'], S::pesos($p['amount_centavos'])], $r['by_product'])) ?></section>
<section class="panel p-4 md:p-5"><?= U::title('Cancellation reasons', 'close', 'mb-3') ?><?= U::table(['Reason', 'Orders'], array_map(static fn (array $c): array => [U::e($c['reason']), (int) $c['orders']], $r['cancel_reasons'])) ?></section></div>
<section class="panel mt-5 p-4 md:p-5"><?= U::title('By barangay', 'pin', 'mb-3') ?><?= U::table(['Barangay', 'Orders'], array_map(static fn (array $b): array => [U::e($b['barangay']), (int) $b['orders']], $r['by_barangay'])) ?></section>
<?php else: ?>
<div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4"><?= U::stat('Trips', (string) $r['trips'], 'truck', 'blue', (int) $r['stops'] . ' stops') ?><?= U::stat('Delivered', (string) $r['delivered'], 'check', 'green', (int) $r['failed'] . ' failed attempts') ?><?= U::stat('Distance', U::e((string) $r['traveled_km']) . ' km', 'pin', 'violet', 'Planned stop legs ' . $r['planned_km'] . ' km') ?><?= U::stat('Average delivery', $r['avg_delivery_minutes'] === null ? '—' : (int) $r['avg_delivery_minutes'] . ' min', 'clock', 'amber', 'Dispatch to delivered') ?></div>
<section class="panel mb-5 p-4 md:p-5"><?= U::title('By rider', 'users', 'mb-3') ?><?= U::table(['Rider', 'Trips', 'Delivered', 'Failed', 'Items', 'Cash collected'], array_map(static fn (array $x): array => [U::e($x['rider']), (int) $x['trips'], (int) $x['delivered'], (int) $x['failed'], (int) $x['units'], S::pesos($x['cash_centavos'])], $r['by_rider'])) ?></section>
<div class="grid gap-5 lg:grid-cols-2"><section class="panel p-4 md:p-5"><?= U::title('Daily', 'truck', 'mb-3') ?><?= U::table(['Date', 'Trips', 'Delivered', 'Failed'], array_map(static fn (array $d): array => [U::e($day($d['date'])), (int) $d['trips'], (int) $d['delivered'], (int) $d['failed']], $r['daily'])) ?></section>
<section class="panel p-4 md:p-5"><?= U::title('Failed attempts', 'alert', 'mb-3') ?><?= U::table(['Reason', 'Attempts'], array_map(static fn (array $f): array => [U::e($f['reason']), (int) $f['attempts']], $r['failure_reasons'])) ?><p class="mt-3 text-xs text-muted">Distance travelled comes from the rider's location during trips; straight-line stop legs are planning estimates.</p></section></div>
<?php endif ?>
