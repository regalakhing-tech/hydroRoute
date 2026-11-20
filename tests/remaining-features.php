<?php
// M13, M15 (date limits), M17, M18 and M19 PHP tests: rider editing, refill limit form, station status, trusted
// station devices and the rider road route, with a fake Supabase and a fake road router.
// Run: php tests/remaining-features.php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/support/fakes.php';

use App\Controllers\RegistryController;
use App\Controllers\RiderController;
use App\Controllers\StationOpsController;
use App\Controllers\UIController;
use App\Core\Session;
use App\Services\CurrentUser;
use App\Services\Invitations;
use App\Services\Mailer;
use App\Services\Registry;
use App\Services\RoadRouter;
use App\Services\SessionStore;
use App\Services\SupabaseException;
use App\Services\Workspace;

const ST = '11111111-1111-4111-8111-111111111111';
const RID = '66666666-6666-4666-8666-666666666666';
const KEY = '55555555-5555-4555-8555-555555555555';
const TRIP = '77777777-7777-4777-8777-777777777777';

$db = new FakeGateway();
$current = new CurrentUser(new FakeAuth());
Workspace::boot(new Workspace($db, $current));
$login = static function (string $role, array $extra = []): void {
    $_SESSION = [];
    Session::login(['id' => 'u-1', 'role' => $role, 'name' => 'Live User', 'email' => 'user@example.test', 'station' => ['id' => ST, 'name' => 'Live Station', 'slug' => 'live']],
        ['access_token' => 'token-u-1', 'refresh_token' => 'refresh-u-1', 'expires_at' => 2_000_000_000] + $extra);
};
$flash = static fn (): array => Session::takeFlash() ?? ['type' => '', 'message' => ''];
$last = static function (string $fn) use ($db): ?array { $c = $db->called('rpc', $fn); return $c === [] ? null : end($c); };
$post = static fn (array $f): array => $_POST = $f + ['_token' => Session::csrfToken(), 'request_key' => KEY];

// --- M13: rider editing -------------------------------------------------------------------------
$ops = new StationOpsController($db, $current);
$login('station-admin');
$db->results['save_rider'] = ['ok' => true, 'data' => ['rider_id' => RID, 'version' => 4]];
$form = ['rider_id' => RID, 'expected_version' => '3', 'vehicle' => 'Tricycle', 'plate_number' => 'ant 101', 'capacity' => '15', 'enabled' => '1', 'back' => '/station-admin/drivers'];
$post($form + ['full_name' => 'Rider Uno', 'phone' => '0917 123 4567']);
$r = $ops->saveRider();
check($r->headers['Location'] === '/station-admin/drivers' && $last('save_rider')[2]['p_payload'] === ['vehicle' => 'Tricycle', 'plate_number' => 'ant 101', 'capacity' => '15', 'enabled' => 'true', 'full_name' => 'Rider Uno', 'phone' => '+639171234567'],
    'Drivers page edit sends name and normalized phone, then returns to Drivers');
$count = count($db->called('rpc', 'save_rider'));
$post($form + ['full_name' => 'Rider Uno', 'phone' => '12345']);
$ops->saveRider();
check(count($db->called('rpc', 'save_rider')) === $count && $flash()['message'] === 'Check the form and try again.', 'Bad phone refused before the database');
$post(['rider_id' => RID, 'expected_version' => '3', 'vehicle' => 'Tricycle', 'plate_number' => 'ANT 101', 'capacity' => '15']);
$r = $ops->saveRider();
check($r->headers['Location'] === '/station-admin/deliveries' && !isset($last('save_rider')[2]['p_payload']['full_name']) && $last('save_rider')[2]['p_payload']['enabled'] === 'false',
    'Vehicle-only form keeps the old fields and unticked means inactive');
$login('customer');
$post($form);
check($ops->saveRider()->headers['Location'] === '/login/station-admin', 'Customers cannot edit riders');

