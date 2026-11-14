<?php
// Phase 4 PHP tests: customer order controller and live customer pages with a fake Supabase.
// Run: php tests/orders.php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/support/fakes.php';

use App\Controllers\OrderController;
use App\Controllers\UIController;
use App\Core\Session;
use App\Services\CurrentUser;
use App\Services\StationDirectory;
use App\Services\SupabaseException;
use App\Services\Workspace;

const S = '11111111-1111-4111-8111-111111111111';
const P = '22222222-2222-4222-8222-222222222222';
const A = '33333333-3333-4333-8333-333333333333';
const O = '44444444-4444-4444-8444-444444444444';
const K = '55555555-5555-4555-8555-555555555555';

$db = new FakeGateway();
$current = new CurrentUser(new FakeAuth());
Workspace::boot(new Workspace($db, $current));
$orders = new OrderController($db, $current);
$login = static function (string $role = 'customer'): void {
    $_SESSION = [];
    Session::login(['id' => 'u-1', 'role' => $role, 'name' => 'Lia Live', 'email' => 'lia@example.test', 'station' => $role === 'station-admin' ? ['id' => S, 'name' => 'Live Station', 'slug' => 'live'] : null],
        ['access_token' => 'token-u-1', 'refresh_token' => 'refresh-u-1', 'expires_at' => 2_000_000_000]);
};
$flash = static fn (): array => Session::takeFlash() ?? ['type' => '', 'message' => ''];
$form = static fn (array $over = []): array => $over + ['_token' => Session::csrfToken(), 'station' => S, 'qty' => [P => '2'], 'fulfillment' => 'delivery',
    'address_id' => A, 'schedule' => 'asap', 'note' => 'Blue gate', 'request_key' => K];
$last = static fn (string $fn): ?array => ($db->called('rpc', $fn) ?: [null])[count($db->called('rpc', $fn) ?: [null]) - 1];

// --- Submit -------------------------------------------------------------------------------------
$_SESSION = [];
$_POST = $form();
$r = $orders->submit();
check($r->headers['Location'] === '/login/customer', 'Logged-out submit goes to the customer login');
$login('station-admin');
$_POST = $form();
check($orders->submit()->headers['Location'] === '/login/customer', 'Another role cannot submit customer orders');
$login();
$_POST = $form(['_token' => 'forged']);
$r = $orders->submit();
check(str_starts_with($r->headers['Location'], '/customer/checkout?') && $flash()['message'] === 'Your session expired. Please try again.' && $db->called('rpc', 'submit_order') === [], 'CSRF failure sends nothing');
$_POST = $form(['qty' => [P => '0']]);
$orders->submit();
check($flash()['message'] === 'Check the form and try again.' && $db->called('rpc', 'submit_order') === [], 'Empty basket is rejected before the database');
$db->results['submit_order'] = ['ok' => true, 'data' => ['order_id' => O, 'order_no' => 'HMT-0007', 'status' => 'confirmed', 'version' => 1]];
$_POST = $form(['price_centavos' => '1', 'fee_centavos' => '0', 'total' => '1']);
$r = $orders->submit();
$call = $last('submit_order');
check($r->headers['Location'] === '/customer/tracking?order=' . O && str_contains($flash()['message'], 'HMT-0007'), 'Success opens tracking with the order number');
check($call[3] === 'token-u-1' && $call[2]['p_station_id'] === S && $call[2]['p_request_key'] === K, 'Submit uses the user token, station and request key');
check($call[2]['p_payload'] === ['fulfillment' => 'delivery', 'schedule' => 'asap', 'note' => 'Blue gate', 'items' => [['product_id' => P, 'quantity' => 2]], 'address_id' => A], 'Payload carries no price, fee or total');
$db->results['submit_order'] = ['ok' => true, 'data' => ['order_id' => O, 'order_no' => 'HMT-0008', 'status' => 'on_hold', 'version' => 1, 'duplicate_order_no' => 'HMT-0007']];
$_POST = $form();
$orders->submit();
$f = $flash();
check($f['type'] === 'warning' && str_contains($f['message'], 'HMT-0007'), 'Possible duplicate warns and names the original');
$db->results['submit_order'] = ['ok' => false, 'error_code' => 'out_of_range', 'field_errors' => ['address_id' => 'This address is about 15.5 km away. The station delivers up to 10 km.'], 'data' => []];
$_POST = $form(['schedule' => 'scheduled', 'requested_at' => '2026-11-20T10:00']);
$r = $orders->submit();
check(str_contains($r->headers['Location'], 'requested_at=2026-11-20T10%3A00') && str_contains($r->headers['Location'], 'qty%5B' . P . '%5D=2'), 'Validation failure reopens checkout with the same choices');
check($flash()['message'] === 'This address is about 15.5 km away. The station delivers up to 10 km.' && isset($_SESSION['form_errors']['address_id']), 'Database field message is shown');
$db->errors['submit_order'] = new SupabaseException('Supabase request failed with HTTP 403.', 403, '42501');
$_POST = $form();
$orders->submit();
check($flash()['message'] === 'You cannot do that.', 'Permission errors are friendly, never raw');
$db->errors['submit_order'] = new RuntimeException('Connection reset');
$_POST = $form();
$orders->submit();
check(str_contains($flash()['message'], 'will not be done twice'), 'Network errors invite a safe retry with the same key');
unset($db->errors['submit_order']);

