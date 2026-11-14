<?php
use App\Services\Workspace;
use App\UI\UI as U;

$live = Workspace::get()->load(static fn (Workspace $w): array => $w->directory());
?>
<?= U::heading('Find stations', 'Approved water stations in Hamtic, Antique, with their live opening status.') ?>
<section class="panel p-5 md:p-6">
<?php if (isset($live['error'])): ?><p role="alert"><?= U::e($live['error']) ?></p>
<?php elseif ($live['stations'] === []): ?><p class="p-6 text-center text-muted">No station is available right now. Please check again later.</p>
<?php else: ?>
<div class="mb-6 grid gap-4 md:grid-cols-[1fr_220px]"><?= U::field('Search stations', 'station_search', '', 'search', ['placeholder' => 'Station or barangay', 'data-search' => 'station-list', 'icon' => 'search']) ?><?= U::select('Availability', 'availability', ['all' => 'All stations', 'open' => 'Taking orders now', 'closed' => 'Closed now'], 'all', ['data-filter-target' => 'station-list']) ?></div>
<div id="station-list" class="space-y-4"><?php foreach ($live['stations'] as $s): ?>
<article data-search-row data-filter-value="<?= $s['available'] ? 'open' : 'closed' ?>" class="flex flex-wrap items-center gap-5 rounded-xl border border-line p-5"><?= U::productImage('h-24 w-24 rounded-xl bg-foam object-contain') ?><div class="min-w-0 flex-1"><h2 class="text-xl font-bold"><?= U::e($s['name']) ?></h2><p class="mt-1 text-muted">Brgy. <?= U::e($s['area']) ?>, Hamtic, Antique</p><div class="mt-3"><?= U::badge($s['status'], $s['tone']) ?></div><p class="mt-2 text-sm text-muted"><?= U::e($s['next']) ?><?= !$s['available'] && $s['can_book'] ? ' You can still schedule an order.' : '' ?></p></div><?= U::btn('View station', '/customer/station?station=' . $s['id'], $s['available'] ? 'primary' : 'secondary', 'arrow-right') ?></article>
<?php endforeach ?></div><p data-empty hidden class="p-8 text-center muted">No stations match your search.</p>
<?php endif ?></section>