// Drivers page rows come from get_station_riders, with status, trip and the edit dialog.
$login('station-admin');
$db->results['get_station_riders'] = ['ok' => true, 'data' => ['riders' => [
    ['user_id' => 'u-r1', 'rider_id' => RID, 'version' => 3, 'full_name' => 'Rider One', 'phone' => '+639175555555', 'email' => 'r1@example.test', 'vehicle' => 'Tricycle', 'plate_number' => 'ANT 101', 'capacity' => 15, 'enabled' => true, 'trip_id' => TRIP, 'trip_no' => 8],
    ['user_id' => 'u-r2', 'rider_id' => null, 'version' => null, 'full_name' => 'Old Driver', 'phone' => '+639176666666', 'email' => 'r2@example.test', 'vehicle' => 'Tricycle', 'plate_number' => 'ANT 102', 'capacity' => 10, 'enabled' => false, 'trip_id' => null, 'trip_no' => null],
]]];
$ui = new UIController(false, null, new Registry($db), $current, fakeStationContext($db, ST));
$page = $ui->page('station-admin', 'drivers')->body;
check(str_contains($page, 'In progress') && str_contains($page, 'Trip 8') && str_contains($page, '/station-admin/trip?trip=' . TRIP) && str_contains($page, 'Inactive'), 'Drivers show live status and the active trip');
check(str_contains($page, 'id="driver-edit-u-r1"') && str_contains($page, 'action="/station-admin/riders/save"') && str_contains($page, 'name="expected_version" value="3"')
    && str_contains($page, 'value="Rider One"') && str_contains($page, '0917 555 5555') && !str_contains($page, 'id="driver-edit-u-r2"'), 'Edit dialog prefilled; no dialog without a rider record');

// Before migration 20261122000200 is applied the page still lists drivers from the driver table, with no error.
$db->errors['get_station_riders'] = new SupabaseException('Could not find the function', 404, 'PGRST202');
$db->tables['drivers'] = [['user_id' => 'u-r9', 'station_id' => ST, 'full_name' => 'Table Driver', 'phone' => '+639177777777', 'email' => 'r9@example.test', 'vehicle' => 'Tricycle', 'plate_number' => 'ANT 109', 'capacity' => 12, 'active' => true, 'created_at' => '2026-11-01T00:00:00Z']];
Session::takeFlash();
$page = $ui->page('station-admin', 'drivers')->body;
check(str_contains($page, 'Table Driver') && !str_contains($page, 'could not be loaded') && Session::takeFlash() === null && !str_contains($page, 'id="driver-edit-'), 'Missing rider RPC falls back to the driver table without an error');
unset($db->errors['get_station_riders']);

// --- M15: one date's refill limit -------------------------------------------------------------
$db->results['save_capacity_day'] = ['ok' => true, 'data' => ['service_date' => '2099-01-05', 'gallon_limit' => 40, 'booked' => 3]];
$post(['service_date' => '2099-01-05', 'gallon_limit' => '40']);
$r = $ops->saveCapacityDay();
check($r->headers['Location'] === '/station-admin/schedules' && $last('save_capacity_day')[2] === ['p_station_id' => ST, 'p_service_date' => '2099-01-05', 'p_gallon_limit' => 40, 'p_request_key' => KEY], 'Date limit sent as a number');
$db->results['save_capacity_day'] = ['ok' => true, 'data' => ['service_date' => '2099-01-05', 'gallon_limit' => null, 'booked' => 3]];
$post(['service_date' => '2099-01-05', 'gallon_limit' => '']);
$ops->saveCapacityDay();
check($last('save_capacity_day')[2]['p_gallon_limit'] === null && str_contains($flash()['message'], 'default'), 'Blank limit clears the override');
$count = count($db->called('rpc', 'save_capacity_day'));
foreach (['2099-02-30', '05/01/2099', ''] as $bad) { $post(['service_date' => $bad, 'gallon_limit' => '5']); $ops->saveCapacityDay(); }
$post(['service_date' => '2099-01-05', 'gallon_limit' => '-1']); $ops->saveCapacityDay();
check(count($db->called('rpc', 'save_capacity_day')) === $count, 'Invalid dates and negative limits refused before the database');

// --- M17: station status --------------------------------------------------------------------
$registry = new Registry($db);
$admin = new RegistryController($registry, new Invitations(new FakeAuth(), $registry, new FakeMailer(), 'http://localhost'), $current);
$login('super-admin');
$db->results['set_station_status'] = ['ok' => true, 'data' => ['station_id' => ST, 'status' => 'suspended']];
$post(['station_id' => ST, 'status' => 'suspended', 'expected_status' => 'approved', 'reason' => 'Permit expired']);
$r = $admin->stationStatus();
check($r->headers['Location'] === '/super-admin/stations' && $last('set_station_status')[2] === ['p_station_id' => ST, 'p_status' => 'suspended', 'p_expected_status' => 'approved', 'p_reason' => 'Permit expired', 'p_request_key' => KEY]
    && str_contains($flash()['message'], 'suspended'), 'Super Admin suspends with reason, expected status and key');
