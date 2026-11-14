<?php
// Unit tests: station registration, Super Admin approval, owner invitations, driver accounts, role logins,
// the set-password page and database-backed pages. No network: Supabase and SMTP are fakes.
// Run: php tests/registry.php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
require __DIR__.'/support/fakes.php';

use App\Controllers\AuthController;
use App\Controllers\RegistrationController;
use App\Controllers\RegistryController;
use App\Controllers\StationController;
use App\Controllers\UIController;
use App\Core\Session;
use App\Services\CurrentUser;
use App\Services\Invitations;
use App\Services\Registry;
use App\Services\StationDirectory;
use App\Services\StationSetup;
use App\Services\SupabaseException;

$tz = new DateTimeZone('Asia/Manila');
$at = static fn (string $when): DateTimeImmutable => new DateTimeImmutable($when, $tz);
$row = static fn (array $over = []): array => $over + ['id' => '11111111-1111-4111-8111-111111111111', 'slug' => 'guintas', 'name' => 'Guintas Water Station', 'barangay' => 'Guintas',
    'address' => 'National Road', 'phone' => '+639170000110', 'email' => '', 'registration_status' => 'approved', 'active' => true,
    'weekday_open' => '07:00:00', 'weekday_close' => '18:00:00', 'sunday_open' => '08:00:00', 'sunday_close' => '16:00:00', 'closed_notice' => null, 'created_at' => '2026-11-14T02:00:00+00:00'];
$flash = static fn (): array => Session::takeFlash() ?? ['type' => '', 'message' => ''];
$post = static function (array $fields): void { $_POST = $fields + ['_token' => Session::csrfToken()]; };
$station = ['id' => '11111111-1111-4111-8111-111111111111', 'name' => 'Guintas Water Station', 'slug' => 'guintas'];

// --- Station hours shown on the landing page (Nov 14, 2026 is a Saturday) ---
$open = StationDirectory::present($row(), $at('2026-11-14 10:00'));
check($open['id'] === 'guintas' && $open['status'] === 'Open until 6:00 PM' && $open['available'] && $open['tone'] === 'green', 'open during weekday hours');
check($open['hours'] === '7:00 AM – 6:00 PM' && $open['sunday'] === '8:00 AM – 4:00 PM', 'hours formatted for the card');
check(StationDirectory::present($row(), $at('2026-11-14 06:30'))['status'] === 'Opens today at 7:00 AM', 'before opening');
$evening = StationDirectory::present($row(), $at('2026-11-14 18:00'));
check($evening['status'] === 'Opens tomorrow at 8:00 AM' && !$evening['available'] && $evening['next'] === 'Next available: November 15, 2026 at 8:00 AM.', 'closing time is exclusive; Sunday hours used tomorrow');
$poblacion = StationDirectory::present($row(['slug' => 'poblacion', 'sunday_open' => null, 'sunday_close' => null, 'weekday_close' => '17:00:00']), $at('2026-11-14 17:30'));
check($poblacion['status'] === 'Opens on Monday at 7:00 AM' && $poblacion['sunday'] === 'Closed', 'closed Sundays skip to Monday');
$maintenance = StationDirectory::present($row(['closed_notice' => 'Under maintenance']), $at('2026-11-14 10:00'));
check($maintenance['status'] === 'Under maintenance' && $maintenance['tone'] === 'amber' && !$maintenance['available'], 'maintenance notice overrides hours');
$db = new FakeGateway();
$db->tables['stations'] = [$row(), $row(['id' => '22222222-2222-4222-8222-222222222222', 'slug' => 'riverside', 'name' => 'Riverside Water Station', 'closed_notice' => 'Under maintenance'])];
$db->results['list_station_availability'] = ['ok'=>true, 'data'=>['stations'=>array_map(static fn (array $station): array => ['station_id'=>$station['id'], 'label'=>'Open', 'accepting_asap'=>true], $db->tables['stations'])]];
check(array_column((new StationDirectory($db))->approved($at('2026-11-14 10:00')), 'id') === ['guintas', 'riverside'] && $db->calls[0][3] === null, 'directory reads stations with the visitor key');
$down = new FakeGateway();
$down->errors['stations'] = new RuntimeException('Supabase is temporarily unreachable.');
check((new StationDirectory($down))->approved() === [], 'directory shows nothing instead of failing when Supabase is down');

