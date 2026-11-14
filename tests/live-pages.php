<?php
// Phase 9: every live customer, station-admin and rider page built in Phases 3-8 renders with empty real data,
// raises no PHP warnings, and shows none of the preview sample names. Run: php tests/live-pages.php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/support/fakes.php';

use App\Controllers\UIController;
use App\Core\Session;
use App\Services\CurrentUser;
use App\Services\Registry;
use App\Services\StationDirectory;
use App\Services\Workspace;

set_error_handler(static function (int $no, string $message, string $file, int $line): bool { throw new ErrorException($message, 0, $no, $file, $line); });
const LIVE_STATION = '11111111-1111-4111-8111-111111111111';
$db = new FakeGateway();
$current = new CurrentUser(new FakeAuth());
Workspace::boot(new Workspace($db, $current));
$ui = new UIController(false, new StationDirectory($db), new Registry($db), $current, fakeStationContext($db, LIVE_STATION));
$empty = static fn (array $data): array => ['ok' => true, 'data' => $data, 'error_code' => null, 'field_errors' => []];
$db->results = [
    'list_customer_orders' => $empty(['orders' => []]), 'list_notifications' => $empty(['notifications' => [], 'unread' => 0]), 'get_customer_order' => ['ok' => false, 'error_code' => 'not_found', 'data' => []],
    'get_station_overview' => $empty(['today' => '2026-11-16', 'counts' => array_fill_keys(['new', 'on_hold', 'scheduled', 'preparing', 'ready', 'out_for_delivery', 'awaiting_completion', 'completed_today', 'cancelled_today', 'orders_today'], 0),
        'cash_collected_today' => 0, 'cash_verified_today' => 0, 'unverified_cash' => 0, 'overdue' => 0, 'open_reviews' => 0, 'riders_free' => 0, 'riders_total' => 0, 'active_trips' => 0,
        'availability' => ['label' => 'Temporarily Closed', 'accepting_asap' => false, 'is_open' => false, 'next_close_at' => null], 'queue' => [], 'ready_delivery' => []]),
    'get_preparation_queue' => $empty(array_fill_keys(['preparing', 'queue', 'ready', 'on_hold', 'scheduled', 'awaiting_completion', 'reviews'], []) + ['settings' => []]),
    'get_dispatch_queue' => $empty(array_fill_keys(['ready_delivery', 'ready_pickup', 'collected', 'delivered', 'riders', 'trips', 'payments', 'reviews'], [])),
    'preview_trip' => $empty(['proposal' => null, 'warnings' => [], 'message' => 'The station is closed, so trips cannot be dispatched now.']),
    'get_station_customers' => $empty(['customers' => []]), 'get_trip' => $empty(['trip' => null]), 'list_rider_history' => $empty(['stops' => []]),
    'list_inbox' => $empty(['conversations' => []]),
    'get_customer_overview' => $empty(['active' => null, 'recent' => [], 'unread_notifications' => 0, 'unread_messages' => 0, 'default_address' => null]),
];
$db->tables['profiles'] = [['id' => 'live-user', 'display_name' => 'Real Person', 'phone' => null, 'order_updates' => true, 'station_updates' => true, 'version' => 1]];
fakeStationContext($db, LIVE_STATION); // the results array above replaced the context fixture
$samples = ['Maria Santos', 'Ana Dela Cruz', 'HMT-104', 'HMT-098', 'Guintas Water Station', 'Juan Reyes', 'Luis Reyes', 'Carlo Diaz', 'Guintas Learning Center', 'sample data', 'Sample data', 'data-chat-seed'];
$render = static function (string $role, string $screen, array $get = []) use ($ui, $samples): void {
    $_SESSION = [];
    Session::login(['id' => 'live-user', 'role' => $role, 'name' => 'Real Person', 'email' => 'real@example.test', 'station' => ['id' => LIVE_STATION, 'name' => 'Real Station', 'slug' => 'real']],
        ['access_token' => 'token-live-user', 'refresh_token' => 'r', 'expires_at' => 2_000_000_000]);
    $_GET = $get;
    $response = $ui->page($role, $screen);
    check($response->status === 200 && !str_contains($response->body, 'Station workspace unavailable'), "$role/$screen renders the live screen");
    foreach ($samples as $name) check(!str_contains($response->body, $name), "$role/$screen shows no sample text \"$name\"");
};
foreach (['overview', 'stations', 'station', 'account', 'addresses', 'checkout', 'orders', 'tracking', 'notifications', 'messages'] as $screen) $render('customer', $screen);
foreach (['overview', 'orders', 'deliveries', 'pickups', 'customers', 'trip', 'messages'] as $screen) $render('station-admin', $screen);
foreach (['sales' => ['completed_orders' => 0, 'completed_total_centavos' => 0, 'delivery_fee_centavos' => 0, 'cash_collected_centavos' => 0, 'cash_verified_centavos' => 0, 'unverified_now_centavos' => 0, 'daily' => []],
    'orders' => ['placed' => 0, 'by_status' => [], 'by_fulfillment' => [], 'by_product' => [], 'by_barangay' => [], 'cancel_reasons' => [], 'daily' => []],
    'deliveries' => ['trips' => 0, 'stops' => 0, 'delivered' => 0, 'failed' => 0, 'planned_km' => 0, 'traveled_km' => 0, 'avg_delivery_minutes' => null, 'failure_reasons' => [], 'by_rider' => [], 'daily' => []]] as $kind => $report) {
    $db->results['get_station_report'] = $empty($report + ['kind' => $kind, 'date_basis' => 'Test basis.']);
    $render('station-admin', 'reports-' . $kind);
}
foreach (['trip', 'history', 'problem', 'account'] as $screen) $render('rider', $screen);
restore_error_handler();
echo $checks . " live page checks passed.\n";
