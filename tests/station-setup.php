<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/support/fakes.php';

use App\Controllers\StationSettingsController;
use App\Core\Session;
use App\Services\CurrentUser;
use App\Services\StationContext;
use App\Services\StationSetup;
use App\Services\SupabaseException;

check(class_exists(StationContext::class), 'checked station context service exists');
check(class_exists(StationSetup::class), 'station setup service exists');
check(class_exists(StationSettingsController::class), 'station settings controller exists');
$station = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$other = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
$product = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
$closure = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
$barangay = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
$key = 'ffffffff-ffff-4fff-8fff-ffffffffffff';
$projection = static fn (string $id = ''): array => [
    'actor' => ['id' => 'owner', 'roles' => ['station_admin']],
    'memberships' => [['station_id' => $id ?: $station, 'station_name' => 'Checked station', 'slug' => 'checked-station', 'role' => 'station_admin']],
    'station' => ['id' => $id ?: $station, 'name' => 'Checked station', 'slug' => 'checked-station', 'profile_version' => 4, 'location_version' => 5],
    'settings' => ['version' => 6], 'barangays' => [['id' => $barangay, 'name' => 'Bia-an', 'psgc_code' => 'x']],
    'service_areas' => [], 'products' => [['id' => $product, 'version' => 7]], 'opening_hours' => [],
    'closures' => [['id' => $closure, 'version' => 8]], 'availability' => ['version' => 9], 'fee_rules' => ['version' => 6, 'bands' => []],
];
$envelope = static fn (array $data): array => ['ok' => true, 'data' => $data, 'error_code' => null, 'field_errors' => []];
$db = new FakeGateway();
$db->results['get_operation_context'] = $envelope($projection());
$context = new StationContext($db);
check($context->load('user-token', $station)['station']['id'] === $station, 'context returns the requested checked station');
$context->load('user-token', $station);
check(count($db->called('rpc', 'get_operation_context')) === 1, 'successful context memoized for exact token/station');
$context->load('second-token', $station);
check(count($db->called('rpc', 'get_operation_context')) === 2, 'different tokens never share context');
try { $context->load('', $station); check(false, 'empty token refused'); } catch (RuntimeException) { check(true, 'empty context token refused'); }
$db->results['get_operation_context'] = $envelope($projection($other));
try { $context->load('third-token', $station); check(false, 'substitute station refused'); } catch (RuntimeException) { check(true, 'inaccessible station cannot silently substitute'); }
$db->results['get_operation_context'] = $envelope($projection());
check($context->load('third-token', $station)['station']['id'] === $station, 'failed contexts are never memoized');
$setup = new StationSetup($db);
$controller = new StationSettingsController($setup, new CurrentUser(new FakeAuth(), static fn (): int => 1000));
$login = static function (string $role = 'station-admin') use ($station): void {
    $_SESSION = [];
    Session::login(['id' => 'owner', 'role' => $role, 'station' => ['id' => $station, 'name' => 'Old', 'slug' => 'old']], ['access_token' => 'user-token', 'expires_at' => 2000000000]);
};
$post = static function (string $form, array $values = []) use ($key): void {
    $_POST = $values + ['_token' => Session::csrfToken(), 'request_key' => $key, 'expected_version' => '4', '_form' => $form];
};
$call = static function (string $method, array $values, string $rpc) use ($controller, $post, $db, $envelope, $station, $key): array {
    $db->results[$rpc] = $envelope(['station_id' => $station, 'version' => 10, 'availability_version' => 10]);
    $before = count($db->called('rpc', $rpc));
    $post($method, $values + ['station_id' => 'forged', 'actor_id' => 'forged', 'role' => 'super_admin']);
    $response = $controller->$method();
    check($response->status === 303 && ($_SESSION['flash']['type'] ?? '') === 'success', "$method redirects with success only after checked RPC");
    $calls = $db->called('rpc', $rpc);
    check(count($calls) === $before + 1 && end($calls)[3] === 'user-token', "$method uses current user token");
    $params = end($calls)[2];
    check(($params['p_station_id'] ?? $station) === $station && $params['p_request_key'] === $key, "$method uses session station and original request key");
    check(!isset($params['p_actor_id']) && !isset($params['p_role']), "$method ignores posted actor and role");
    return $params;
};
$login('super-admin'); $post('reopen');
check($controller->reopen()->headers['Location'] === '/login/station-admin' && $db->called('rpc', 'reopen_station') === [], 'wrong session role fails before mutation');
$login(); $post('reopen', ['_token' => 'wrong']); $controller->reopen();
check($db->called('rpc', 'reopen_station') === [], 'invalid CSRF fails before mutation');
unset($_SESSION['auth']); $post('reopen');
check($controller->reopen()->headers['Location'] === '/login/station-admin', 'missing current token asks for login');
$login();
foreach (['profile','location','serviceAreas','settings','fees','product','hours','closure','cancelClosure','reopen','switchStation'] as $method) {
    $before = count($db->calls);
    $post($method, ['_token'=>'bad']); $controller->$method();
    check(count($db->calls) === $before, "$method checks CSRF before any gateway calls");
}
foreach ([['request_key' => 'bad'], ['expected_version' => '-1'], ['expected_version' => ['4']]] as $invalid) {
    $post('reopen', $invalid); $controller->reopen();
    check($db->called('rpc', 'reopen_station') === [], 'invalid request UUID/version fails before mutation');
}
$params = $call('profile', ['name' => 'Water', 'contact_name' => 'Ana', 'phone' => '09171234567', 'email' => 'ANA@example.com', 'address' => 'Main road', 'barangay_id' => $barangay, 'description' => 'Fresh'], 'save_station_profile');
check(array_keys($params['p_payload']) === ['name','contact_name','phone','email','address','barangay_id','description'] && $params['p_expected_version'] === 4, 'profile payload allowlist and version');
$post('location', ['latitude' => '100', 'longitude' => '122']); $controller->location();
check($db->called('rpc', 'save_station_location') === [], 'invalid coordinates refused before RPC');
$params = $call('location', ['latitude' => '10.65', 'longitude' => '122.01', 'expected_version' => '5'], 'save_station_location');
check($params['p_latitude'] === '10.65' && $params['p_longitude'] === '122.01' && $params['p_expected_version'] === 5, 'location preserves validated decimals and location version');
$post('serviceAreas', ['barangay_ids' => 'wrong']); $controller->serviceAreas();
check($db->called('rpc', 'save_station_service_areas') === [], 'array parameter cannot be scalar');
$params = $call('serviceAreas', ['barangay_ids' => [$barangay], 'expected_version' => '6'], 'save_station_service_areas');
check($params['p_barangay_ids'] === [$barangay] && $params['p_expected_version'] === 6, 'service areas sends UUID array/settings version');
$params = $call('serviceAreas', ['barangay_ids'=>[], 'expected_version'=>'6'], 'save_station_service_areas');
check($params['p_barangay_ids'] === [], 'explicit empty served-area set is allowed');
$settings = ['max_order_units'=>'20','max_distance_km'=>'10','booking_days'=>'7','duplicate_minutes'=>'30','overdue_minutes'=>'120','institution_bonus_enabled'=>'0','urgency_asap'=>'40','urgency_soon'=>'25','urgency_later'=>'10','urgency_immediate_minutes'=>'60','urgency_soon_minutes'=>'180','aging_interval_minutes'=>'10','aging_points'=>'5','aging_cap'=>'25','institution_bonus'=>'10'];
$params = $call('settings', $settings + ['expected_version' => '6'], 'save_station_settings');
check(count($params['p_payload']) === 15 && $params['p_payload']['institution_bonus_enabled'] === false && $params['p_payload']['max_order_units'] === 20, 'settings uses all exact mutable field names and typed values');
$params = $call('settings', $settings + ['batch_capacity_load_units' => '15', 'daily_gallon_limit' => '', 'customer_cancel_minutes' => '20', 'expected_version' => '6'], 'save_station_settings');
check($params['p_payload']['batch_capacity_load_units'] === 15 && array_key_exists('daily_gallon_limit', $params['p_payload']) && $params['p_payload']['daily_gallon_limit'] === null
    && $params['p_payload']['customer_cancel_minutes'] === 20, 'batch size, blank daily limit (no limit) and cancel window are typed');
