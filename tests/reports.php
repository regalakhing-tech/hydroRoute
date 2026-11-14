<?php
// Phase 8 PHP tests: live report pages and CSV export with a fake Supabase.
// Run: php tests/reports.php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/support/fakes.php';

use App\Controllers\ReportController;
use App\Controllers\UIController;
use App\Core\Session;
use App\Services\CurrentUser;
use App\Services\StationDirectory;
use App\Services\Workspace;

const SID = '11111111-1111-4111-8111-111111111111';
$db = new FakeGateway();
$current = new CurrentUser(new FakeAuth());
Workspace::boot(new Workspace($db, $current));
$_SESSION = [];
Session::login(['id' => 'u-1', 'role' => 'station-admin', 'name' => 'Owner Live', 'email' => 'o@example.test', 'station' => ['id' => SID, 'name' => 'Live Station', 'slug' => 'live']],
    ['access_token' => 'token-u-1', 'refresh_token' => 'r', 'expires_at' => 2_000_000_000]);
$last = static function (string $fn) use ($db): ?array { $c = $db->called('rpc', $fn); return $c === [] ? null : end($c); };

[$kind, $start, $end] = ReportController::range(['kind' => 'payroll', 'start' => '2026-02-31', 'end' => 'x']);
$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
check($kind === 'sales' && $end === $today && $start === (new DateTimeImmutable($today))->modify('-6 days')->format('Y-m-d'), 'Bad input falls back to sales for the last 7 days');

$db->results['get_station_report'] = ['ok' => true, 'data' => ['kind' => 'sales', 'date_basis' => 'Order totals by completion date; cash by collection date; verified cash by verification date.',
    'completed_orders' => 2, 'completed_total_centavos' => 12000, 'delivery_fee_centavos' => 1000, 'cash_collected_centavos' => 14000, 'cash_verified_centavos' => 12000, 'unverified_now_centavos' => 2000,
    'daily' => [['date' => '2026-11-16', 'completed_orders' => 2, 'completed_total_centavos' => 12000, 'collected_centavos' => 14000, 'verified_centavos' => 12000]]]];
$ui = new UIController(false, new StationDirectory($db), new App\Services\Registry($db), $current, fakeStationContext($db, SID));
$_GET = ['start' => '2026-11-10', 'end' => '2026-11-16'];
$page = $ui->page('station-admin', 'reports-sales')->body;
check(str_contains($page, 'PHP 120.00') && str_contains($page, 'PHP 140.00') && !str_contains($page, 'PHP 6,900'), 'Sales report is live with separate measures');
check($last('get_station_report')[2] === ['p_station_id' => SID, 'p_report_kind' => 'sales', 'p_start_date' => '2026-11-10', 'p_end_date' => '2026-11-16', 'p_filters' => (object) []] || $last('get_station_report')[2]['p_start_date'] === '2026-11-10', 'Report uses the session station and the chosen range');
check(str_contains($page, 'href="/station-admin/reports/export?kind=sales&amp;start=2026-11-10&amp;end=2026-11-16"') && str_contains($page, 'data-print'), 'CSV export and print offered');

$db->results['get_station_report'] = ['ok' => true, 'data' => ['kind' => 'orders', 'date_basis' => 'Orders by the date they were placed.', 'placed' => 3, 'by_status' => ['completed' => 1, 'cancelled' => 1], 'by_fulfillment' => ['delivery' => 2, 'pickup' => 1],
    'by_product' => [['name' => '=HYPERLINK("http://x")', 'quantity' => 2, 'amount_centavos' => 4000]], 'by_barangay' => [], 'cancel_reasons' => [['reason' => 'Changed <plans>', 'orders' => 1]],
    'daily' => [['date' => '2026-11-16', 'placed' => 3, 'completed' => 1, 'cancelled' => 1, 'units' => 4]]]];
$page = $ui->page('station-admin', 'reports-orders')->body;
check(str_contains($page, 'Changed &lt;plans&gt;') && !str_contains($page, 'Changed <plans>') && !str_contains($page, 'Outside operating hours'), 'Orders report is live and escaped');
$_GET = ['kind' => 'orders', 'start' => '2026-11-16', 'end' => '2026-11-16'];
$csv = (new ReportController($db, $current))->export();
check($csv->headers['Content-Type'] === 'text/csv; charset=utf-8' && str_contains($csv->headers['Content-Disposition'], 'hydroroute-orders-2026-11-16-to-2026-11-16.csv'), 'CSV download headers');
check(str_contains($csv->body, "\"'=HYPERLINK(\"\"http://x\"\")\"") && str_contains($csv->body, '40.00'), 'CSV protects formula cells and shows pesos');

$db->results['get_station_report'] = ['ok' => true, 'data' => ['kind' => 'deliveries', 'date_basis' => 'Trips by dispatch date.', 'trips' => 1, 'stops' => 2, 'delivered' => 1, 'failed' => 1, 'planned_km' => 3.1, 'traveled_km' => 4.2, 'avg_delivery_minutes' => 18,
    'failure_reasons' => [['reason' => 'Customer unavailable', 'attempts' => 1]], 'by_rider' => [['rider' => 'Rider One', 'trips' => 1, 'delivered' => 1, 'failed' => 1, 'units' => 3, 'cash_centavos' => 7000]],
    'daily' => [['date' => '2026-11-16', 'trips' => 1, 'delivered' => 1, 'failed' => 1]]]];
$_GET = [];
$page = $ui->page('station-admin', 'reports-deliveries')->body;
check(str_contains($page, 'Rider One') && str_contains($page, '18 min') && !str_contains($page, 'Carlo Diaz'), 'Delivery report is live');
$db->errors['get_station_report'] = new RuntimeException('down');
check(str_contains($ui->page('station-admin', 'reports-sales')->body, 'could not be loaded'), 'Report failure shows an error state');
$_SESSION = [];
check((new ReportController($db, $current))->export()->headers['Location'] === '/login/station-admin', 'Export needs a station admin login');

echo $checks . " report checks passed.\n";
