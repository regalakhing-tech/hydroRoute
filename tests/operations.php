<?php
// Phases 5-6 PHP tests: station operations, dispatch and rider controllers, and their live pages, with a fake Supabase.
// Run: php tests/operations.php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/support/fakes.php';

use App\Controllers\RiderController;
use App\Controllers\StationOpsController;
use App\Controllers\UIController;
use App\Core\Session;
use App\Services\CurrentUser;
use App\Services\StationDirectory;
use App\Services\Workspace;

const ST = '11111111-1111-4111-8111-111111111111';
const OR1 = '44444444-4444-4444-8444-444444444444';
const OR2 = '44444444-4444-4444-8444-444444444445';
const KEY = '55555555-5555-4555-8555-555555555555';
const RID = '66666666-6666-4666-8666-666666666666';
const TRIP = '77777777-7777-4777-8777-777777777777';
const STOP = '88888888-8888-4888-8888-888888888888';
const PAY = '99999999-9999-4999-8999-999999999999';

$db = new FakeGateway();
$current = new CurrentUser(new FakeAuth());
Workspace::boot(new Workspace($db, $current));
$ops = new StationOpsController($db, $current);
$rider = new RiderController($db, $current);
$login = static function (string $role): void {
    $_SESSION = [];
    Session::login(['id' => 'u-1', 'role' => $role, 'name' => 'Owner Live', 'email' => 'owner@example.test', 'station' => ['id' => ST, 'name' => 'Live Station', 'slug' => 'live']],
        ['access_token' => 'token-u-1', 'refresh_token' => 'refresh-u-1', 'expires_at' => 2_000_000_000]);
};
$flash = static fn (): array => Session::takeFlash() ?? ['type' => '', 'message' => ''];
$last = static function (string $fn) use ($db): ?array { $c = $db->called('rpc', $fn); return $c === [] ? null : end($c); };
$post = static fn (array $f): array => $_POST = $f + ['_token' => Session::csrfToken(), 'request_key' => KEY];