// --- Order actions ------------------------------------------------------------------------------
$db->results['cancel_order'] = ['ok' => true, 'data' => ['order_id' => O, 'status' => 'cancelled', 'version' => 4]];
$_POST = ['_token' => Session::csrfToken(), 'order_id' => O, 'expected_version' => '3', 'reason' => 'Changed my mind', 'request_key' => K];
$r = $orders->cancel();
$call = $last('cancel_order');
check($r->headers['Location'] === '/customer/tracking?order=' . O && $call[2] === ['p_order_id' => O, 'p_expected_version' => 3, 'p_reason' => 'Changed my mind', 'p_request_key' => K], 'Cancel sends version, reason and key');
$_POST['expected_version'] = 'abc';
$orders->cancel();
check($flash()['message'] === 'Check the form and try again.', 'Bad version rejected locally');
$db->results['reschedule_order'] = ['ok' => true, 'data' => ['order_id' => O, 'status' => 'scheduled', 'version' => 2]];
$_POST = ['_token' => Session::csrfToken(), 'order_id' => O, 'expected_version' => '1', 'requested_at' => '2026-11-20T10:00', 'request_key' => K];
$orders->reschedule();
check($last('reschedule_order')[2]['p_requested_at'] === '2026-11-20T10:00:00+08:00', 'Manila local time becomes an ISO instant');
$db->results['confirm_duplicate'] = ['ok' => false, 'error_code' => 'stale_version', 'field_errors' => [], 'data' => []];
$_POST = ['_token' => Session::csrfToken(), 'order_id' => O, 'expected_version' => '1', 'request_key' => K];
$orders->confirmDuplicate();
check(str_contains($flash()['message'], 'changed a moment ago'), 'Stale version message');

// --- JSON endpoints -----------------------------------------------------------------------------
$_GET = ['station' => S, 'qty' => [P => '2'], 'fulfillment' => 'pickup'];
$db->results['quote_order'] = ['ok' => true, 'data' => ['fulfillment' => 'pickup', 'subtotal_centavos' => 4000, 'fee_centavos' => 0, 'total_centavos' => 4000, 'total_units' => 2, 'delivery' => null,
    'items' => [['name' => 'Round refill', 'quantity' => 2, 'line_total_centavos' => 4000]]]];