$db->results['set_station_status'] = ['ok' => false, 'error_code' => 'station_busy', 'field_errors' => ['status' => 'This station still has open orders, trips or reservation requests.'], 'data' => []];
$post(['station_id' => ST, 'status' => 'archived', 'expected_status' => 'suspended', 'reason' => 'Closed']);
$admin->stationStatus();
check(str_contains($flash()['message'], 'still has open orders'), 'Database refusal shown to the Super Admin');
$count = count($db->called('rpc', 'set_station_status'));
foreach ([['status' => 'deleted'], ['reason' => 'x'], ['station_id' => 'nope'], ['expected_status' => 'archived']] as $bad) {
    $post($bad + ['station_id' => ST, 'status' => 'suspended', 'expected_status' => 'approved', 'reason' => 'Permit expired']); $admin->stationStatus();
}
check(count($db->called('rpc', 'set_station_status')) === $count, 'Bad status, short reason, bad id and removed stations refused before the database');
$login('station-admin');
$post(['station_id' => ST, 'status' => 'suspended', 'expected_status' => 'approved', 'reason' => 'Permit expired']);
check($admin->stationStatus()->headers['Location'] === '/login/super-admin' && count($db->called('rpc', 'set_station_status')) === $count, 'Only the Super Admin can change a station');
$_POST = [];

// --- M18: trusted station devices -----------------------------------------------------------
$_SESSION = [];
check(Session::ttl() === Session::TTL, 'Normal sessions keep the 8-hour store lifetime');
$_SESSION = ['user' => ['role' => 'station-admin'], 'remember_until' => time() + Session::REMEMBER_TTL];
check(Session::ttl() > Session::REMEMBER_TTL - 5, 'A remembered station device keeps the session for 30 days');
$_SESSION['remember_until'] = time() - 1;
check(Session::ttl() === Session::TTL, 'An expired remember window falls back to 8 hours');
$store = new SessionStore($db);
$_SESSION = ['user' => ['role' => 'station-admin'], 'remember_until' => time() + 1000];
$store->write('sess-1', 'user|a:0:{}');
$written = end($db->calls);
check($written[1] === 'session_write' && $written[2]['p_ttl'] > 990 && $written[2]['p_ttl'] <= 1000, 'Shared store uses the remembered lifetime');
// Refresh failures: a refusal logs out; an outage keeps the session for the next request.
$flaky = new class implements App\Services\AuthProvider {
    public ?Throwable $error = null;
    public function isConfigured(): bool { return true; }
    public function canCreateUsers(): bool { return false; }
    public function createConfirmedUser(string $email, string $password, array $metadata): array { return []; }
    public function signInWithPassword(string $email, string $password): array { return []; }
    public function refreshSession(string $refreshToken): array { throw $this->error; }
    public function setUserPassword(string $userId, string $password): void {}
    public function deleteUser(string $userId): void {}
    public function saveDisplayName(string $userId, string $name, string $accessToken): void {}
    public function signOut(string $accessToken): void {}
    public function access(string $accessToken): array { return []; }
    public function inviteLink(string $email, array $metadata): array { return []; }
    public function verifyLink(string $type, string $tokenHash): array { return []; }
    public function updatePassword(string $accessToken, string $password): void {}
};
$expiring = new CurrentUser($flaky, static fn (): int => 2_000_000_000);
foreach ([[new RuntimeException('network down'), true], [new SupabaseException('HTTP 503', 503), true], [new SupabaseException('HTTP 429', 429), true], [new SupabaseException('HTTP 400', 400, 'refresh_token_not_found'), false]] as [$error, $kept]) {
    $login('station-admin');
    $flaky->error = $error;
    $expiring->unavailable = false;
    check($expiring->token() === null && (Session::user() !== null) === $kept && $expiring->unavailable === $kept, ($kept ? 'Kept' : 'Logged out') . ' after refresh failure: ' . $error->getMessage());
}
// An outage shows a retry page instead of bouncing between the workspace and the login page.
$login('station-admin');
$flaky->error = new RuntimeException('network down');
$expiring->unavailable = false;
$outage = (new UIController(false, null, new Registry($db), $expiring, fakeStationContext($db, ST)))->page('station-admin', 'overview');
check($outage->status === 503 && !isset($outage->headers['Location']) && str_contains($outage->body, 'still signed in') && Session::user() !== null, 'Auth outage: retry page, no login redirect, still signed in');

