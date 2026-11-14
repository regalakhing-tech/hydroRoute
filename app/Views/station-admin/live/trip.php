<?php
// Original trip design with the real trip, its nearest-first stops and results.
use App\Services\Workspace;
use App\UI\LiveForm as F;
use App\UI\OrderStatus as S;
use App\UI\UI as U;

$id = is_string($_GET['trip'] ?? null) ? $_GET['trip'] : '';
$live = Workspace::get()->load(static fn (Workspace $w): array => $id === '' ? ['error_code' => 'not_found'] : $w->trip($id));
if (isset($live['error']) || isset($live['error_code']) || empty($live['trip'])): ?><?= U::heading('Trip not found', 'Choose a trip from the Deliveries page.') ?><?= U::btn('Back to deliveries','/station-admin/deliveries','secondary','arrow-left') ?><?php return; endif;
$t = $live['trip']; $stops = $live['stops'];
$delivered = array_values(array_filter($stops, fn($s) => $s['state'] === 'delivered'));
$resolved = array_values(array_filter($stops, fn($s) => in_array($s['state'], ['delivered','failed'], true)));
$load = array_sum(array_map(fn($s) => (int) $s['order']['total_units'], $stops));
$cash = array_sum(array_map(fn($s) => (int) ($s['attempt']['amount_due_centavos'] ?? 0), $delivered));
$name = (string) ($t['rider_name'] ?? 'Rider'); $words = preg_split('/\s+/', trim($name)) ?: ['R'];
$initials = mb_strtoupper(mb_substr($words[0], 0, 1) . mb_substr($words[1] ?? '', 0, 1));
$tone = ['delivered' => 'green', 'failed' => 'red', 'arrived' => 'amber', 'pending' => 'blue'];
$rows = []; foreach ($stops as $s) $rows[] = [(int) $s['sequence'], U::e($s['order']['order_no']), U::e($s['order']['customer_name'] ?? ''), (int) $s['order']['total_units'], U::badge(ucfirst($s['state']), $tone[$s['state']] ?? 'blue')];
?>
<?php if ($t['status'] === 'active'): ?><div data-poll-url="/station-admin/trips/status?trip=<?= U::e($t['id']) ?>" data-poll-version="<?= (int) $t['version'] ?>:<?= count($resolved) ?>" data-poll-seconds="30" hidden></div><?php endif ?>
<?= U::heading('Trip '.(int) $t['trip_no'],$info['caption'].' · '.S::time($t['dispatched_at'], 'F j, Y'),U::btn('Back to deliveries','/station-admin/deliveries','secondary','arrow-left')) ?>
<div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_340px]"><div class="space-y-5"><section class="panel p-5"><div class="mb-5 flex justify-between gap-3"><?= U::title('Delivery stops','truck','') ?><?= U::badge($t['status']==='closed'?'Completed':'In progress',$t['status']==='closed'?'green':'blue') ?></div><?= U::table(['Stop','Order','Customer','Containers','Status'],$rows) ?></section><section class="panel p-5"><?= U::title('Trip timeline','truck','') ?><ol class="mt-5 space-y-5 border-l-2 border-line pl-5"><li><p class="font-bold"><?= U::e(S::time($t['dispatched_at'], 'g:i A')) ?> · Dispatched</p><p class="muted"><?= $load ?> containers loaded at the station.</p></li>
<?php foreach ($resolved as $s): ?><li><p class="font-bold"><?= U::e(S::time($s['attempt']['resolved_at'] ?? null, 'g:i A')) ?> · Stop <?= (int) $s['sequence'] ?> <?= $s['state'] === 'delivered' ? 'delivered' : 'failed' ?></p><p class="muted"><?= $s['state'] === 'delivered' ? (int) $s['order']['total_units'] . ' containers delivered. ' . S::pesos($s['attempt']['amount_due_centavos']) . ' collected.' : U::e($s['attempt']['failure_reason'] ?? 'Not delivered.') ?></p></li><?php endforeach ?>
<li><p class="font-bold"><?= $t['status'] === 'closed' ? U::e(S::time($t['closed_at'], 'g:i A')) . ' · Trip completed' : (count($resolved) === count($stops) ? 'Returning to station' : 'En route to the next stop') ?></p><p class="muted"><?= $t['status'] === 'closed' ? 'Driver returned to station.' : (count($resolved) === count($stops) ? 'All delivery stops completed.' : 'Driver is delivering now.') ?></p></li></ol></section></div>
<aside class="panel h-fit p-5"><?= U::title('Trip summary','truck','') ?><div class="my-5 flex items-center gap-3"><?= U::avatar($initials) ?><div><strong><?= U::e($name) ?></strong><p class="muted">Assigned driver</p></div></div><dl class="space-y-4"><div class="flex justify-between"><dt>Barangay</dt><dd><?= U::e($stops[0]['order']['barangay'] ?? '—') ?></dd></div><div class="flex justify-between"><dt>Load</dt><dd><?= (int) $t['load_units'] ?> / <?= (int) $t['capacity'] ?> containers</dd></div><div class="flex justify-between"><dt>Stops completed</dt><dd><?= count($resolved) ?> / <?= count($stops) ?></dd></div><div class="flex justify-between"><dt>Cash collected</dt><dd><?= S::pesos($cash) ?></dd></div></dl><div class="mt-5 border-t border-line pt-5"><p class="muted mb-3">Cash reconciliation is reviewed after the rider returns. Verify each order's cash on the Order queue.</p><?= U::btn('Review delivery report','/station-admin/reports/deliveries','secondary','chart') ?>
<?php if ($t['status'] === 'active'): ?><div class="mt-4"><?= F::reason('/station-admin/trips/close', ['trip_id' => $t['id'], 'expected_version' => $t['version']], 'Close trip (reason needed if stops are open)', 'Rider returned', 'Close trip', 'secondary', 'Close this trip and free the rider?') ?></div><?php endif ?></div></aside></div>
