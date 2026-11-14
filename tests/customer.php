<?php
// Phase 3 PHP tests: customer account, password, sign-out everywhere, addresses and the live
// overview, stations, station, account and address pages, with fake Supabase and Auth.
// Run: php tests/customer.php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/support/fakes.php';

use App\Controllers\CustomerController;
use App\Controllers\UIController;
use App\Core\Session;
use App\Services\CurrentUser;
use App\Services\Registry;
use App\Services\StationDirectory;
use App\Services\Workspace;

const CST = '11111111-1111-4111-8111-111111111111';
const BRG = '22222222-2222-4222-8222-222222222222';
const ADR = '33333333-3333-4333-8333-333333333333';
const PRO = '44444444-4444-4444-8444-444444444444';
const RQK = '55555555-5555-4555-8555-555555555555';

$db = new FakeGateway();
$auth = new FakeAuth();
$account = $auth->createConfirmedUser('lia@example.test', 'old-password-1', ['full_name' => 'Lia Live']);
$current = new CurrentUser($auth);
Workspace::boot(new Workspace($db, $current));
$customer = new CustomerController($db, $current, $auth);
$login = static function () use ($account): void {
    $_SESSION = [];
    Session::login(['id' => $account['id'], 'role' => 'customer', 'name' => 'Lia Live', 'email' => 'lia@example.test', 'station' => null],
        ['access_token' => 'token-' . $account['id'], 'refresh_token' => 'refresh-' . $account['id'], 'expires_at' => 2_000_000_000]);
};
$flash = static fn (): array => Session::takeFlash() ?? ['type' => '', 'message' => ''];
$last = static function (string $fn) use ($db): ?array { $c = $db->called('rpc', $fn); return $c === [] ? null : end($c); };
$post = static fn (array $f): array => $_POST = $f + ['_token' => Session::csrfToken(), 'request_key' => RQK];

// Profile.
$login();
$db->results['save_customer_profile'] = ['ok' => true, 'data' => ['version' => 2]];
$post(['display_name' => 'Lia Santos', 'phone' => '0917 123 4567', 'order_updates' => '1', 'expected_version' => '1']);
$r = $customer->saveProfile();
check($r->headers['Location'] === '/customer/account' && $last('save_customer_profile')[2] === ['p_payload' => ['display_name' => 'Lia Santos', 'phone' => '+639171234567', 'order_updates' => 'true', 'station_updates' => 'false'], 'p_expected_version' => 1, 'p_request_key' => RQK], 'Phone normalized; unchecked preference is false');
check($_SESSION['user']['name'] === 'Lia Santos', 'Header name follows the saved profile');
$post(['display_name' => 'Lia Santos', 'phone' => '12345', 'expected_version' => '1']);
$calls = count($db->called('rpc', 'save_customer_profile'));
$customer->saveProfile();
check($flash()['message'] === 'Check the form and try again.' && count($db->called('rpc', 'save_customer_profile')) === $calls, 'Bad phone rejected before the database');

// Password.
$post(['current_password' => 'wrong', 'password' => 'new-password-2', 'confirm_password' => 'new-password-2']);
$customer->changePassword();
check($flash()['message'] === 'Your current password is not correct.' && $auth->users['lia@example.test']['password'] === 'old-password-1', 'Wrong current password changes nothing');
$post(['current_password' => 'old-password-1', 'password' => 'new-password-2', 'confirm_password' => 'different-3']);
$customer->changePassword();
check($auth->users['lia@example.test']['password'] === 'old-password-1', 'Mismatched confirmation changes nothing');
$post(['current_password' => 'old-password-1', 'password' => 'new-password-2', 'confirm_password' => 'new-password-2']);
$customer->changePassword();
check($flash()['message'] === 'Password changed.' && $auth->users['lia@example.test']['password'] === 'new-password-2', 'Password changed through Auth after confirming the current one');

// Addresses.
$db->results['save_customer_address'] = ['ok' => true, 'data' => ['address_id' => ADR, 'version' => 1]];
$post(['label' => 'Home', 'barangay_id' => BRG, 'street' => 'Purok 2', 'landmark' => '', 'latitude' => '10.6512345', 'longitude' => '122.0312345', 'pin_source' => 'map', 'is_default' => '1']);
$r = $customer->saveAddress();
check($r->headers['Location'] === '/customer/addresses' && $last('save_customer_address')[2] === ['p_address_id' => null, 'p_payload' => ['label' => 'Home', 'barangay_id' => BRG, 'street' => 'Purok 2', 'landmark' => '', 'is_default' => 'true', 'latitude' => '10.6512345', 'longitude' => '122.0312345', 'pin_source' => 'map'], 'p_expected_version' => null, 'p_request_key' => RQK], 'New pinned address');
$post(['address_id' => ADR, 'expected_version' => '3', 'label' => 'Home', 'barangay_id' => BRG, 'street' => 'Purok 2', 'latitude' => '', 'longitude' => '', 'pin_source' => 'map']);
$customer->saveAddress();
$call = $last('save_customer_address')[2];
check($call['p_address_id'] === ADR && $call['p_expected_version'] === 3 && !isset($call['p_payload']['latitude']) && $call['p_payload']['is_default'] === 'false', 'Edit without a pin sends no coordinates');
$db->results['save_customer_address'] = ['ok' => false, 'error_code' => 'validation', 'field_errors' => ['latitude' => 'Place the pin inside Hamtic.'], 'data' => []];
$post(['address_id' => ADR, 'expected_version' => '3', 'label' => 'Home', 'barangay_id' => BRG, 'street' => 'Purok 2', 'latitude' => '11.5', 'longitude' => '122']);
$r = $customer->saveAddress();
check($r->headers['Location'] === '/customer/addresses?edit=' . ADR && $flash()['message'] === 'Place the pin inside Hamtic.', 'Database pin error reopens the edit form');
$db->results['delete_customer_address'] = ['ok' => true, 'data' => ['address_id' => ADR]];
$post(['address_id' => ADR, 'expected_version' => '4']);
$customer->deleteAddress();
check($last('delete_customer_address')[2] === ['p_address_id' => ADR, 'p_expected_version' => 4, 'p_request_key' => RQK] && str_contains($flash()['message'], 'Past orders keep'), 'Address removed (soft delete)');