// --- M19: road route ------------------------------------------------------------------------
$osrm = static fn (array $steps = []): string => json_encode(['code' => 'Ok', 'routes' => [['distance' => 2412.4, 'duration' => 431.6,
    'geometry' => ['coordinates' => [[122.0, 10.6], [122.001, 10.605], [122.002, 10.61]]],
    'legs' => [['steps' => $steps ?: [
        ['name' => 'Rizal Street', 'distance' => 120.4, 'maneuver' => ['type' => 'depart', 'modifier' => 'north']],
        ['name' => 'Bonifacio Road', 'distance' => 900, 'maneuver' => ['type' => 'turn', 'modifier' => 'left']],
        ['name' => '', 'distance' => 60, 'maneuver' => ['type' => 'turn', 'modifier' => 'slight_right']],
        ['name' => 'Mabini', 'distance' => 300, 'maneuver' => ['type' => 'roundabout', 'exit' => 2]],
        ['name' => '', 'distance' => 0, 'maneuver' => ['type' => 'arrive']],
    ]]]]]]);
$urls = [];
$router = new RoadRouter('https://osrm.example', static function (string $url) use (&$urls, $osrm): string { $urls[] = $url; return $osrm(); });
$route = $router->route([10.6, 122.0], [10.61, 122.002]);
check($urls[0] === 'https://osrm.example/route/v1/driving/122.000000,10.600000;122.002000,10.610000?overview=full&geometries=geojson&steps=true', 'Router sends only lng,lat pairs in stop order');
check($route['distance_m'] === 2412 && $route['duration_s'] === 432 && $route['path'][0] === [10.6, 122.0] && count($route['path']) === 3, 'Distance, time and lat,lng path parsed');
check(array_column($route['steps'], 'text') === ['Start on Rizal Street, heading north', 'Turn left onto Bonifacio Road', 'Keep right', 'Enter the roundabout and take exit 2 onto Mabini', 'Arrive at the delivery point'], 'Maneuvers become plain directions');
foreach (['{"code":"NoRoute","routes":[]}', 'not json', '{"code":"Ok","routes":[{"geometry":{"coordinates":[[122,10.6]]}}]}'] as $bad) {
    $broken = new RoadRouter('https://osrm.example', static fn (): string => $bad);
    try { $broken->route([10.6, 122.0], [10.61, 122.0]); $threw = false; } catch (RuntimeException) { $threw = true; }
    check($threw, 'Unusable router answer refused: ' . substr($bad, 0, 20));
}

$asked = [];
$rider = new RiderController($db, $current, new RoadRouter('https://osrm.example', static function (string $url) use (&$asked, $osrm): string { $asked[] = $url; return $osrm(); }));
$trip = static fn (array $stops, ?array $station = ['latitude' => 10.6, 'longitude' => 122.0]): array => ['ok' => true, 'data' => ['trip' => ['id' => TRIP, 'version' => 2], 'station' => $station, 'stops' => $stops]];
$stop = static fn (int $seq, string $state, ?float $lat, ?float $lng, string $name): array => ['id' => 'stop-' . $seq, 'sequence' => $seq, 'state' => $state, 'order' => ['latitude' => $lat, 'longitude' => $lng, 'customer_name' => $name]];
$json = static fn ($response): array => json_decode($response->body, true);
$login('rider');
$db->results['get_trip'] = $trip([$stop(2, 'pending', 10.62, 122.01, 'Second'), $stop(1, 'delivered', 10.61, 122.0, 'First')]);
$_GET = ['lat' => '10.605', 'lng' => '122.003'];
$body = $json($rider->route());
check($body['ok'] && $body['data']['to'] === [10.62, 122.01] && $body['data']['from'] === [10.605, 122.003] && $body['data']['label'] === 'Second' && !$body['data']['from_station'],
    'Route goes from the rider to the next open stop in sequence order');