// --- Public registration form ---
$_SESSION = [];
$db = new FakeGateway();
$db->results['submit_station_registration'] = ['value' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'];
$registration = new RegistrationController(new Registry($db));
$form = ['station_name' => 'Bia-an Fresh Water', 'business_phone' => '0917 111 2233', 'business_email' => '', 'barangay' => 'Bia-an', 'business_address' => 'Purok 1, near the chapel',
    'administrator_name' => 'Elena Ramos', 'administrator_email' => 'Elena@Example.com', 'administrator_mobile' => '09181112233', 'business_role' => 'Owner', 'authorized' => 'yes'];
$_POST = $form;
$registration->submit();
check($db->calls === [] && $flash()['type'] === 'error', 'registration without CSRF token sends nothing');
$post(['business_phone' => '12345'] + $form);
$registration->submit();
check($db->calls === [] && str_contains($flash()['message'], 'business number'), 'invalid phone rejected before the database');
$post($form);
$response = $registration->submit();
$call = $db->called('rpc', 'submit_station_registration')[0] ?? null;
check($call !== null && $call[3] === null && $call[2]['p_station_phone'] === '+639171112233' && $call[2]['p_owner_email'] === 'elena@example.com', 'registration saved through the public RPC with normalized values');
check($response->headers['Location'] === '/register-station' && $flash()['type'] === 'success' && $_SESSION['registration_submitted']['station'] === 'Bia-an Fresh Water', 'success page shown after submitting');
$db->errors['submit_station_registration'] = new SupabaseException('Supabase request failed with HTTP 409.', 409, '23505');
$post($form);
$registration->submit();
check(str_contains($flash()['message'], 'already waiting for review'), 'duplicate pending registration explained');
check((new UIController(false, new StationDirectory(new FakeGateway())))->registration()->status === 200, 'registration page renders');

// --- Super Admin decisions ---
$auth = new FakeAuth();
$mailer = new FakeMailer();
$db = new FakeGateway();
$registry = new Registry($db);
$invitations = new Invitations($auth, $registry, $mailer, 'http://localhost:8000');
$current = new CurrentUser($auth);
$decisions = new RegistryController($registry, $invitations, $current);
$application = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
$newStation = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
$auth->createConfirmedUser('admin@hydroroute.test', 'adminpass1', ['full_name' => 'Registry Admin']);
$auth->grant('admin@hydroroute.test', 'super_admin');
$_SESSION = [];
$post(['id' => $application, 'decision' => 'approve']);
$response = $decisions->decide();
check($response->headers['Location'] === '/login/super-admin' && $db->calls === [], 'decisions need a Super Admin login');
Session::login(['id' => 'user-0', 'email' => 'admin@hydroroute.test', 'name' => 'Registry Admin', 'role' => 'super-admin', 'station' => null], ['access_token' => 'token-user-0', 'refresh_token' => 'refresh-user-0', 'expires_at' => 2_000_000_000]);
$post(['id' => $application, 'decision' => 'reject', 'reason' => '']);
$decisions->decide();
check($db->calls === [] && str_contains($flash()['message'], 'reason'), 'rejecting needs a reason');
$db->results['review_station_registration'] = ['status' => 'approved', 'station_id' => $newStation, 'station_name' => 'Bia-an Fresh Water', 'owner_email' => 'elena@example.com', 'owner_name' => 'Elena Ramos'];
$post(['id' => $application, 'decision' => 'approve']);
$response = $decisions->decide();
$review = $db->called('rpc', 'review_station_registration')[0];
check($review[3] === 'token-user-0' && $review[2] === ['p_id' => $application, 'p_decision' => 'approve', 'p_reason' => null], 'approval runs as the Super Admin user');
$provision = $db->called('admin', 'provision_station_member')[0];
check($provision[2] === ['p_user_id' => $auth->users['elena@example.com']['id'], 'p_station_id' => $newStation, 'p_role' => 'station_admin'], 'owner account linked to the new station with the secret key');
$invite = $mailer->invites[0];
check($invite['to'] === 'elena@example.com' && str_contains($invite['intro'], 'Bia-an Fresh Water') && str_starts_with($invite['link'], 'http://localhost:8000/account/setup?token_hash=hash') && str_contains($invite['link'], 'type=invite'), 'owner emailed a set-password link');
check($response->headers['Location'] === '/super-admin/application?id='.$application && $flash()['type'] === 'success', 'approval toast');
$db->errors['review_station_registration'] = new SupabaseException('Supabase request failed with HTTP 400.', 400, '55000');
$post(['id' => $application, 'decision' => 'approve']);
$decisions->decide();
check($flash()['message'] === 'This application was already reviewed.' && count($mailer->invites) === 1, 'second review refused without another invitation');
unset($db->errors['review_station_registration']);
$db->tables['station_registrations'] = [['id' => $application, 'status' => 'approved', 'station_id' => $newStation, 'station_name' => 'Bia-an Fresh Water', 'owner_email' => 'elena@example.com', 'owner_name' => 'Elena Ramos']];
$post(['id' => $application]);
$decisions->resendInvite();
check(count($mailer->invites) === 2 && str_contains($mailer->invites[1]['link'], 'type=recovery') && $flash()['type'] === 'success', 'invitation can be sent again');
$mailer->fail = true;
$post(['id' => $application]);
$decisions->resendInvite();
check($flash()['type'] === 'warning', 'failed email reported as a warning');
$mailer->fail = false;

// --- Logins check the role in the database ---
$login = new AuthController($auth, $mailer);
$_SESSION = [];
$auth->createConfirmedUser('maria@example.com', 'customer1', ['full_name' => 'Maria Santos']);
$post(['email' => 'maria@example.com', 'password' => 'customer1']);
$response = $login->login('station-admin');
check(Session::user() === null && in_array('token-'.$auth->users['maria@example.com']['id'], $auth->signedOut, true) && $flash()['message'] === 'Wrong email or password.', 'customer cannot open the station admin dashboard');
$post(['email' => 'admin@hydroroute.test', 'password' => 'adminpass1']);
$login->login('customer');
check(Session::user() === null && $flash()['message'] === 'Wrong email or password.', 'Super Admin cannot use customer ordering');
$post(['email' => 'admin@hydroroute.test', 'password' => 'adminpass1']);
$response = $login->login('super-admin');
check(Session::user()['role'] === 'super-admin' && $response->headers['Location'] === '/super-admin', 'Super Admin logs in');

// --- Owner opens the email link and sets a password ---
$_SESSION = [];
$auth->grant('elena@example.com', 'station_admin', ['id' => $newStation, 'name' => 'Bia-an Fresh Water', 'slug' => 'bia-an-fresh-water']);
parse_str((string) parse_url($mailer->invites[0]['link'], PHP_URL_QUERY), $link);
$_GET = ['token_hash' => 'x', 'type' => 'invite'];
check($login->setupForm()->headers['Location'] === '/login', 'broken link refused');
$_GET = $link;
$page = $login->setupForm();
check($page->status === 200 && str_contains($page->body, 'value="'.$link['token_hash'].'"'), 'set-password page keeps the token for the form');
$post($link + ['password' => 'stationpass1', 'confirm_password' => 'different1']);
$login->setup();
check(isset($auth->links[$link['token_hash']]) && $flash()['message'] === 'Passwords do not match.', 'mismatch does not use up the link');
$post($link + ['password' => 'stationpass1', 'confirm_password' => 'stationpass1']);
$response = $login->setup();
check($auth->users['elena@example.com']['password'] === 'stationpass1' && !isset($auth->links[$link['token_hash']]), 'password saved and link used up');
check(Session::user()['role'] === 'station-admin' && Session::user()['station']['id'] === $newStation && $response->headers['Location'] === '/station-admin', 'owner lands on their station dashboard');
check(str_contains($flash()['message'], 'Welcome, Elena'), 'welcome toast after setting the password');
$post($link + ['password' => 'stationpass1', 'confirm_password' => 'stationpass1']);
$login->setup();
check(str_contains($flash()['message'], 'expired or was already used'), 'a link works only once');
$_SESSION = [];
$post(['email' => 'elena@example.com', 'password' => 'stationpass1']);
$response = $login->login('station-admin');
check(Session::user()['station']['name'] === 'Bia-an Fresh Water' && $response->headers['Location'] === '/station-admin', 'owner logs in with the new password');

// --- Station admin adds a driver with a password (no email) ---
$db->results['get_operation_context'] = ['ok'=>true, 'data'=>[
    'actor'=>['id'=>Session::user()['id'], 'roles'=>['station_admin']],
    'memberships'=>[['station_id'=>$newStation, 'station_name'=>'Bia-an Fresh Water', 'slug'=>'bia-an-fresh-water', 'role'=>'station_admin']],
    'station'=>['id'=>$newStation, 'name'=>'Bia-an Fresh Water', 'slug'=>'bia-an-fresh-water'],
    'settings'=>[], 'barangays'=>[], 'service_areas'=>[], 'products'=>[], 'opening_hours'=>[],
    'closures'=>[], 'availability'=>[], 'fee_rules'=>[],
]];
$stations = new StationController($auth, $registry, $current, new StationSetup($db));
$invitesBefore = count($mailer->invites);
$driverForm = ['first_name' => 'Juan', 'last_name' => 'Reyes', 'phone' => '0917 555 0101', 'email' => 'Juan.Reyes@Example.com', 'vehicle' => 'Tricycle', 'plate' => 'abc 1234', 'capacity' => '15', 'password' => 'rider-pass1', 'confirm_password' => 'rider-pass1', 'verified' => 'yes'];
$savedAuth = $_SESSION['auth'];
unset($_SESSION['auth']);
$post(['email'=>'missing-token@example.com'] + $driverForm);
$stations->addDriver();
check(!isset($auth->users['missing-token@example.com']) && $flash()['type'] === 'info', 'missing current token prevents creating an Auth identity');
$_SESSION['auth'] = $savedAuth;
$db->errors['get_operation_context'] = new RuntimeException('Scoped access revoked.');
$post(['email'=>'revoked-owner@example.com'] + $driverForm);
$stations->addDriver();
check(!isset($auth->users['revoked-owner@example.com']) && $flash()['type'] === 'error', 'revoked protected membership prevents creating an Auth identity');
unset($db->errors['get_operation_context']);
$post(['capacity' => '500'] + $driverForm);
$stations->addDriver();
check($db->called('admin', 'register_driver') === [] && str_contains($flash()['message'], 'capacity'), 'capacity limits checked');
$retryPage = (new UIController(false, new StationDirectory($db), $registry, $current, new StationSetup($db)))->page('station-admin', 'add-driver');
check(str_contains($retryPage->body, 'value="500"') && str_contains($retryPage->body, 'Juan.Reyes@Example.com'), 'add-driver error redirect retains submitted nonsecret fields');
$post(['confirm_password' => 'different1'] + $driverForm);
$stations->addDriver();
check(!isset($auth->users['juan.reyes@example.com']) && $flash()['message'] === 'Passwords do not match.', 'password mismatch creates no account');
$post(['password' => 'short', 'confirm_password' => 'short'] + $driverForm);
$stations->addDriver();
check(!isset($auth->users['juan.reyes@example.com']) && str_contains($flash()['message'], '8 characters'), 'short password refused');
$post($driverForm);
$response = $stations->addDriver();
check(($auth->users['juan.reyes@example.com']['password'] ?? null) === 'rider-pass1' && $auth->users['juan.reyes@example.com']['user_metadata'] === ['full_name' => 'Juan Reyes', 'phone' => '+639175550101'], 'driver account created with the typed password');
$register = $db->called('admin', 'register_driver')[0][2];
check($register['p_user_id'] === $auth->users['juan.reyes@example.com']['id'] && $register['p_station_id'] === $newStation && $register['p_created_by'] === Session::user()['id'] && $register['p_plate'] === 'ABC 1234' && $register['p_email'] === 'juan.reyes@example.com', 'driver registered to the admin’s own station');
check(count($mailer->invites) === $invitesBefore, 'no email is sent to the driver');
check($response->headers['Location'] === '/station-admin/drivers' && $flash()['message'] === 'Juan Reyes can now log in at Rider login with juan.reyes@example.com and the password you set.', 'toast tells the admin what to hand over, never the password');
$post($driverForm);
$stations->addDriver();
check(str_contains($flash()['message'], 'already has a HydroRoute account') && count($db->called('admin', 'register_driver')) === 1, 'existing email is not taken over');
$db->errors['register_driver'] = new SupabaseException('Supabase request failed with HTTP 403.', 403, '42501');
$post(['email' => 'pedro@example.com'] + $driverForm);
$stations->addDriver();
check(!isset($auth->users['pedro@example.com']) && str_contains($flash()['message'], 'could not add the driver'), 'failed station link removes the half-made account');
unset($db->errors['register_driver']);
$auth->grant('juan.reyes@example.com', 'rider', ['id' => $newStation, 'name' => 'Bia-an Fresh Water', 'slug' => 'bia-an-fresh-water']);
$elena = $_SESSION;
$_SESSION = [];
$post(['email' => 'juan.reyes@example.com', 'password' => 'rider-pass1']);
$response = $login->login('rider');
check(Session::user()['role'] === 'rider' && Session::user()['station']['id'] === $newStation && $response->headers['Location'] === '/rider', 'driver logs in with the email and password from the admin');
$_SESSION = [];
$post(['email' => 'juan.reyes@example.com', 'password' => 'rider-pass1']);
$response = $login->login('customer');
check(Session::user() === null && $response->headers['Location'] === '/login/customer' && $flash()['message'] === 'Wrong email or password.', 'driver account is refused on the customer login');
$post(['email' => 'juan.reyes@example.com', 'password' => 'rider-pass1']);
$login->login('station-admin');
check(Session::user() === null && $flash()['message'] === 'Wrong email or password.', 'driver account is refused on the owner login');
$_SESSION = $elena;
$db->tables['drivers'] = [['user_id' => $auth->users['juan.reyes@example.com']['id'], 'station_id' => $newStation, 'full_name' => 'Juan Reyes', 'phone' => '+639175550101', 'email' => 'juan.reyes@example.com', 'vehicle' => 'Tricycle', 'plate_number' => 'ABC 1234', 'capacity' => 15, 'active' => true, 'created_at' => '2026-11-14T03:00:00+00:00']];
$juan = $auth->users['juan.reyes@example.com']['id'];
$post(['user_id' => $juan, 'password' => 'new-pass-22', 'confirm_password' => 'other-pass-22']);
$stations->setDriverPassword();
check($auth->users['juan.reyes@example.com']['password'] === 'rider-pass1' && $flash()['message'] === 'Passwords do not match.', 'mismatched new password refused');
$post(['user_id' => $juan, 'password' => 'new-pass-22', 'confirm_password' => 'new-pass-22']);
$db->results['authorize_driver_credentials'] = ['ok'=>true, 'data'=>['allowed'=>true]];
$stations->setDriverPassword();
check($auth->users['juan.reyes@example.com']['password'] === 'new-pass-22' && $flash()['message'] === 'Password changed. Give Juan Reyes the new password for juan.reyes@example.com.', 'admin sets a new password for their own driver');
$db->results['authorize_driver_credentials'] = ['ok'=>true, 'data'=>['allowed'=>false]];
$post(['user_id' => $juan, 'password' => 'blocked-pass-22', 'confirm_password' => 'blocked-pass-22']);
$stations->setDriverPassword();
check($auth->users['juan.reyes@example.com']['password'] === 'new-pass-22' && str_contains($flash()['message'], 'administrator-managed'), 'protected shared driver identity cannot have credentials changed by station owner');
$post(['user_id' => $auth->users['maria@example.com']['id'], 'password' => 'hijack-pass1', 'confirm_password' => 'hijack-pass1']);
$stations->setDriverPassword();
check($auth->users['maria@example.com']['password'] === 'customer1' && str_contains($flash()['message'], 'not registered to your station'), 'cannot change the password of an account outside the station');
$saved = $_SESSION;
$_SESSION = [];
$post(['user_id' => $juan, 'password' => 'new-pass-33', 'confirm_password' => 'new-pass-33']);
check($stations->setDriverPassword()->headers['Location'] === '/login/station-admin' && $auth->users['juan.reyes@example.com']['password'] === 'new-pass-22', 'password changes need a station admin login');
$_SESSION = $saved;

// --- Database-backed pages ---
$db->results['get_operation_context'] = ['ok'=>true, 'data'=>[
    'actor'=>['id'=>Session::user()['id'], 'roles'=>['station_admin']],
    'memberships'=>[['station_id'=>$newStation, 'station_name'=>'Bia-an Fresh Water', 'slug'=>'bia-an-fresh-water', 'role'=>'station_admin']],
    'station'=>['id'=>$newStation, 'name'=>'Bia-an Fresh Water', 'slug'=>'bia-an-fresh-water'],
    'settings'=>[], 'barangays'=>[], 'service_areas'=>[], 'products'=>[], 'opening_hours'=>[],
    'closures'=>[], 'availability'=>[], 'fee_rules'=>[],
]];
$ui = new UIController(false, new StationDirectory($db), $registry, $current, new StationSetup($db));
$page = $ui->page('station-admin', 'drivers');
check($page->status === 200 && str_contains($page->body, 'Juan Reyes') && str_contains($page->body, 'juan.reyes@example.com') && !str_contains($page->body, 'Carlo Diaz'), 'drivers page lists database drivers, not samples');
check(str_contains($page->body, 'action="/station-admin/drivers/password"') && str_contains($page->body, 'data-dialog-open="driver-password-'), 'each driver has a Set password dialog');
check(str_contains($page->body, 'Bia-an Fresh Water') && !str_contains($page->body, 'Guintas Water Station'), 'station admin sees their own station name');
check(str_contains($ui->page('station-admin', 'add-driver')->body, 'action="/station-admin/drivers"'), 'add driver form posts to the server');
$_SESSION = [];
check($ui->page('station-admin', 'overview')->headers['Location'] === '/login/station-admin', 'station admin pages need a login');
Session::login(['id' => 'user-0', 'email' => 'admin@hydroroute.test', 'name' => 'Registry Admin', 'role' => 'super-admin', 'station' => null], ['access_token' => 'token-user-0', 'refresh_token' => 'refresh-user-0', 'expires_at' => 2_000_000_000]);
$db->tables['station_registrations'] = [['id' => $application, 'station_name' => 'Malandog Clear Water', 'barangay' => 'Malandog', 'address' => 'Purok 3', 'station_phone' => '+639171112233', 'station_email' => '', 'owner_name' => 'Roberto Perez', 'owner_email' => 'roberto@example.com', 'owner_phone' => '+639181112233', 'owner_role' => 'Owner', 'status' => 'pending', 'decision_reason' => null, 'station_id' => null, 'reviewed_at' => null, 'created_at' => '2026-11-14T04:00:00+00:00']];
$db->tables['stations'] = [$row()];
$db->results['get_platform_station_registry'] = ['ok'=>true, 'data'=>['rows'=>[$row() + ['registered_count'=>1, 'active_count'=>1]], 'unique_driver_count'=>1]];
$db->results['list_station_availability'] = ['ok'=>true, 'data'=>['stations'=>[['station_id'=>$row()['id'], 'label'=>'Open', 'accepting_asap'=>true]]]];
$list = $ui->page('super-admin', 'applications');
check(str_contains($list->body, 'Malandog Clear Water') && str_contains($list->body, 'REG-BBBBBB') && !str_contains($list->body, 'Bongbongan Fresh Water'), 'applications page lists database registrations');
$_GET = ['id' => $application];
$detail = $ui->page('super-admin', 'application');
check(str_contains($detail->body, 'action="/super-admin/application/decision"') && str_contains($detail->body, 'roberto@example.com') && str_contains($detail->body, '0917 111 2233'), 'application page has the real decision form');
$overview = $ui->page('super-admin', 'overview');
check(str_contains($overview->body, 'Guintas Water Station') && str_contains($overview->body, 'Malandog Clear Water'), 'overview shows stations and the pending application');
$home = $ui->home();
check(str_contains($home->body, 'Guintas Water Station') && !str_contains($home->body, 'Poblacion Water Station') && !str_contains($home->body, 'Sample stations for the UI preview'), 'landing page lists database stations only');

// --- Access tokens refresh before they expire ---
$_SESSION['auth'] = ['access_token' => 'old', 'refresh_token' => 'refresh-user-0', 'expires_at' => 100];
check((new CurrentUser($auth, static fn (): int => 1000))->token() === 'token-user-0' && $_SESSION['auth']['expires_at'] === 2_000_000_000, 'expired access token refreshed');
$_SESSION['auth'] = ['access_token' => 'old', 'refresh_token' => 'revoked', 'expires_at' => 100];
check((new CurrentUser($auth, static fn (): int => 1000))->token() === null && Session::user() === null, 'revoked session logs the user out');

echo "$checks registry assertions passed.\n";