$body = json_decode($orders->quote()->body, true);
check($body['ok'] && $body['data']['total'] === 'PHP 40.00' && $body['data']['fee'] === 'Free (pickup)' && $body['data']['lines'][0] === ['2 × Round refill', 'PHP 40.00'], 'Quote endpoint formats database totals');
$_SESSION = [];
check($orders->quote()->status === 401, 'Quote endpoint needs a customer session');
$login();
$_GET = ['order' => O];
$db->results['get_customer_order'] = ['ok' => true, 'data' => ['order' => ['version' => 5, 'status' => 'ready', 'fulfillment' => 'pickup']]];
$body = json_decode($orders->status()->body, true);
check($body['data'] === ['version' => 5, 'status' => 'ready', 'label' => 'Ready for pickup'], 'Tracking poll returns version and label');
$_GET = ['order' => 'not-a-uuid'];
check($orders->status()->status === 422, 'Tracking poll validates the order id');

// --- Live pages ---------------------------------------------------------------------------------
$login();
$ui = new UIController(false, new StationDirectory($db), null, $current);
$db->tables['stations'] = [['id' => S, 'name' => 'Live Station', 'address' => 'Purok 3, Guintas', 'barangay' => 'Guintas', 'phone' => '+639170000001', 'slug' => 'live', 'registration_status' => 'approved', 'active' => true, 'weekday_open' => '07:00:00', 'weekday_close' => '18:00:00', 'sunday_open' => null, 'sunday_close' => null, 'closed_notice' => null]];
$db->tables['products'] = [['id' => P, 'station_id' => S, 'active' => 'true', 'name' => 'Round refill', 'pack_text' => '18.9 L', 'description' => '', 'image_key' => 'water-jug', 'price_centavos' => 2000, 'returnable' => true, 'load_units' => 1]];
$db->tables['addresses'] = [['id' => A, 'label' => 'Home', 'address' => 'Purok 1', 'latitude' => 10.61, 'longitude' => 122.0]];
$db->results['get_station_availability'] = ['ok' => true, 'data' => ['accepting_asap' => true, 'can_book' => true, 'label' => 'Open now', 'preview' => []]];
$db->results['quote_order'] = ['ok' => true, 'data' => ['fulfillment' => 'delivery', 'subtotal_centavos' => 4000, 'fee_centavos' => 1000, 'total_centavos' => 5000, 'total_units' => 2,
    'delivery' => ['distance_display' => 1.1], 'items' => [['name' => 'Round refill', 'quantity' => 2, 'unit_price_centavos' => 2000, 'line_total_centavos' => 4000]]]];
$_GET = ['station' => S, 'qty' => [P => '2']];
$page = $ui->page('customer', 'checkout')->body;
check(str_contains($page, 'action="/customer/orders"') && str_contains($page, 'name="' . P . '"') && str_contains($page, 'value="' . Session::csrfToken() . '"'), 'Checkout posts a real form with CSRF');
check(str_contains($page, 'PHP 50.00') && str_contains($page, '1.1 km') && str_contains($page, 'Live Station') && !str_contains($page, 'Guintas Water Station') && !str_contains($page, 'Maria Santos'), 'Checkout shows the database quote, not samples');
$db->results['quote_order'] = ['ok' => false, 'error_code' => 'load_units_missing', 'field_errors' => ['items' => 'Some products are pickup only.'], 'data' => []];
$page = $ui->page('customer', 'checkout')->body;
check(str_contains($page, 'Some products are pickup only.') && str_contains($page, 'data-subtotal="40"') && str_contains($page, '2 &times; PHP 20.00'), 'A refused quote still lists the basket at catalogue prices');
$_GET = ['station' => '99999999-9999-4999-8999-999999999999'];
$db->tables['stations'][0]['id'] = S;
check(str_contains($ui->page('customer', 'checkout')->body, 'Station not found'), 'Unknown station');

