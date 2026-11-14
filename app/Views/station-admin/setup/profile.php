<?php
use App\UI\UI as U;
require __DIR__ . '/common.php';
if (!is_array($setup) || !is_array($setup['station'] ?? null)) return;
$settingsTabs('/station-admin/settings');
$barangays = [];
foreach ($setup['barangays'] as $barangay) $barangays[(string) $barangay['id']] = $barangay['name'];
?>
<div class="mt-5 grid gap-5 xl:grid-cols-2">
<?php $start('settings/profile', 'profile', $station['profile_version']); ?>
<?= U::title('Station profile', 'store') ?>
<?= $field('Station name', 'name', $value('profile', 'name', $station['name']), 'text', ['required' => true, 'maxlength' => 100]) ?>
<?= $field('Contact person', 'contact_name', $value('profile', 'contact_name', $station['contact_name']), 'text', ['required' => true, 'maxlength' => 100]) ?>
<?= $field('Mobile number', 'phone', $value('profile', 'phone', $station['phone']), 'tel', ['required' => true, 'pattern' => '(09[0-9]{9}|\+639[0-9]{9})', 'maxlength' => 13, 'hint' => 'Use 09 followed by 9 digits, or +639 followed by 9 digits.']) ?>
<?= $field('Email address', 'email', $value('profile', 'email', $station['email']), 'email', ['maxlength' => 254]) ?>
<?= U::select('Barangay', 'barangay_id', ['' => 'Choose barangay'] + $barangays, (string) $value('profile', 'barangay_id', $station['barangay_id']), ['required' => true]) ?>
<?= U::textarea('Station address', 'address', (string) $value('profile', 'address', $station['address']), ['required' => true, 'maxlength' => 250]) ?>
<?= U::textarea('About the station', 'description', (string) $value('profile', 'description', $station['description']), ['maxlength' => 400]) ?>
<?= $save('Save profile') ?></form>
<?php $start('settings/location', 'location', $station['location_version'], [], ['data-station-location' => true]); ?>
<?= U::title('Station location', 'pin') ?>
<p class="muted text-sm">Enter coordinates, choose a map pin, or use this device's location. Saving requires a pin within the Hamtic coordinate bounds.</p>
<div data-station-map class="h-80 overflow-hidden rounded-xl border border-line" role="region" aria-label="Station pin map"></div>
<?= U::btn('Use device location', '', 'secondary', 'pin', ['data-device-location' => true]) ?>
<p data-location-status class="text-sm text-muted" role="status" aria-live="polite">Enter latitude and longitude below. Map and device controls require JavaScript.</p>
<div class="grid gap-4 sm:grid-cols-2">
<?= $field('Latitude', 'latitude', $value('location', 'latitude', $station['latitude']), 'number', ['required' => true, 'min' => '10.584854', 'max' => '10.746484', 'step' => 'any', 'inputmode' => 'decimal']) ?>
<?= $field('Longitude', 'longitude', $value('location', 'longitude', $station['longitude']), 'number', ['required' => true, 'min' => '121.961241', 'max' => '122.073730', 'step' => 'any', 'inputmode' => 'decimal']) ?>
</div>
<?= $save('Save location') ?></form></div>