// --- Owner actions ------------------------------------------------------------------------------
$login('customer');
$post(['order_id' => OR1, 'expected_version' => '2']);
check($ops->start()->headers['Location'] === '/login/station-admin', 'Customers cannot run station steps');
$login('station-admin');
$db->results['start_preparation'] = ['ok' => true, 'data' => ['status' => 'preparing', 'version' => 3]];
$post(['order_id' => OR1, 'expected_version' => '2', 'back' => '/station-admin/pickups']);
$r = $ops->start();
check($r->headers['Location'] === '/station-admin/pickups' && $last('start_preparation')[2] === ['p_order_id' => OR1, 'p_expected_version' => 2, 'p_request_key' => KEY], 'Start preparation sends id, version and key');
$post(['order_id' => OR1, 'expected_version' => '2', 'back' => 'https://evil.example/']);
check($ops->start()->headers['Location'] === '/station-admin/orders', 'Return path is limited to station pages');
$db->results['cancel_order'] = ['ok' => false, 'error_code' => 'validation', 'field_errors' => ['reason' => 'Give a reason for cancelling.'], 'data' => []];
$post(['order_id' => OR1, 'expected_version' => '2', 'reason' => '']);
$ops->cancel();
check($flash()['message'] === 'Give a reason for cancelling.', 'Owner cancel needs a reason');
$db->results['record_pickup_collection'] = ['ok' => true, 'data' => ['status' => 'collected']];
$post(['order_id' => OR2, 'expected_version' => '3', 'tendered' => '1,000.50', 'empties' => '2']);
$r = $ops->collectPickup();
check($r->headers['Location'] === '/station-admin/pickups' && $last('record_pickup_collection')[2]['p_payload'] === ['tendered_centavos' => '100050', 'empties_returned' => '2'], 'Pesos become centavos for the pickup collection');
$post(['order_id' => OR2, 'expected_version' => '3', 'tendered' => '12.345', 'empties' => '2']);
$ops->collectPickup();
check($flash()['message'] === 'Check the form and try again.', 'Bad money input rejected before the database');
$db->results['dispatch_trip'] = ['ok' => true, 'data' => ['trip_id' => TRIP, 'trip_no' => 4, 'stops' => 2]];
$post(['rider_id' => RID, 'order_ids' => [OR1, OR2, OR1], 'front_order_id' => OR2, 'front_reason' => 'Waited two hours']);
$r = $ops->dispatch();
$call = $last('dispatch_trip')[2];
check($r->headers['Location'] === '/station-admin/trip?trip=' . TRIP && $call['p_station_id'] === ST && $call['p_order_ids'] === [OR1, OR2], 'Dispatch uses the session station and unique order ids');
check($call['p_proposal'] === ['front_order_id' => OR2, 'front_reason' => 'Waited two hours'], 'Overdue front stop and reason passed on');
$post(['rider_id' => RID, 'order_ids' => ['not-a-uuid']]);
$ops->dispatch();
check($flash()['message'] === 'Check the form and try again.', 'Malformed order id rejected');
$db->results['verify_payment'] = ['ok' => true, 'data' => ['state' => 'verified']];
$post(['payment_id' => PAY, 'expected_version' => '1', 'note' => 'Counted']);
$ops->verifyPayment();
check($last('verify_payment')[2]['p_payload'] === ['note' => 'Counted'], 'Cash verification');
$db->results['set_customer_classification'] = ['ok' => true, 'data' => ['classification' => 'institution']];
$post(['customer_id' => OR1, 'classification' => 'institution', 'expected_version' => '']);
$ops->classify();
check($last('set_customer_classification')[2]['p_expected_version'] === null && str_contains($flash()['message'], 'institution'), 'First classification has no version');
$db->results['save_rider'] = ['ok' => true, 'data' => ['version' => 2]];
$post(['rider_id' => RID, 'expected_version' => '1', 'vehicle' => 'Tricycle', 'plate_number' => 'ANT 1', 'capacity' => '15']);
$ops->saveRider();
check($last('save_rider')[2]['p_payload'] === ['vehicle' => 'Tricycle', 'plate_number' => 'ANT 1', 'capacity' => '15', 'enabled' => 'false'], 'Unchecked box disables the rider');

$queue = ['preparing' => [], 'queue' => [['id' => OR1, 'version' => 2, 'order_no' => 'HMT-0001', 'status' => 'confirmed', 'fulfillment' => 'delivery', 'customer_name' => 'Lia Live', 'barangay' => 'Guintas',
    'total_units' => 3, 'load_units' => 3, 'total_centavos' => 7000, 'schedule_mode' => 'asap', 'requested_at' => null, 'overdue' => true, 'due' => true, 'redispatch_paused' => false, 'score' => ['u' => 40, 'a' => 10, 'c' => 0, 'p' => 50]]],
    'ready' => [], 'on_hold' => [], 'scheduled' => [], 'awaiting_completion' => [], 'reviews' => [['id' => KEY, 'order_id' => OR1, 'order_no' => 'HMT-0001', 'kind' => 'overdue', 'detail' => 'Waiting since 8:00 AM', 'version' => 1]]];
$db->results['get_preparation_queue'] = ['ok' => true, 'data' => $queue];
$_GET = [];
$body = json_decode($ops->updates()->body, true);
check($body['data']['version'] === Workspace::fingerprint($queue), 'Queue poll fingerprint matches the page');