$db->results['get_customer_order'] = ['ok' => true, 'data' => [
    'order' => ['id' => O, 'order_no' => 'HMT-0007', 'status' => 'confirmed', 'fulfillment' => 'delivery', 'version' => 2, 'schedule_mode' => 'asap', 'created_at' => '2026-11-15T01:00:00+00:00', 'requested_at' => '2026-11-15T01:00:00+00:00',
        'subtotal_centavos' => 4000, 'quoted_fee_centavos' => 1000, 'final_fee_centavos' => null, 'total_centavos' => 5000, 'distance_display' => 1.1, 'address_label' => 'Home', 'address_text' => 'Purok 1', 'address_landmark' => '', 'note' => '', 'cancel_reason' => null],
    'station' => ['id' => S, 'name' => 'Live Station', 'address' => 'Purok 3', 'barangay' => 'Guintas', 'phone' => '+639170000001'],
    'items' => [['name' => 'Round refill <b>', 'pack_text' => '18.9 L', 'quantity' => 2, 'unit_price_centavos' => 2000, 'line_total_centavos' => 4000]],
    'timeline' => [['from' => null, 'to' => 'confirmed', 'reason' => null, 'at' => '2026-11-15T01:00:00+00:00']],
    'driver' => null, 'duplicate_of' => null, 'can_cancel' => true, 'can_confirm_duplicate' => false, 'can_reschedule' => true]];
$_GET = ['order' => O];
$page = $ui->page('customer', 'tracking')->body;
check(str_contains($page, 'Order HMT-0007') && str_contains($page, 'data-poll-version="2"') && str_contains($page, 'action="/customer/orders/cancel"') && str_contains($page, 'name="expected_version" value="2"'), 'Tracking shows the order, polling and a versioned cancel form');
check(str_contains($page, 'Round refill &lt;b&gt;') && !str_contains($page, 'Round refill <b>'), 'Database text is escaped');
$db->results['get_customer_order'] = ['ok' => false, 'error_code' => 'not_found', 'data' => []];
check(str_contains($ui->page('customer', 'tracking')->body, 'Order not found'), 'Another customer\'s order is not found');

$db->results['list_customer_orders'] = ['ok' => true, 'data' => ['orders' => [['id' => O, 'order_no' => 'HMT-0007', 'station_name' => 'Live Station', 'fulfillment' => 'pickup', 'status' => 'ready', 'version' => 3,
    'created_at' => '2026-11-15T01:00:00+00:00', 'requested_at' => null, 'schedule_mode' => 'asap', 'total_units' => 2, 'total_centavos' => 4000]]]];
$_GET = ['group' => 'active'];
$page = $ui->page('customer', 'orders')->body;
check(str_contains($page, 'HMT-0007') && str_contains($page, 'Ready for pickup') && !str_contains($page, 'HMT-104') && $last('list_customer_orders')[2]['p_filters'] === ['group' => 'all'], 'Orders list is live; tabs filter in the page');
$db->errors['list_customer_orders'] = new RuntimeException('down');
$page = $ui->page('customer', 'orders')->body;
check(str_contains($page, 'could not be loaded') && !str_contains($page, 'HMT-098'), 'Supabase failure shows an error state, never samples');
unset($db->errors['list_customer_orders']);
$db->results['list_notifications'] = ['ok' => true, 'data' => ['unread' => 1, 'notifications' => [['id' => K, 'kind' => 'order_ready', 'title' => 'Order HMT-0007 is ready for pickup', 'body' => 'Bring your containers.', 'order_id' => O, 'order_no' => 'HMT-0007', 'station_name' => 'Live Station', 'created_at' => '2026-11-15T01:00:00+00:00', 'read_at' => null]]]];
$page = $ui->page('customer', 'notifications')->body;
check(str_contains($page, 'ready for pickup') && str_contains($page, 'action="/customer/notifications/read"') && str_contains($page, 'Mark all as read') && !str_contains($page, 'Rider Juan Reyes'), 'Notifications are live with read actions');

echo $checks . " order checks passed.\n";
