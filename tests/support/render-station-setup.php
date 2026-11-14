<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$page = $input['page'] ?? '';
if (!in_array($page, ['settings-profile', 'settings-service-areas', 'settings-order-rules', 'products', 'schedules'], true)) {
    throw new RuntimeException('Unknown fixture page');
}
$_SESSION = ['csrf' => 'fixture-csrf-token'];
$_GET = $input['query'] ?? [];
$data = $input['data'];
if ($data['preview'] ?? false) $data['sample'] = App\UI\StationData::all();
$info = ['caption' => 'Preview station'];
require dirname(__DIR__, 2) . '/app/Views/station-admin/' . $page . '.php';