// --- Rider actions ------------------------------------------------------------------------------
$login('rider');
$db->results['mark_arrived'] = ['ok' => true, 'data' => ['fee_decision' => 'lowered', 'amount_due_centavos' => 6500]];
$post(['stop_id' => STOP, 'latitude' => '10.6134900', 'longitude' => '122.0', 'accuracy' => '12', 'fallback_reason' => 'No GPS signal']);
$rider->arrived();
$call = $last('mark_arrived')[2];
check($call['p_latitude'] === 10.61349 && $call['p_fallback_reason'] === null && str_contains($flash()['message'], 'PHP 65.00'), 'Arrival with GPS sends the position, not a fallback');
$post(['stop_id' => STOP, 'latitude' => '', 'longitude' => '', 'accuracy' => '', 'fallback_reason' => 'No GPS signal']);
$rider->arrived();
check($last('mark_arrived')[2]['p_latitude'] === null && $last('mark_arrived')[2]['p_fallback_reason'] === 'No GPS signal', 'Arrival without GPS sends the reason');
$post(['stop_id' => STOP, 'latitude' => '95', 'longitude' => '122', 'accuracy' => '5']);
$rider->arrived();
check($flash()['message'] === 'Check the form and try again.', 'Impossible coordinates rejected');
$db->results['record_delivery'] = ['ok' => true, 'data' => ['result' => 'delivered', 'change_centavos' => 3500]];
$post(['stop_id' => STOP, 'expected_version' => '2', 'result' => 'delivered', 'tendered' => '100', 'empties' => '3']);
$rider->deliver();
check($last('record_delivery')[2]['p_payload'] === ['stop_id' => STOP, 'version' => 2, 'result' => 'delivered', 'tendered_centavos' => '10000', 'empties_returned' => '3'] && str_contains($flash()['message'], 'PHP 35.00'), 'Delivered result with cash and empties');
$db->results['record_delivery'] = ['ok' => true, 'data' => ['result' => 'failed']];
$post(['stop_id' => STOP, 'expected_version' => '2', 'result' => 'failed', 'failure_reason' => 'Customer unavailable', 'failure_detail' => 'Gate locked']);
$rider->deliver();
check($last('record_delivery')[2]['p_payload']['failure_reason'] === 'Customer unavailable Gate locked', 'Failed result carries the reason');
$db->results['record_rider_location'] = ['ok' => true, 'data' => []];
$post(['trip_id' => TRIP, 'latitude' => '10.61', 'longitude' => '122.01', 'accuracy' => '8']);
check(json_decode($rider->location()->body, true)['ok'] === true && $last('record_rider_location')[2]['p_trip_id'] === TRIP, 'Location ping saved');
$_POST['_token'] = 'forged';
check($rider->location()->status === 401, 'Location ping needs the CSRF token');
$login('station-admin');
$post(['trip_id' => TRIP, 'latitude' => '10.61', 'longitude' => '122.01', 'accuracy' => '8']);
check($rider->location()->status === 401, 'Only riders send locations');

// --- Live pages ---------------------------------------------------------------------------------
$ui = new UIController(false, new StationDirectory($db), new App\Services\Registry($db), $current);
$db->results['get_station_overview'] = ['ok' => true, 'data' => ['today' => '2026-11-16', 'counts' => ['new' => 1, 'on_hold' => 0, 'scheduled' => 2, 'preparing' => 0, 'ready' => 1, 'out_for_delivery' => 0, 'awaiting_completion' => 0, 'completed_today' => 5, 'cancelled_today' => 1, 'orders_today' => 9],
    'cash_collected_today' => 34500, 'cash_verified_today' => 30000, 'unverified_cash' => 4500, 'overdue' => 1, 'open_reviews' => 1, 'riders_free' => 2, 'riders_total' => 3, 'active_trips' => 1,
    'availability' => ['label' => 'Open now', 'accepting_asap' => true, 'is_open' => true, 'next_close_at' => '2026-11-16T10:00:00+00:00'], 'queue' => $queue['queue'], 'ready_delivery' => []]];