check(str_contains(end($asked), '122.003000,10.605000;122.010000,10.620000'), 'Router receives the rider and stop coordinates only');
check($body['data']['warning'] === null && $body['data']['straight_m'] > 1500, 'A sensible road route has no warning');
$detour = new RiderController($db, $current, new RoadRouter('https://osrm.example', static fn (): string => str_replace('"distance":2412.4', '"distance":33561', $osrm())));
check(str_contains((string) $json($detour->route())['data']['warning'], 'incomplete'), 'A huge detour (missing roads in the map) is flagged');
$_GET = ['lat' => '14.6', 'lng' => '121.0']; // Manila: outside the service area, so the route starts at the station
$body = $json($rider->route());
check($body['ok'] && $body['data']['from_station'] && $body['data']['from'] == [10.6, 122.0], 'Without a usable fix the route starts at the station');
$_GET = ['lat' => '10.605', 'lng' => '122.003', 'to_lat' => '10.7', 'to_lng' => '122.05'];
check($json($rider->route())['data']['to'] === [10.62, 122.01], 'A destination in the request is ignored');
$db->results['get_trip'] = $trip([$stop(1, 'delivered', 10.61, 122.0, 'First')]);
$body = $json($rider->route());
check($body['ok'] && $body['data']['to_station'] && $body['data']['to'] == [10.6, 122.0] && $body['data']['label'] === 'Back to the station', 'After the last stop the route leads back to the station');
$db->results['get_trip'] = $trip([$stop(1, 'pending', null, null, 'No pin')]);
check($json($rider->route())['error_code'] === 'no_pin', 'A stop without a map pin reports no_pin');
$db->results['get_trip'] = ['ok' => true, 'data' => ['trip' => null]];
check($json($rider->route())['error_code'] === 'not_found', 'No active trip');
$db->results['get_trip'] = $trip([$stop(1, 'pending', 10.62, 122.01, 'Second')]);
$down = new RiderController($db, $current, new RoadRouter('https://osrm.example', static fn (): string => throw new RuntimeException('timeout')));
$_GET = ['lat' => '10.605', 'lng' => '122.003'];
check($json($down->route())['error_code'] === 'no_route', 'Router outage becomes no_route, not a 500');
$login('customer');
check($rider->route()->status === 401, 'Only riders get routes');
$_GET = [];

// --- Address pin: nearest landmark from the reverse lookup ----------------------------------
$geo = static fn (array $place): array => (new App\Services\Geocoder('test', static fn (): string => json_encode($place)))->reverse(10.69, 122.03);
check($geo(['name' => 'Hamtic Central School', 'address' => ['road' => 'Rizal Street', 'amenity' => 'school', 'village' => 'Guintas']])['landmark'] === 'Hamtic Central School', 'Named place becomes the landmark');
check($geo(['name' => 'Rizal Street', 'address' => ['road' => 'Rizal Street', 'shop' => 'Sari-sari ni Lita']])['landmark'] === 'Sari-sari ni Lita', 'The road name is not a landmark; the shop is');
check($geo(['name' => '', 'address' => ['road' => 'Rizal Street', 'building' => 'yes']])['landmark'] === '', 'Generic tags give no landmark');
check($geo(['address' => ['road' => 'Rizal Street', 'village' => 'Guintas']])['street'] === 'Rizal Street', 'Street lookup unchanged');

// --- Customer reschedule (plan M5): date + 5-minute time from the tracking page ------------
$orders = new App\Controllers\OrderController($db, $current);
$login('customer');
$db->results['reschedule_order'] = ['ok' => true, 'data' => ['order_id' => KEY, 'status' => 'scheduled', 'version' => 4]];
$post(['order_id' => KEY, 'expected_version' => '3', 'date' => '2099-01-05', 'time' => '08:05']);
$r = $orders->reschedule();
check($r->headers['Location'] === '/customer/tracking?order=' . KEY && $last('reschedule_order')[2]['p_requested_at'] === '2099-01-05T08:05:00+08:00' && $last('reschedule_order')[2]['p_expected_version'] === 3, 'Date and time become a Manila timestamp');
$count = count($db->called('rpc', 'reschedule_order'));
$post(['order_id' => KEY, 'expected_version' => '3', 'date' => '2099-01-05', 'time' => '8am']); $orders->reschedule();
check(count($db->called('rpc', 'reschedule_order')) === $count, 'A malformed time never reaches the database');
$day = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->modify('+1 day')->format('Y-m-d');
$db->results['get_customer_order'] = ['ok' => true, 'data' => ['order' => ['id' => KEY, 'order_no' => 'HMT-0009', 'status' => 'scheduled', 'fulfillment' => 'pickup', 'version' => 3, 'schedule_mode' => 'scheduled',
    'created_at' => '2026-11-20T01:00:00+00:00', 'requested_at' => $day . 'T01:00:00+00:00', 'subtotal_centavos' => 2000, 'quoted_fee_centavos' => 0, 'final_fee_centavos' => null, 'total_centavos' => 2000,
    'distance_display' => null, 'address_label' => '', 'address_text' => '', 'address_landmark' => '', 'note' => '', 'cancel_reason' => null],
    'station' => ['id' => ST, 'name' => 'Live Station', 'address' => 'Purok 3', 'barangay' => 'Guintas', 'phone' => '+639170000001'], 'items' => [], 'timeline' => [], 'driver' => null, 'duplicate_of' => null,
    'can_cancel' => true, 'cancel_until' => null, 'can_confirm_duplicate' => false, 'can_reschedule' => true]];
