<?php
declare(strict_types=1);
$base = $argv[1] ?? 'http://127.0.0.1:8000';
$paths = ['/', '/login', '/login/customer', '/login/rider', '/login/station-admin', '/login/super-admin', '/signup', '/forgot-password', '/register-station',
'/customer', '/customer/stations', '/customer/orders', '/customer/addresses', '/customer/notifications', '/customer/account', '/customer/station', '/customer/checkout', '/customer/tracking',
'/rider', '/rider/history', '/rider/account', '/rider/problem',
'/station-admin', '/station-admin/orders', '/station-admin/deliveries', '/station-admin/drivers', '/station-admin/customers', '/station-admin/products', '/station-admin/schedules', '/station-admin/reports', '/station-admin/settings', '/station-admin/trip', '/station-admin/drivers/add', '/station-admin/settings/service-areas', '/station-admin/settings/order-rules', '/station-admin/reports/orders', '/station-admin/reports/deliveries',
'/super-admin', '/super-admin/applications', '/super-admin/stations', '/super-admin/drivers', '/super-admin/activity', '/super-admin/account', '/super-admin/application'];
$paths = [...$paths, '/customer/messages', '/station-admin/messages', '/station-admin/pickups'];
$failures = [];
$links = [];
foreach ($paths as $path) {
    $body = @file_get_contents($base . $path, false, stream_context_create(['http'=>['ignore_errors'=>true]]));
    $status = $http_response_header[0] ?? '';
    if (!str_contains($status, '200') || !is_string($body) || !str_contains($body, 'HydroRoute')) {
        $failures[] = "$path should render HydroRoute, got $status";
        continue;
    }
    if (str_contains($path, '/login/')) {
        $role = substr($path, 7);
        if (!str_contains($body, 'data-login-role="' . $role . '"')) $failures[] = "$path needs its dedicated role form";
    }
    if (str_starts_with($path, '/super-admin') && preg_match('/HMT-\d|PHP\s?[\d,]+|Collected payments|Priority breakdown|Sales report|data-customer-order/i', $body)) {
        $failures[] = "$path contains station operations or finance data";
    }
    preg_match_all('/(?:href|src)="(\/[^"?#]*)(?:[^"]*)"/', $body, $matches);
    foreach ($matches[1] as $link) $links[$link] = true;
}
foreach (array_keys($links) as $path) {
    @file_get_contents($base . $path, false, stream_context_create(['http'=>['ignore_errors'=>true]]));
    if (!str_contains($http_response_header[0] ?? '', '200')) $failures[] = "Broken local link: $path";
}
foreach (['/.env','/config/supabase.php','/bootstrap.php','/unknown-page','/assets/images/../config/app.php','/super-admin/messages','/rider/messages','/supabase/migrations/20261102144011_access_fulfillment_messaging.sql'] as $path) {
    @file_get_contents($base . $path, false, stream_context_create(['http'=>['ignore_errors'=>true]]));
    if (!str_contains($http_response_header[0] ?? '', '404')) $failures[] = "Private or missing path should be 404: $path";
}
foreach (['/assets/css/app.css'=>'text/css','/assets/js/app.js'=>'javascript','/assets/images/hero-water.png'=>'image/png','/assets/images/water-jug.png'=>'image/png'] as $path=>$type) {
    @file_get_contents($base . $path, false, stream_context_create(['http'=>['ignore_errors'=>true]]));
    if (!str_contains(implode(' ', $http_response_header ?? []), $type)) $failures[] = "Wrong asset type: $path";
}
if ($failures) { fwrite(STDERR, implode("\n", $failures) . "\n"); exit(1); }
echo count($paths) . " pages passed; " . count($links) . " local links/assets passed; registry privacy and asset boundary checks passed.\n";

