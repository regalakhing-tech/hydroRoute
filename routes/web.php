<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\HomeController;
use App\Controllers\RegistrationController;
use App\Controllers\RegistryController;
use App\Controllers\StationController;
use App\Controllers\StationSettingsController;
use App\Controllers\UIController;
use App\UI\Pages;
use App\Controllers\SystemController;
use App\Core\Router;
use App\Services\CurrentUser;
use App\Services\Invitations;
use App\Services\Mailer;
use App\Services\Registry;
use App\Services\StationDirectory;
use App\Services\StationContext;
use App\Services\StationSetup;
use App\Services\SupabaseClient;

return static function (Router $router, array $config): void {
    $home = new HomeController($config);
    $supabase = new SupabaseClient($config['supabase']);
    $mailer = new Mailer($config['mail'], dirname(__DIR__) . '/public/assets/images/logo.png');
    $registry = new Registry($supabase);
    $current = new CurrentUser($supabase);
    $invitations = new Invitations($supabase, $registry, $mailer, $config['app']['url']);
    $system = new SystemController($supabase);
    $auth = new AuthController($supabase, $mailer);
    $registration = new RegistrationController($registry);
    $registryAdmin = new RegistryController($registry, $invitations, $current);
    $stationContext = new StationContext($supabase);
    $stationSetup = new StationSetup($supabase, $stationContext);
    $station = new StationController($supabase, $registry, $current, $stationSetup);
    $stationSettings = new StationSettingsController($stationSetup, $current);

    $ui = new UIController($config['app']['preview'], new StationDirectory($supabase), $registry, $current, $stationSetup);
    $router->get('/', [$ui, 'home']);
    $router->get('/thesis', [$home, 'index']);
    $router->get('/login', static fn () => $ui->login());
    foreach (array_keys(Pages::roles()) as $role) {
        $router->get('/login/' . $role, static fn () => $ui->login($role));
        $router->post('/login/' . $role, static fn () => $auth->login($role));
    }
    $router->get('/logout', [$auth, 'logout']);
    $router->get('/account/setup', [$auth, 'setupForm']);
    $router->post('/account/setup', [$auth, 'setup']);
    $router->get('/signup', [$ui, 'signup']);
    $router->post('/signup', [$auth, 'sendCode']);
    $router->get('/signup/verify', [$auth, 'verifyForm']);
    $router->post('/signup/verify', [$auth, 'verify']);
    $router->post('/signup/resend', [$auth, 'resend']);
    $router->get('/forgot-password', [$ui, 'recovery']);
    $router->get('/register-station', [$ui, 'registration']);
    $router->post('/register-station', [$registration, 'submit']);
    $router->post('/super-admin/application/decision', [$registryAdmin, 'decide']);
    $router->post('/super-admin/application/invite', [$registryAdmin, 'resendInvite']);
    $router->post('/super-admin/stations/status', [$registryAdmin, 'stationStatus']);
    $router->post('/station-admin/drivers', [$station, 'addDriver']);
    $router->post('/station-admin/drivers/password', [$station, 'setDriverPassword']);
    foreach ([
        'settings/profile'=>'profile', 'settings/location'=>'location',
        'settings/service-areas'=>'serviceAreas', 'settings/order-rules'=>'settings',
        'settings/delivery-fees'=>'fees', 'products/save'=>'product',
        'schedules/hours'=>'hours', 'schedules/closure'=>'closure',
        'schedules/closure/cancel'=>'cancelClosure', 'schedules/reopen'=>'reopen',
        'switch-station'=>'switchStation',
    ] as $path => $action) {
        $router->post('/station-admin/'.$path, [$stationSettings, $action]);
    }
    foreach (Pages::routes() as $path => $page) {
        $router->get($path, static fn () => $ui->page($page['role'], $page['screen']));
    }
    $router->get('/api/health', [$system, 'health']);
    // Scheduler hook for the email outbox (for example Supabase pg_net or any cron). Disabled unless JOB_SECRET is set.
    $router->post('/jobs/email', static function () use ($supabase, $config): App\Core\Response {
        $secret = (string) getenv('JOB_SECRET');
        if (strlen($secret) < 24 || !hash_equals($secret, (string) ($_SERVER['HTTP_X_JOB_KEY'] ?? ''))) return App\Core\Response::json(['ok' => false], 404);
        $outbox = App\Services\EmailOutbox::fromConfig($supabase, $config);
        return App\Core\Response::json(['ok' => true] + ($outbox?->flush(20) ?? ['sent' => 0, 'failed' => 0, 'mail' => 'not configured']));
    });
    (require __DIR__ . '/operations.php')($router, $supabase, $current);
};
