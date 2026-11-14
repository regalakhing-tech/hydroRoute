<?php
use App\UI\UI as U;
require __DIR__ . '/common.php';
if (!is_array($setup) || !is_array($setup['station'] ?? null)) return;
$settingsTabs('/station-admin/settings/service-areas');
$selected = [];
foreach ($setup['service_areas'] as $area) if ($bool($area['served'])) $selected[] = (string) $area['barangay_id'];
$submitted = $value('serviceAreas', 'barangay_ids', $selected);
$selected = is_array($submitted) ? array_map('strval', $submitted) : [];
$start('settings/service-areas', 'serviceAreas', $setup['settings']['version'], [], ['class' => 'panel mt-5 space-y-5 p-5']);
?>
<?= U::title('Service areas', 'pin') ?>
<p class="muted">Choose the Hamtic barangays your station serves. Delivery fees are managed in order rules.</p>
<?php if ($setup['barangays'] === []): ?>
<p role="status">No barangay choices are available. Reload this page before changing coverage.</p>
<?php else: ?>
<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
<?php foreach ($setup['barangays'] as $barangay): ?>
<?= $check((string) $barangay['name'], 'barangay_ids[]', in_array((string) $barangay['id'], $selected, true), ['value' => (string) $barangay['id']]) ?>
<?php endforeach ?></div>
<p class="text-sm text-muted">Clearing every checkbox removes all served barangays.</p>
<?= $save('Save service areas') ?>
<?php endif ?></form>