$db->results['get_station_availability'] = ['ok' => true, 'data' => ['preview' => [['date' => $day, 'windows' => [['opens_at' => '08:00:00', 'closes_at' => '17:00:00', 'order_cutoff' => '16:00:00', 'delivery_cutoff' => '15:00:00']]]]]];
$db->results['get_booked_slots'] = ['ok' => true, 'data' => ['slots' => [$day . 'T08:05'], 'refills_left' => []]];
$_GET = ['order' => KEY];
$page = (new UIController(false, null, new Registry($db), $current, null))->page('customer', 'tracking')->body;
check(str_contains($page, 'Change the time') && str_contains($page, 'action="/customer/orders/reschedule"') && str_contains($page, 'data-slot-form="pickup"')
    && str_contains($page, 'value="' . $day . '"') && str_contains($page, 'Now set for'), 'Tracking offers a 5-minute reschedule form for a scheduled order');
$db->results['get_customer_order']['data']['can_reschedule'] = false;
check(!str_contains((new UIController(false, null, new Registry($db), $current, null))->page('customer', 'tracking')->body, 'Change the time'), 'No form once the order cannot be rescheduled');
$_GET = []; $_POST = [];

// --- Order-update email outbox (plan M8) ----------------------------------------------------
$outboxDb = new class implements App\Services\Gateway {
    public array $finished = [];
    public array $emails = [];
    public function select(string $table, array $query = [], ?string $accessToken = null): array { return []; }
    public function rpc(string $function, array $parameters, ?string $accessToken): array { return []; }
    public function adminRpc(string $function, array $parameters): array
    {
        if ($function === 'claim_email_batch') return ['emails' => $this->emails];
        $this->finished[] = $parameters;
        return ['ok' => true];
    }
};
$outboxDb->emails = [
    ['id' => 'e1', 'email' => 'maria@example.test', 'name' => 'Maria', 'subject' => 'Order HMT-0009 is ready', 'body' => 'Collect it today.', 'link' => '/customer/tracking?order=x', 'station' => 'Live Station'],
    ['id' => 'e2', 'email' => 'not-an-email', 'name' => 'X', 'subject' => 'S', 'body' => 'B', 'link' => '/customer/notifications', 'station' => ''],
    ['id' => 'e3', 'email' => 'down@example.test', 'name' => 'Y', 'subject' => 'S', 'body' => 'B', 'link' => '/customer/notifications', 'station' => ''],
];
$mails = [];
$outbox = new App\Services\EmailOutbox($outboxDb, static function (string $to, string $name, string $subject, string $body, string $link) use (&$mails): void {
    if ($to === 'down@example.test') throw new RuntimeException('SMTP Error: Could not connect to SMTP host.');
    $mails[] = compact('to', 'name', 'subject', 'body', 'link');
}, 'https://hydroroute.example/');
$result = $outbox->flush(5);
check($result === ['sent' => 1, 'failed' => 2] && $mails[0]['to'] === 'maria@example.test' && $mails[0]['subject'] === 'Order HMT-0009 is ready · Live Station'
    && $mails[0]['link'] === 'https://hydroroute.example/customer/tracking?order=x', 'Outbox sends with an absolute link and the station name');
check(array_column($outboxDb->finished, 'p_ok', 'p_id') === ['e1' => true, 'e2' => false, 'e3' => false] && str_contains($outboxDb->finished[2]['p_error'], 'SMTP'), 'Each email is reported; failures carry the reason for retry');
$broken = new class implements App\Services\Gateway {
    public function select(string $table, array $query = [], ?string $accessToken = null): array { return []; }
    public function rpc(string $function, array $parameters, ?string $accessToken): array { return []; }
    public function adminRpc(string $function, array $parameters): array { throw new RuntimeException('down'); }
};
check((new App\Services\EmailOutbox($broken, static fn () => null, 'https://x'))->flush() === ['sent' => 0, 'failed' => 0], 'An unreachable database never breaks the page');
$mailConfig = ['host' => 'smtp.example', 'port' => 587, 'username' => 'u', 'password' => 'p', 'encryption' => 'tls', 'from_address' => 'no-reply@example.test', 'from_name' => 'HydroRoute'];
check(App\Services\EmailOutbox::fromConfig($broken, ['mail' => $mailConfig, 'app' => ['preview' => true, 'url' => 'https://x']]) === null
    && App\Services\EmailOutbox::fromConfig($broken, ['mail' => $mailConfig, 'app' => ['preview' => false, 'url' => 'https://x']]) !== null, 'Preview mode never builds an email sender (covers /jobs/email too)');
