<?php
use App\UI\UI as U;
require __DIR__ . '/common.php';
if (!is_array($setup) || !is_array($setup['station'] ?? null)) return;
$settingsTabs('/station-admin/settings/order-rules');
$settings = $setup['settings'];
$rules = [
    'max_order_units' => ['Maximum packages per order', 1, 1000],
    'max_distance_km' => ['Maximum delivery distance (km)', 0.001, 100],
    'booking_days' => ['Booking horizon (days)', 1, 7],
    'duplicate_minutes' => ['Duplicate window (minutes)', 1, 1440],
    'overdue_minutes' => ['Overdue threshold (minutes)', 1, 1440],
    'urgency_asap' => ['ASAP urgency points', 0, 1000],
    'urgency_soon' => ['Soon urgency points', 0, 1000],
    'urgency_later' => ['Later urgency points', 0, 1000],
    'urgency_immediate_minutes' => ['Immediate threshold (minutes)', 1, 1440],
    'urgency_soon_minutes' => ['Soon threshold (minutes)', 1, 1440],
    'aging_interval_minutes' => ['Aging interval (minutes)', 1, 1440],
    'aging_points' => ['Points per aging interval', 0, 1000],
    'aging_cap' => ['Maximum aging points', 0, 1000],
    'institution_bonus' => ['Institution bonus points', 0, 1000],
];
?>
<div class="mt-5 space-y-5">
<?php $start('settings/order-rules', 'settings', $settings['version']); ?>
<?= U::title('Order processing rules', 'settings') ?>
<p class="text-sm text-muted">Settings version <?= U::e((string) $settings['version']) ?>. Package counts and rider load units are configured separately.</p>
<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
<?php foreach ($rules as $key => [$label, $minimum, $maximum]): ?>
<?= $field($label, $key, $value('settings', $key, $settings[$key]), 'number', ['required' => true, 'min' => $minimum, 'max' => $maximum, 'step' => $key === 'max_distance_km' ? 'any' : '1']) ?>
<?php endforeach ?></div>
<?= $check('Enable institution bonus', 'institution_bonus_enabled', $bool($value('settings', 'institution_bonus_enabled', $settings['institution_bonus_enabled']))) ?>
<?= $save('Save order rules') ?></form>
<?php
$bands = $value('fees', 'bands', $setup['fee_rules']['bands']);
$bands = is_array($bands) ? $bands : [];
$start('settings/delivery-fees', 'fees', $settings['version'], [], ['data-fee-form' => true]);
?>
<?= U::title('Distance delivery fees', 'truck') ?>
<p class="text-sm text-muted">Fee version <?= U::e((string) $setup['fee_rules']['version']) ?>. Bands must join without gaps or overlaps. Leave the final maximum blank for an open-ended band.</p>
<?= $field('Maximum delivery distance (km)', 'max_distance_km', $value('fees', 'max_distance_km', $setup['fee_rules']['max_distance_km']), 'number', ['required' => true, 'min' => '0.001', 'max' => 100, 'step' => 'any', 'id' => 'fee-max-distance']) ?>
<div data-fee-bands class="space-y-4">
<?php foreach ($bands as $index => $band): ?>
<fieldset data-fee-band class="grid gap-3 rounded-lg border border-line p-4 sm:grid-cols-2 lg:grid-cols-5"><legend class="px-1 text-sm font-bold">Distance band</legend>
<?= $field('Minimum km', "bands[$index][min_km]", $band['min_km'] ?? '', 'number', ['required' => true, 'min' => 0, 'step' => 'any']) ?>
<?= U::select('Include minimum', "bands[$index][min_inclusive]", ['1' => 'Included', '0' => 'Excluded'], $bool($band['min_inclusive'] ?? false) ? '1' : '0') ?>
<?= $field('Maximum km', "bands[$index][max_km]", $band['max_km'] ?? '', 'number', ['min' => 0, 'step' => 'any']) ?>
<?= U::select('Include maximum', "bands[$index][max_inclusive]", ['1' => 'Included', '0' => 'Excluded'], $bool($band['max_inclusive'] ?? false) ? '1' : '0') ?>
<?= $field('Fee (PHP)', "bands[$index][fee]", $band['fee'] ?? $money($band['fee_centavos'] ?? 0), 'number', ['required' => true, 'min' => 0, 'step' => '0.01', 'inputmode' => 'decimal']) ?>
<?= U::btn('Remove band', '', 'ghost', 'minus', ['data-remove-fee-band' => true]) ?>
</fieldset><?php endforeach ?></div>
<div class="flex flex-wrap gap-3"><?= U::btn('Add distance band', '', 'secondary', 'plus', ['data-add-fee-band' => true]) ?><?= $save('Save delivery fees') ?></div>
<p class="text-sm text-muted">Adding or removing bands requires JavaScript. Existing band values can be edited directly.</p>
</form></div>