$db->results['list_notifications'] = ['ok' => true, 'data' => ['unread' => 0, 'notifications' => []]];
$page = $ui->page('station-admin', 'overview')->body;
check(str_contains($page, 'PHP 345.00') && str_contains($page, 'PHP 45.00') && str_contains($page, 'HMT-0001') && !str_contains($page, 'Maria Santos') && !str_contains($page, 'HMT-104'), 'Overview shows live numbers only');
$db->results['get_station_order'] = ['ok' => true, 'data' => ['order' => ['id' => OR1, 'order_no' => 'HMT-0001', 'contact_name' => 'Lia Live', 'contact_phone' => '+639171111111', 'customer_id' => KEY,
    'status' => 'delivered', 'fulfillment' => 'delivery', 'version' => 7, 'address_label' => 'Home', 'address_text' => 'Purok 1', 'address_landmark' => '', 'subtotal_centavos' => 6000, 'quoted_fee_centavos' => 1000,
    'final_fee_centavos' => null, 'total_centavos' => 7000, 'distance_display' => 1.5, 'note' => '', 'redispatch_paused' => false],
    'score' => ['u' => 40, 'a' => 25, 'c' => 0, 'p' => 65], 'items' => [], 'timeline' => [], 'stops' => [], 'reviews' => [['id' => KEY, 'kind' => 'fee_mismatch', 'state' => 'open', 'detail' => 'Measured 5 km', 'version' => 1]],
    'payment' => ['id' => PAY, 'state' => 'unverified', 'applied_centavos' => 7000, 'tendered_centavos' => 10000, 'change_centavos' => 3000, 'empties_returned' => 3, 'expected_empties' => 3, 'version' => 1], 'classification' => 'household', 'driver' => null]];
$_GET = ['order' => OR1];
$page = $ui->page('station-admin', 'orders')->body;
check(str_contains($page, '#1 (estimate)') && str_contains($page, 'Overdue') && str_contains($page, 'action="/station-admin/orders/start"') && str_contains($page, 'data-poll-version="' . Workspace::fingerprint($queue) . '"'), 'Queue shows rank, overdue and actions');
check(str_contains($page, 'action="/station-admin/payments/verify"') && str_contains($page, 'action="/station-admin/orders/fee-review"') && str_contains($page, 'action="/station-admin/orders/complete"'), 'Order panel offers cash verification, fee review and completion');
$db->results['get_dispatch_queue'] = ['ok' => true, 'data' => ['ready_delivery' => [], 'ready_pickup' => [], 'collected' => [], 'delivered' => [], 'reviews' => [],
    'riders' => [['id' => RID, 'name' => 'Rider One', 'vehicle' => 'Tricycle', 'plate_number' => 'ANT 1', 'capacity' => 15, 'enabled' => true, 'free' => true, 'version' => 1, 'trips_today' => 0]],
    'trips' => [], 'payments' => []]];
$db->results['preview_trip'] = ['ok' => true, 'data' => ['proposal' => ['rider_id' => RID, 'rider_name' => 'Rider One', 'capacity' => 15, 'trips_today' => 0, 'load' => 14, 'barangay' => 'Guintas', 'planned_km' => 2.5,
    'stops' => [['order_id' => OR1, 'sequence' => 1, 'leg_km' => 1.5, 'station_km' => 1.5, 'score' => 60, 'order_no' => 'HMT-0001', 'customer_name' => 'Lia Live', 'barangay' => 'Guintas', 'load' => 10, 'overdue' => true]]],
    'other_riders' => [], 'eligible' => [['order_id' => OR1]], 'warnings' => [['order_id' => OR2, 'order_no' => 'HMT-0002', 'load' => 20, 'message' => 'No free vehicle can carry this whole order.']]]];