$notice = (new App\Services\Mailer(['host' => 'smtp.example', 'port' => 587, 'username' => 'u', 'password' => 'p', 'encryption' => 'tls', 'from_address' => 'no-reply@example.test', 'from_name' => 'HydroRoute'],
    dirname(__DIR__) . '/public/assets/images/logo.png'))->noticeEmail('maria@example.test', 'Maria', 'Order <b>ready</b>', "Line 1\n<script>x</script>", 'https://hydroroute.example/customer/tracking?order=x');
check(str_contains($notice->Body, 'Order &lt;b&gt;ready&lt;/b&gt;') && !str_contains($notice->Body, '<script>') && str_contains($notice->Body, 'Open in HydroRoute') && str_contains($notice->AltBody, 'Turn these emails off'), 'Notice email is escaped and has an opt-out line');

// Emails are only sent after a confirmed database write: a CSRF-failed or refused post marks nothing as due.
$login('rider');
App\Services\EmailOutbox::$due = false;
$_POST = ['trip_id' => TRIP, 'latitude' => '10.65', 'longitude' => '122.01', 'request_key' => KEY, '_token' => 'wrong'];
(new RiderController($db, $current))->replan();
check(App\Services\EmailOutbox::$due === false, 'A CSRF-failed post queues no email send');
$db->results['resequence_trip'] = ['ok' => false, 'error_code' => 'validation', 'field_errors' => ['location' => 'Turn on location.'], 'data' => []];
$post(['trip_id' => TRIP, 'latitude' => '10.65', 'longitude' => '122.01']);
(new RiderController($db, $current))->replan();
check(App\Services\EmailOutbox::$due === false, 'A refused write queues no email send');
$db->results['resequence_trip'] = ['ok' => true, 'data' => ['trip_id' => TRIP, 'stops' => 2]];
$post(['trip_id' => TRIP, 'latitude' => '10.65', 'longitude' => '122.01']);
(new RiderController($db, $current))->replan();
check(App\Services\EmailOutbox::$due === true, 'A confirmed write allows the email send');
App\Services\EmailOutbox::$due = false;
Session::takeFlash();

// --- Rider "Re-plan from here" (plan M11) -----------------------------------------------------
$login('rider');
$db->results['resequence_trip'] = ['ok' => true, 'data' => ['trip_id' => TRIP, 'stops' => 3]];
$post(['trip_id' => TRIP, 'latitude' => '10.6512', 'longitude' => '122.0101']);
$r = (new RiderController($db, $current))->replan();
check($r->headers['Location'] === '/rider' && $last('resequence_trip')[2] === ['p_trip_id' => TRIP, 'p_latitude' => 10.6512, 'p_longitude' => 122.0101, 'p_request_key' => KEY] && str_contains($flash()['message'], 're-planned'), 'Re-plan sends the trip and the rider position');
$count = count($db->called('rpc', 'resequence_trip'));
$post(['trip_id' => TRIP, 'latitude' => '', 'longitude' => '']);
(new RiderController($db, $current))->replan();
check(count($db->called('rpc', 'resequence_trip')) === $count && $flash()['message'] === 'Check the form and try again.', 'Re-plan without a location is refused before the database');
$_POST = [];

// --- Pages ------------------------------------------------------------------------------------
$_SESSION = [];
$ui = new UIController(false, null, new Registry($db), $current, fakeStationContext($db, ST));
check(str_contains($ui->login('station-admin')->body, 'name="remember" value="1"') && !str_contains($ui->login('customer')->body, 'name="remember"') && !str_contains($ui->login('rider')->body, 'name="remember"'),
    'Only the station admin login offers "keep me signed in"');

$login('station-admin');
$db->results['get_capacity_days'] = ['ok' => true, 'data' => ['default_limit' => 50, 'days' => [
    ['date' => '2099-01-05', 'limit' => 50, 'override' => false, 'booked' => 12], ['date' => '2099-01-06', 'limit' => 0, 'override' => true, 'booked' => 0]]]];
$db->results['get_operation_context']['data']['availability'] = ['version' => 3, 'label' => 'Open now', 'preview' => []];
$page = $ui->page('station-admin', 'schedules')->body;
check(str_contains($page, 'Daily refill limits') && str_contains($page, '50 refills a day') && str_contains($page, 'action="/station-admin/schedules/capacity"')
    && str_contains($page, '<option value="2099-01-06">') && str_contains($page, 'This date only') && str_contains($page, '>Full<') && str_contains($page, '>38<'), 'Schedules page lists date limits, overrides, booked and left');

