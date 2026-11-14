<?php
// Original "Find stations" design, listing approved stations with their live status.
use App\Services\Workspace;
use App\UI\UI as U;

$live = Workspace::get()->load(static fn (Workspace $w): array => $w->directory());
$stations = $live['stations'] ?? [];
?>
<?= U::heading('Find stations', 'Fresh refills from local water stations in Hamtic, Antique.') ?>
<section class="panel p-5 md:p-6"><div class="grid gap-4 md:grid-cols-[1fr_220px] mb-6"><?= U::field('Search stations', 'station_search', '', 'search', ['placeholder' => 'Station, barangay or area', 'data-search' => 'station-list']) ?><?= U::select('Availability', 'availability', ['all' => 'All stations', 'open' => 'Open now', 'closed' => 'Currently unavailable'], 'all', ['data-filter-target' => 'station-list']) ?></div><div id="station-list" class="space-y-4"><?php foreach ($stations as $s): ?><article data-search-row data-filter-value="<?= $s['available'] ? 'open' : 'closed' ?>" class="flex flex-wrap items-center gap-5 rounded-xl border border-line p-5"><?= U::productImage('h-28 w-28 rounded-xl bg-foam object-contain') ?><div class="flex-1"><h2 class="text-xl font-bold"><?= U::e($s['name']) ?></h2><p class="muted mt-1">Brgy. <?= U::e($s['area']) ?>, Hamtic, Antique</p><div class="mt-3"><?= U::badge($s['status'], $s['tone']) ?></div><p class="muted mt-3"><?= U::e($s['next']) ?></p></div><?= U::btn('View station', '/customer/station?station=' . $s['id'], $s['available'] ? 'primary' : 'secondary', 'arrow-right') ?></article><?php endforeach; ?></div><p data-empty <?= $stations === [] ? '' : 'hidden' ?> class="p-8 text-center muted"><?= isset($live['error']) ? U::e($live['error']) : ($stations === [] ? 'No station is available right now.' : 'No stations match your search. Try another barangay.') ?></p></section>