$_GET = [];
$page = $ui->page('station-admin', 'deliveries')->body;
check(str_contains($page, 'action="/station-admin/dispatch"') && str_contains($page, 'name="order_ids[]" value="' . OR1 . '"') && str_contains($page, 'HMT-0002') && str_contains($page, 'Move one overdue stop'), 'Dispatch proposal with SSTF stops, capacity warning and overdue option');
$page = $ui->page('station-admin', 'pickups')->body;
check(str_contains($page, 'No pickup is ready') && !str_contains($page, 'Emilia Ramos'), 'Pickup page is live');
$db->results['get_station_customers'] = ['ok' => true, 'data' => ['customers' => [['customer_id' => KEY, 'name' => 'Lia <Live>', 'phone' => '+639171111111', 'barangay' => 'Guintas', 'orders' => 4, 'completed' => 3, 'last_order_at' => '2026-11-16T01:00:00+00:00', 'total_spent_centavos' => 21000, 'classification' => 'institution', 'version' => 2]]]];
$page = $ui->page('station-admin', 'customers')->body;
check(str_contains($page, 'Lia &lt;Live&gt;') && str_contains($page, 'PHP 210.00') && str_contains($page, 'value="institution" selected') && !str_contains($page, 'Guintas Learning Center'), 'Customers list is live and escaped');
$stopRow = ['id' => STOP, 'sequence' => 1, 'state' => 'pending', 'version' => 1, 'leg_km' => 1.5, 'station_km' => 1.5, 'manual_front_reason' => null, 'attempt' => null,
    'order' => ['id' => OR1, 'order_no' => 'HMT-0001', 'customer_name' => 'Lia Live', 'contact_phone' => '+639171111111', 'address_text' => 'Purok 1', 'landmark' => 'Blue gate', 'barangay' => 'Guintas',
        'latitude' => 10.6135, 'longitude' => 122.0, 'total_units' => 3, 'expected_empties' => 3, 'note' => '', 'amount_due_centavos' => 7000, 'items' => [['name' => 'Round refill', 'quantity' => 3]]]];
$tripData = ['trip' => ['id' => TRIP, 'trip_no' => 4, 'status' => 'active', 'version' => 1, 'load_units' => 10, 'capacity' => 15, 'planned_km' => 1.5, 'traveled_km' => null, 'dispatched_at' => '2026-11-16T01:00:00+00:00', 'rider_name' => 'Rider One'],
    'station' => ['name' => 'Live Station'], 'stops' => [$stopRow], 'last_location' => null];
$db->results['get_trip'] = ['ok' => true, 'data' => $tripData];
$_GET = ['trip' => TRIP];
$page = $ui->page('station-admin', 'trip')->body;
check(str_contains($page, 'Trip 4') && str_contains($page, 'action="/station-admin/trips/close"') && !str_contains($page, 'Juan Reyes'), 'Owner trip page is live');
$login('rider');
$_GET = [];
$page = $ui->page('rider', 'trip')->body;
check(str_contains($page, 'data-rider-track="' . TRIP . '"') && str_contains($page, 'action="/rider/trips/arrived"') && str_contains($page, 'PHP 70.00') && str_contains($page, 'Blue gate'), 'Rider sees the next stop and the arrival form');
$stopRow['state'] = 'arrived'; $stopRow['attempt'] = ['fee_decision' => 'lowered', 'amount_due_centavos' => 6500, 'state' => 'open'];
$tripData['stops'] = [$stopRow];
$db->results['get_trip'] = ['ok' => true, 'data' => $tripData];
$page = $ui->page('rider', 'trip')->body;
check(str_contains($page, 'action="/rider/trips/result"') && str_contains($page, 'PHP 65.00') && str_contains($page, 'value="failed"'), 'After arrival: delivered and failed forms with the lowered amount');
$db->results['get_trip'] = ['ok' => true, 'data' => ['trip' => null]];
check(str_contains($ui->page('rider', 'trip')->body, 'No trip is assigned'), 'Rider without a trip');
$db->results['list_rider_history'] = ['ok' => true, 'data' => ['stops' => [['order_no' => 'HMT-0001', 'customer_name' => 'Lia Live', 'barangay' => 'Guintas', 'state' => 'failed', 'trip_no' => 4, 'station_name' => 'Live Station', 'total_units' => 3, 'empties_returned' => null, 'failure_reason' => 'Customer unavailable', 'resolved_at' => '2026-11-16T01:00:00+00:00']]]];
$page = $ui->page('rider', 'history')->body;
check(str_contains($page, 'Customer unavailable') && !str_contains($page, 'HMT-099'), 'Rider history is live');

echo $checks . " operations checks passed.\n";