// Order queue: cancelled orders are rendered hidden (data-filter-only) and offered as a Status filter.
$row = static fn (string $id, string $no, string $status): array => ['id' => $id, 'order_no' => $no, 'status' => $status, 'fulfillment' => 'pickup', 'customer_name' => 'Lebron James', 'barangay' => null,
    'total_units' => 4, 'overdue' => false, 'score' => ['u' => 40, 'a' => 0, 'c' => 0, 'p' => 40], 'version' => 1];
$db->results['get_preparation_queue'] = ['ok' => true, 'data' => ['preparing' => [], 'queue' => [$row(KEY, 'HMT-0001', 'scheduled')], 'ready' => [], 'on_hold' => [], 'scheduled' => [], 'awaiting_completion' => [], 'reviews' => []]];
$db->results['get_cancelled_orders'] = ['ok' => true, 'data' => ['orders' => [$row(TRIP, 'HMT-0002', 'cancelled') + ['cancel_reason' => 'Changed plans', 'cancelled_at' => '2026-11-20T01:00:00Z']]]];
$db->results['get_station_order'] = ['ok' => false, 'error_code' => 'not_found', 'data' => []];
$page = $ui->page('station-admin', 'orders')->body;
check(str_contains($page, '<option value="Cancelled">Cancelled</option>') && preg_match('/<tr data-search-row data-filter-only hidden[^>]*data-filter-value="Cancelled"/', $page) === 1
    && str_contains($page, 'HMT-0002') && str_contains($page, 'Changed plans'), 'Cancelled orders hidden by default and offered in the Status filter');
$db->errors['get_cancelled_orders'] = new SupabaseException('missing', 404, 'PGRST202');
$page = $ui->page('station-admin', 'orders')->body;
check(str_contains($page, 'HMT-0001') && !str_contains($page, 'HMT-0002') && !str_contains($page, 'could not be loaded'), 'Queue still loads before the cancelled list exists');
unset($db->errors['get_cancelled_orders']);

$login('super-admin');
$db->results['get_platform_station_registry'] = ['ok' => true, 'data' => ['unique_driver_count' => 0, 'rows' => [
    ['id' => ST, 'name' => 'Open Station', 'barangay' => 'Guintas', 'registration_status' => 'approved', 'active' => true, 'registered_count' => 1, 'active_count' => 1, 'phone' => '+639170000001', 'created_at' => '2026-11-01T00:00:00Z'],
    ['id' => RID, 'name' => 'Paused Station', 'barangay' => 'Bia-an', 'registration_status' => 'suspended', 'active' => true, 'registered_count' => 0, 'active_count' => 0, 'phone' => '+639170000002', 'created_at' => '2026-11-01T00:00:00Z'],
    ['id' => TRIP, 'name' => 'Closed Station', 'barangay' => 'Apdo', 'registration_status' => 'archived', 'active' => false, 'registered_count' => 0, 'active_count' => 0, 'phone' => '+639170000003', 'created_at' => '2026-11-01T00:00:00Z'],
]]];
$page = $ui->page('super-admin', 'stations')->body;
check(substr_count($page, 'action="/super-admin/stations/status"') === 2 && str_contains($page, 'name="expected_status" value="suspended"') && str_contains($page, 'value="approved"')
    && str_contains($page, 'Remove station') && str_contains($page, 'Removed') && !str_contains($page, 'name="station_id" value="' . TRIP . '"'), 'Suspend/remove/reactivate offered for live stations only; removed ones are read-only');

$login('rider');
$db->results['get_trip'] = $trip([$stop(1, 'pending', 10.62, 122.01, 'Maria')]);
$db->results['get_trip']['data']['trip'] += ['trip_no' => 8, 'dispatched_at' => '2026-11-20T01:00:00Z', 'load_units' => 3, 'capacity' => 15];
$db->results['get_trip']['data']['stops'][0] += ['version' => 1, 'station_km' => 1.2, 'attempt' => null];
$db->results['get_trip']['data']['stops'][0]['order'] += ['address_text' => 'Purok 1', 'landmark' => '', 'contact_phone' => '+639171111111', 'total_units' => 3, 'expected_empties' => 3, 'amount_due_centavos' => 7000, 'items' => [], 'note' => ''];
$page = $ui->page('rider', 'trip')->body;
check(str_contains($page, 'data-route-map') && str_contains($page, 'data-route-url="/rider/trips/route"') && str_contains($page, 'Road map to this stop') && str_contains($page, 'leaflet.js'), 'Rider trip page shows the road map with Leaflet');

echo "$checks remaining-feature checks passed.\n";