$params = $call('settings', $settings + ['batch_capacity_load_units' => '15', 'daily_gallon_limit' => '120', 'customer_cancel_minutes' => '15', 'expected_version' => '6'], 'save_station_settings');
check($params['p_payload']['daily_gallon_limit'] === 120, 'daily refill limit sent as a number');
$productForm = ['name'=>'Refill','pack_text'=>'5 gallons','description'=>'Clean','image_key'=>'water-jug','price'=>'123.45','returnable'=>'1','load_units'=>'1','active'=>'1'];
foreach (['1.001','1e2','-1','1,000','9999999999999999999'] as $bad) {
    $post('product', ['price'=>$bad] + $productForm); $controller->product();
    check($db->called('rpc', 'save_product') === [], 'unsafe pesos value refused: '.$bad);
}
$params = $call('product', $productForm, 'save_product');
check($params['p_product_id'] === null && $params['p_expected_version'] === null && $params['p_payload']['price_centavos'] === 12345 && !isset($params['p_payload']['default_code']), 'product creation uses exact integer money and null id/version');
$params = $call('product', ['product_id'=>$product,'expected_version'=>'7','active'=>'0'] + $productForm, 'save_product');
check($params['p_payload']['active'] === false && $params['p_expected_version'] === 7 && $params['p_payload']['image_key'] === 'water-jug' && $params['p_payload']['load_units'] === 1, 'edit/deactivate keeps complete product metadata');
$params = $call('product', ['image_key'=>'water-bottle','returnable'=>'0','load_units'=>'','price'=>'0.01'] + $productForm, 'save_product');
check($params['p_payload']['load_units'] === null && $params['p_payload']['price_centavos'] === 1, 'unconfigured bottle load remains null and one centavo stays exact');
$bands = [['min_km'=>'0','max_km'=>'1','min_inclusive'=>'1','max_inclusive'=>'0','fee'=>'5.00'],['min_km'=>'1','max_km'=>'3','min_inclusive'=>'1','max_inclusive'=>'1','fee'=>'10'],['min_km'=>'3','max_km'=>'','min_inclusive'=>'0','max_inclusive'=>'0','fee'=>'15']];
$params = $call('fees', ['bands'=>$bands,'max_distance_km'=>'10','expected_version'=>'6'], 'save_delivery_fee_rules');
check($params['p_bands'][0]['fee_centavos'] === 500 && $params['p_bands'][2]['max_km'] === null && $params['p_bands'][1]['max_inclusive'] === true, 'fee boundaries and money retain exact shape');
$params = $call('hours', ['windows'=>[['weekday'=>'1','window_no'=>'1','opens_at'=>'08:00','closes_at'=>'17:00','order_cutoff'=>'16:30','delivery_cutoff'=>'','enabled'=>'1']],'expected_version'=>'9'], 'save_station_hours');
check($params['p_windows'][0]['opens_at'] === '08:00:00' && $params['p_cutoffs'][0]['delivery_cutoff'] === null && $params['p_expected_version'] === 9, 'hours splits canonical windows/cutoffs and availability version');
$params = $call('hours', ['windows'=>[],'confirm_closed'=>'1','expected_version'=>'9'], 'save_station_hours');
check($params['p_windows'] === [] && $params['p_cutoffs'] === [], 'confirmed closed-all-week schedule sends two empty arrays');
$params = $call('closure', ['starts_at'=>'2026-11-20T08:00','ends_at'=>'2026-11-20T12:00','is_manual'=>'0','mode'=>'closed','public_notice'=>'Maintenance','closure_id'=>$closure,'closure_version'=>'8','expected_version'=>'9'], 'set_station_closure');
check(str_ends_with($params['p_interval']['starts_at'], '+08:00') && $params['p_interval']['closure_version'] === 8 && $params['p_expected_version'] === 9, 'closure converts Manila local time and carries both versions');
$params = $call('cancelClosure', ['closure_id'=>$closure,'reason'=>'Rescheduled','expected_version'=>'8'], 'cancel_station_closure');
check(!isset($params['p_station_id']) && $params['p_closure_id'] === $closure && $params['p_expected_version'] === 8, 'cancel uses exact closure RPC signature and closure version');
$params = $call('reopen', ['expected_version'=>'9'], 'reopen_station');
check($params['p_expected_version'] === 9, 'reopen uses availability version');
$invalidForms = [
    ['profile', ['name'=>['forged']], 'save_station_profile'],
    ['profile', ['name'=>'Water','contact_name'=>'Ana','phone'=>'bad','address'=>'Road','barangay_id'=>$barangay], 'save_station_profile'],
    ['serviceAreas', ['barangay_ids'=>[$other]], 'save_station_service_areas'],
    ['settings', ['booking_days'=>'8'] + $settings, 'save_station_settings'],
    ['product', ['product_id'=>$other] + $productForm, 'save_product'],
    ['product', ['image_key'=>'https://evil.test/x'] + $productForm, 'save_product'],
    ['fees', ['bands'=>[$bands[1],$bands[2]],'max_distance_km'=>'10'], 'save_delivery_fee_rules'],
    ['hours', ['windows'=>'forged'], 'save_station_hours'],
    ['hours', ['windows'=>[]], 'save_station_hours'],
    ['hours', ['windows'=>[['weekday'=>'7','window_no'=>'1','enabled'=>'1','opens_at'=>'08:00','closes_at'=>'17:00']]], 'save_station_hours'],
    ['hours', ['windows'=>[['weekday'=>'1','window_no'=>'1','enabled'=>'1','opens_at'=>'08:00','closes_at'=>'17:00','order_cutoff'=>'17:01']]], 'save_station_hours'],
    ['closure', ['starts_at'=>'2026-02-30T08:00','ends_at'=>'2026-03-01T08:00','mode'=>'closed'], 'set_station_closure'],
    ['closure', ['starts_at'=>'2026-11-20T08:00','ends_at'=>'2026-11-20T07:00','mode'=>'closed'], 'set_station_closure'],
    ['closure', ['starts_at'=>'2026-11-20T08:00','ends_at'=>'2026-11-20T12:00','mode'=>'closed','closure_id'=>$other,'closure_version'=>'8'], 'set_station_closure'],
    ['cancelClosure', ['closure_id'=>$other], 'cancel_station_closure'],
];
foreach ($invalidForms as [$method,$values,$rpc]) {
    $before = count($db->called('rpc', $rpc));
    $post($method,$values); $controller->$method();
    check(count($db->called('rpc',$rpc)) === $before && $_SESSION['flash']['type'] === 'error', "$method invalid values cannot reach mutation");
}
$params = $call('hours', ['windows'=>[['weekday'=>'0','window_no'=>'1','enabled'=>'0','opens_at'=>'','closes_at'=>'']],'confirm_closed'=>'1','expected_version'=>'9'], 'save_station_hours');
check($params['p_windows'] === [], 'disabled rest-day window ignores blank times');
$params = $call('closure', ['starts_at'=>'2020-01-01T00:00','ends_at'=>'','is_manual'=>'1','mode'=>'closed','expected_version'=>'9'], 'set_station_closure');
check($params['p_interval']['ends_at'] === null && $params['p_interval']['is_manual'] === true, 'manual indefinite closure sends explicit null expiration');
$db->results['save_product'] = ['ok'=>false,'data'=>[],'error_code'=>'stale_version','field_errors'=>['price'=>'raw secret','evil'=>'raw secret']];
$post('product', ['product_id'=>$product,'expected_version'=>'7','password'=>'SECRET'] + $productForm); $controller->product();
check($_SESSION['flash']['type'] === 'error' && !str_contains($_SESSION['flash']['message'], 'raw secret'), 'stale mutation cannot report success or expose database text');
check(($_SESSION['old']['request_key'] ?? null) === $key && !isset($_SESSION['old']['password'], $_SESSION['old']['_token']), 'retry preserves request key and safe old fields only');
check(!str_contains(json_encode($_SESSION['setup_errors'] ?? []), 'raw secret'), 'field errors contain controlled messages only');
$safeOld = StationSetup::oldInput('hours', ['windows'=>[['weekday'=>'1','password'=>'SECRET','token'=>'SECRET','enabled'=>'1']],'_token'=>'SECRET']);
check(!str_contains(json_encode($safeOld), 'SECRET') && $safeOld['windows'][0]['weekday'] === '1', 'nested old input preserves only form row fields');
$db->errors['save_product'] = new SupabaseException('private SQL data', 403, '42501');
$post('product', ['product_id'=>$product,'expected_version'=>'7'] + $productForm); $controller->product();
check($_SESSION['flash']['type'] === 'error' && !str_contains($_SESSION['flash']['message'], 'private SQL data'), 'forbidden reply is safe');
$db->errors['save_product'] = new RuntimeException('network secret');
$post('product', $productForm); $controller->product();
check($_SESSION['flash']['type'] === 'error' && $_SESSION['old']['request_key'] === $key, 'uncertain network outcome keeps retry key and never reports success');
unset($db->errors['save_product']);
$db->results['save_product'] = $envelope([]);
$post('product', $productForm); $controller->product();
check($_SESSION['flash']['type'] === 'error', 'malformed success envelope is an uncertain outcome');
$db->results['get_operation_context'] = $envelope($projection($other));
$post('switchStation', ['station_id'=>$other]); $response = $controller->switchStation();
check($response->status === 303 && Session::user()['station'] === ['id'=>$other,'name'=>'Checked station','slug'=>'checked-station'], 'station switching rechecks protected context and copies checked name/slug');
$db->results['get_operation_context'] = ['ok'=>false,'data'=>[],'error_code'=>'forbidden','field_errors'=>[]];
$controller = new StationSettingsController(new StationSetup($db), new CurrentUser(new FakeAuth(), static fn (): int => 1000));
$post('switchStation', ['station_id'=>$station]); $controller->switchStation();
check(Session::user()['station']['id'] === $other, 'failed station switch keeps prior checked station');
$bad = $projection(); $bad['actor']['roles'][] = 'super_admin';
$denied = new FakeGateway(); $denied->results['get_operation_context'] = $envelope($bad);
try { (new StationSetup($denied))->load('token', $station); check(false, 'super admin denied'); } catch (RuntimeException) { check(true, 'protected Super Admin role cannot access setup through injected membership'); }
check((bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', StationSetup::requestKey()), 'requestKey generates UUIDv4');
check(method_exists(StationSetup::class, 'choices'), 'owner setup exposes protected station choices');
$choicesDb = new FakeGateway();
$ownerChoice = ['station_id'=>$station,'station_name'=>'Own water station','slug'=>'own-water-station','role'=>'station_admin'];
$secondChoice = ['station_id'=>$other,'station_name'=>'Second own station','slug'=>'second-own-station','role'=>'station_admin'];
$riderChoice = ['station_id'=>$product,'station_name'=>'Rider-only station','slug'=>'rider-only-station','role'=>'rider'];
$choiceContext = ['actor'=>['id'=>'owner','roles'=>['station_admin','rider']], 'memberships'=>[$ownerChoice,$riderChoice,$secondChoice]];
$choicesDb->results['get_operation_context'] = $envelope($choiceContext);
$choicesService = new StationSetup($choicesDb);
check($choicesService->choices('choice-token') === [$ownerChoice,$secondChoice], 'station chooser filters rider memberships and preserves only owner choices');
$choiceCall = $choicesDb->called('rpc', 'get_operation_context')[0];
check($choiceCall[2] === ['p_station_id'=>null] && $choiceCall[3] === 'choice-token', 'station choices uses null-station protected RPC with current user token');
$mixedContext = $projection(); $mixedContext['memberships'] = [$ownerChoice,$riderChoice,$secondChoice];
$choicesDb->results['get_operation_context'] = $envelope($mixedContext);
check($choicesService->load('choice-token', $station)['memberships'] === [$ownerChoice,$secondChoice], 'loaded owner setup excludes rider-only sidebar choices');
foreach ([['station_admin','super_admin'],['rider'],[]] as $index => $roles) {
    $choiceContext['actor']['roles'] = $roles;
    $choicesDb->results['get_operation_context'] = $envelope($choiceContext);
    try { $choicesService->choices('denied-choice-'.$index); check(false, 'unauthorized chooser denied'); } catch (RuntimeException) { check(true, 'chooser denies Super Admin and missing global owner role'); }
}
$choiceContext['actor']['roles'] = ['station_admin'];
foreach ([['station_id'=>'invalid'] + $ownerChoice, ['station_name'=>[]] + $ownerChoice, ['slug'=>''] + $ownerChoice, 'invalid-row'] as $index => $badChoice) {
    $choiceContext['memberships'] = [$badChoice];
    $choicesDb->results['get_operation_context'] = $envelope($choiceContext);
    try { $choicesService->choices('malformed-choice-'.$index); check(false, 'malformed chooser denied'); } catch (RuntimeException) { check(true, 'chooser rejects malformed membership projection'); }
}
$choiceContext['memberships'] = [];
$choicesDb->results['get_operation_context'] = $envelope($choiceContext);
check($choicesService->choices('empty-choice-token') === [], 'authorized owner with no accessible stations gets an empty safe chooser');
echo "$checks station setup assertions passed.\n";