// Pages.
$ui = new UIController(false, new StationDirectory($db), new Registry($db), $current, fakeStationContext($db, CST));
$db->results['get_customer_overview'] = ['ok' => true, 'data' => ['active' => ['id' => PRO, 'order_no' => 'HMT-0003', 'status' => 'preparing', 'fulfillment' => 'delivery', 'station_id' => CST, 'station_name' => 'Real Station',
    'total_units' => 2, 'total_centavos' => 5000, 'schedule_mode' => 'asap', 'requested_at' => null, 'timeline' => [['to' => 'confirmed', 'at' => '2026-11-16T01:00:00+00:00'], ['to' => 'preparing', 'at' => '2026-11-16T01:05:00+00:00']]],
    'recent' => [], 'unread_notifications' => 2, 'unread_messages' => 1, 'default_address' => ['id' => ADR, 'label' => 'Home', 'address' => 'Purok 2, Guintas', 'pinned' => false]]];
$_GET = [];
$page = $ui->page('customer', 'overview')->body;
check(str_contains($page, 'HMT-0003') && str_contains($page, 'add a map pin for delivery') && str_contains($page, 'Good') && !str_contains($page, 'Maria Santos') && !str_contains($page, 'HMT-104'), 'Overview shows the live active order and the pinless-address warning');
$db->tables['stations'] = [['id' => CST, 'slug' => 'real-station', 'name' => 'Real Station', 'address' => 'Purok 1, Guintas', 'barangay' => 'Guintas', 'phone' => '+639170000001', 'description' => 'Clean water since 2010.']];
$db->results['get_station_availability'] = ['ok' => true, 'data' => ['accepting_asap' => true, 'can_book' => true, 'label' => 'Open now', 'public_notice' => '', 'preview' => [['date' => '2026-11-16', 'windows' => [['opens_at' => '07:00:00', 'closes_at' => '18:00:00']]]]]];
$db->tables['products'] = [['id' => PRO, 'station_id' => CST, 'active' => 'true', 'name' => 'Round refill', 'pack_text' => '18.9 L', 'description' => '', 'image_key' => 'water-jug', 'price_centavos' => 2500, 'returnable' => true, 'load_units' => 1]];
$db->tables['station_service_areas'] = [['station_id' => CST, 'served' => 'true', 'barangay_id' => BRG, 'barangays' => ['name' => 'Guintas']]];
$db->tables['addresses'] = [['id' => ADR, 'label' => 'Home', 'address' => 'Purok 2, Guintas', 'latitude' => 10.65, 'is_default' => 'true', 'street' => 'Purok 2', 'landmark' => '', 'barangay_id' => BRG, 'longitude' => 122.03, 'pin_source' => 'map', 'version' => 1]];
$db->results['quote_delivery'] = ['ok' => true, 'data' => ['eligible' => true, 'distance_display' => 1.4, 'fee_centavos' => 1000]];
$_GET = ['station' => 'real-station'];
$page = $ui->page('customer', 'station')->body;
check(str_contains($page, 'PHP 25.00') && str_contains($page, 'name="qty[' . PRO . ']"') && str_contains($page, 'name="station" value="' . CST . '"') && str_contains($page, '1.4 km') && str_contains($page, 'PHP 10.00') && str_contains($page, 'Guintas'), 'Station page: live prices, served barangay and the fee from the default address');
$_GET = ['station' => 'no-such-station'];
$db->tables['stations'][0]['slug'] = 'real-station';
check(str_contains($ui->page('customer', 'station')->body, 'Station not found'), 'Unknown station');
$db->tables['profiles'] = [['id' => $account['id'], 'display_name' => 'Lia <Santos>', 'phone' => '+639171234567', 'order_updates' => true, 'station_updates' => false, 'version' => 2]];
$_GET = [];
$page = $ui->page('customer', 'account')->body;
check(str_contains($page, 'value="Lia &lt;Santos&gt;"') && str_contains($page, 'value="09171234567"') && str_contains($page, 'action="/customer/account/password"') && str_contains($page, 'action="/customer/account/logout-all"') && !str_contains($page, 'maria.santos@example.com'), 'Account page is live and escaped');
$db->tables['barangays'] = [['id' => BRG, 'name' => 'Guintas', 'active' => 'true']];
$page = $ui->page('customer', 'addresses')->body;
check(str_contains($page, 'data-pin-picker') && str_contains($page, 'Pinned (map)') && str_contains($page, 'action="/customer/addresses/delete"') && !str_contains($page, 'National Road, Poblacion'), 'Address page lists live addresses with the pin picker');

// Sign out everywhere last: it ends the session.
$login();
$post([]);
$r = $customer->logoutEverywhere();
check($r->headers['Location'] === '/login/customer' && $auth->signedOut === ['token-' . $account['id']] && !isset($_SESSION['user']), 'Signed out at Supabase Auth and locally');

echo $checks . " customer checks passed.\n";
