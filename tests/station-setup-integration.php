<?php
declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';
require __DIR__.'/support/fakes.php';

use App\Services\Registry;
use App\Services\StationDirectory;
use App\Services\StationSetup;
use App\Services\CurrentUser;
use App\Controllers\AuthController;
use App\Controllers\UIController;
use App\Controllers\StationController;
use App\Core\Session;

$id = '11111111-1111-4111-8111-111111111111';
$db = new FakeGateway();
$db->tables['stations'] = [[
    'id'=>$id, 'slug'=>'real-station', 'name'=>'Real Station', 'barangay'=>'Guintas',
    'address'=>'National Road', 'phone'=>'+639171112233',
    'weekday_open'=>'07:00:00', 'weekday_close'=>'18:00:00',
    'sunday_open'=>null, 'sunday_close'=>null,
]];
$db->results['list_station_availability'] = ['ok'=>true, 'data'=>['stations'=>[[
    'station_id'=>$id, 'accepting_asap'=>false, 'can_book'=>true,
    'status_key'=>'closed', 'label'=>'Closed until Monday',
    'next_open_at'=>'2026-11-16T07:00:00+08:00', 'next_close_at'=>null,
]]], 'error_code'=>null, 'field_errors'=>[]];
$stations = (new StationDirectory($db))->approved();
check(count($db->called('rpc', 'list_station_availability')) === 1, 'live directory reads database availability');
check($stations[0]['status'] === 'Closed until Monday' && !$stations[0]['available'], 'database availability controls public status');
check($db->called('rpc', 'list_station_availability')[0][3] === null, 'public availability uses visitor authority');
$db->errors['list_station_availability'] = new RuntimeException('unavailable');
check((new StationDirectory($db))->approved() === [], 'directory failure has an honest empty result');

$registry = new Registry($db);
$db->results['get_platform_station_registry'] = ['ok'=>true, 'data'=>['rows'=>[], 'unique_driver_count'=>3]];
check($registry->platformRegistry('owner-token')['unique_driver_count'] === 3, 'platform registry uses bounded count RPC');
check($db->called('select', 'drivers') === [], 'platform count never reads driver identities');
$db->results['authorize_driver_credentials'] = ['ok'=>true, 'data'=>['allowed'=>true]];
check($registry->canManageDriverCredentials('owner-token', $id, $id), 'credential guard accepts explicit authorization');
$db->results['authorize_driver_credentials'] = ['ok'=>true, 'data'=>['allowed'=>false]];
check(!$registry->canManageDriverCredentials('owner-token', $id, $id), 'credential guard refuses protected identities');
$db->results['authorize_driver_credentials'] = [];
check(!$registry->canManageDriverCredentials('owner-token', $id, $id), 'credential guard fails closed on incomplete response');

$auth = new FakeAuth();
$owner = $auth->createConfirmedUser('owner@example.test', 'owner-password1', ['full_name'=>'Real Owner']);
$second = '22222222-2222-4222-8222-222222222222';
$auth->grant('owner@example.test', 'station_admin', ['id'=>$id, 'name'=>'First Station', 'slug'=>'first']);
$auth->grant('owner@example.test', 'station_admin', ['id'=>$second, 'name'=>'Second Station', 'slug'=>'second']);
$_SESSION = [];
$_POST = ['_token'=>Session::csrfToken(), 'email'=>'owner@example.test', 'password'=>'owner-password1'];
(new AuthController($auth, new FakeMailer()))->login('station-admin');
check(Session::user()['station'] === null, 'multiple owner stations require an explicit workspace choice after login');
$db = new FakeGateway();
$db->results['get_operation_context'] = ['ok'=>true, 'data'=>[
    'actor'=>['id'=>$owner['id'], 'roles'=>['station_admin']],
    'memberships'=>[
        ['station_id'=>$id, 'station_name'=>'First Station', 'slug'=>'first', 'role'=>'station_admin'],
        ['station_id'=>$second, 'station_name'=>'Second Station', 'slug'=>'second', 'role'=>'station_admin'],
    ],
]];
$ui = new UIController(false, new StationDirectory($db), new Registry($db), new CurrentUser($auth), new StationSetup($db));
$page = $ui->page('station-admin', 'products');
check($page->status === 200 && str_contains($page->body, 'action="/station-admin/switch-station"'), 'unselected owner gets a checked workspace picker');
check(str_contains($page->body, 'Second Station') && !str_contains($page->body, 'Guintas Water Station'), 'picker contains checked memberships without fixture identity');
check(count($db->calls) === 1 && $db->calls[0][2]['p_station_id'] === null, 'choosing workspace does not load operational data before selection');
$callsBefore = count($db->calls);
$_POST = ['_token'=>Session::csrfToken(), 'user_id'=>$id, 'password'=>'new-password1', 'confirm_password'=>'new-password1'];
$response = (new StationController($auth, new Registry($db), new CurrentUser($auth), new StationSetup($db)))->setDriverPassword();
check($response->headers['Location'] === '/station-admin' && count($db->calls) === $callsBefore, 'unselected station cannot access driver password management');
$db->results['get_operation_context']['data']['memberships'] = [];
$emptyUi = new UIController(false, new StationDirectory($db), new Registry($db), new CurrentUser($auth), new StationSetup($db));
$page = $emptyUi->page('station-admin', 'products');
check(str_contains($page->body, 'Station workspace unavailable') && !str_contains($page->body, 'Guintas Water Station'), 'unlinked owner sees a neutral unavailable workspace without fixture station identity');

echo "$checks station setup integration assertions passed.\n";
