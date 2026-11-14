<?php
use App\Services\Workspace;
use App\UI\LiveForm as F;
use App\UI\OrderStatus as S;
use App\UI\UI as U;

$search = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 80) : '';
$live = Workspace::get()->load(static fn (Workspace $w): array => $w->customers($search));
?>
<?= U::heading('Customers', 'People who ordered from this station. Totals count completed orders at this station only.', U::btn('Export CSV', '', 'secondary', 'download', ['data-export' => 'station-customers'])) ?>
<?php if (isset($live['error'])): ?><section class="panel p-6" role="alert"><?= U::e($live['error']) ?></section><?php return; endif;
$rows = $live['customers']; $institutions = count(array_filter($rows, static fn (array $c): bool => $c['classification'] === 'institution')); ?>
<div class="mb-5 grid grid-cols-2 gap-3 xl:grid-cols-4"><?= U::stat('Customers', (string) count($rows), 'users', 'blue') ?><?= U::stat('Households', (string) (count($rows) - $institutions), 'home', 'green') ?><?= U::stat('Institutions', (string) $institutions, 'store', 'violet') ?><?= U::stat('Orders', (string) array_sum(array_column($rows, 'orders')), 'orders', 'amber') ?></div>
<section class="panel p-4 md:p-5"><form method="get" action="/station-admin/customers" class="mb-4 flex max-w-lg items-end gap-2"><div class="flex-1"><?= U::field('Search customers', 'q', $search, 'search', ['icon' => 'search', 'placeholder' => 'Name or phone']) ?></div><?= U::btn('Search', '', 'secondary', 'search', ['type' => 'submit']) ?></form>
<?php if ($rows === []): ?><p class="p-6 text-center text-muted"><?= $search === '' ? 'No customer has ordered from this station yet.' : 'No customer matches your search.' ?></p><?php else: ?>
<?= U::table(['Customer', 'Barangay', 'Phone', 'Orders', 'Last order', 'Spent here', 'Type'], array_map(static fn (array $c): array => [
    '<strong>' . U::e($c['name'] ?? 'Customer') . '</strong>', U::e($c['barangay'] ?? ''), U::e($c['phone'] ?? ''), (int) $c['orders'] . ' <span class="text-xs text-muted">(' . (int) $c['completed'] . ' completed)</span>',
    U::e(S::time($c['last_order_at'], 'M j, Y')), S::pesos($c['total_spent_centavos']),
    F::open('/station-admin/customers/classify', ['customer_id' => $c['customer_id'], 'expected_version' => (string) ($c['version'] ?? '')], ['class' => 'flex items-center gap-1']) . '<label class="sr-only" for="class-' . U::e($c['customer_id']) . '">Customer type</label><select class="field py-1 text-sm" id="class-' . U::e($c['customer_id']) . '" name="classification"><option value="household"' . ($c['classification'] === 'household' ? ' selected' : '') . '>Household</option><option value="institution"' . ($c['classification'] === 'institution' ? ' selected' : '') . '>Institution</option></select>' . U::btn('Save', '', 'ghost', '', ['type' => 'submit', 'class' => 'btn-sm']) . '</form>',
], $rows), ['id' => 'station-customers']) ?>
<p class="mt-3 text-xs text-muted">Customer type is this station's own classification. The institution bonus applies only when it is turned on in Order rules.</p><?php endif ?></section>
