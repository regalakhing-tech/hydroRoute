<?php
// Live customer account, ordering, station operations, rider delivery, messaging and report routes (Phases 3-8).
// Kept apart from routes/web.php so the station-setup work and this work merge without conflicts.
declare(strict_types=1);

use App\Controllers\CustomerController;
use App\Controllers\MessageController;
use App\Controllers\OrderController;
use App\Controllers\ReportController;
use App\Controllers\RiderController;
use App\Controllers\StationOpsController;
use App\Core\Router;
use App\Services\CurrentUser;
use App\Services\Gateway;
use App\Services\Workspace;

return static function (Router $router, Gateway $db, CurrentUser $current): void {
    Workspace::boot(new Workspace($db, $current));

    // Phase 3: the Supabase client is also the Auth provider (password change, sign-out everywhere).
    $geocoder = new App\Services\Geocoder('HydroRoute/1.0 (water refill ordering, Hamtic, Antique)');
    $customer = new CustomerController($db, $current, $db, $geocoder);
    $router->get('/customer/addresses/reverse', [$customer, 'reverse']);
    foreach (['account/profile' => 'saveProfile', 'account/password' => 'changePassword', 'account/logout-all' => 'logoutEverywhere',
        'addresses/save' => 'saveAddress', 'addresses/delete' => 'deleteAddress', 'addresses/default' => 'setDefaultAddress'] as $path => $action) {
        $router->post('/customer/' . $path, [$customer, $action]);
    }

    // Super Admin: own profile only; registry pages are read through get_registry_workspace.
    $router->post('/super-admin/account', [new App\Controllers\RegistryProfileController($db, $current), 'save']);

    $orders = new OrderController($db, $current);
    $router->post('/customer/orders', [$orders, 'submit']);
    $router->post('/customer/checkout/review', [$orders, 'review']);
    $router->post('/customer/orders/confirm', [$orders, 'confirmDuplicate']);
    $router->post('/customer/orders/cancel', [$orders, 'cancel']);
    $router->post('/customer/orders/reschedule', [$orders, 'reschedule']);
    $router->get('/customer/checkout/quote', [$orders, 'quote']);
    $router->get('/customer/tracking/status', [$orders, 'status']);
    $router->post('/customer/notifications/read', [$orders, 'readNotification']);
    $router->post('/customer/notifications/read-all', [$orders, 'readAll']);
    $router->get('/customer/navigation-counts', [$orders, 'counts']);

    $ops = new StationOpsController($db, $current, $geocoder);
    $router->get('/station-admin/settings/reverse', [$ops, 'reverse']);
    foreach ([
        'orders/start' => 'start', 'orders/ready' => 'ready', 'orders/complete' => 'complete', 'orders/cancel' => 'cancel',
        'orders/reattempt' => 'reattempt', 'orders/fee-review' => 'reviewFee', 'pickups/collect' => 'collectPickup',
        'payments/verify' => 'verifyPayment', 'reviews/resolve' => 'resolveReview', 'dispatch' => 'dispatch', 'trips/close' => 'closeTrip',
        'customers/classify' => 'classify', 'riders/save' => 'saveRider', 'notifications/read' => 'readNotification', 'schedules/capacity' => 'saveCapacityDay',
    ] as $path => $action) {
        $router->post('/station-admin/' . $path, [$ops, $action]);
    }
    $router->get('/station-admin/orders/updates', [$ops, 'updates']);
    $router->get('/station-admin/trips/status', [$ops, 'tripStatus']);
    $router->get('/station-admin/navigation-counts', [$ops, 'counts']);

    $rider = new RiderController($db, $current);
    $router->post('/rider/trips/arrived', [$rider, 'arrived']);
    $router->post('/rider/trips/result', [$rider, 'deliver']);
    $router->post('/rider/trips/close', [$rider, 'closeTrip']);
    $router->post('/rider/trips/location', [$rider, 'location']);
    $router->post('/rider/trips/replan', [$rider, 'replan']);
    $router->get('/rider/trips/current', [$rider, 'current']);
    $router->get('/rider/trips/route', [$rider, 'route']);

    $messages = new MessageController($db, $current);
    $router->post('/customer/messages/start', [$messages, 'start']);
    foreach (['customer', 'station-admin'] as $role) {
        $router->post('/' . $role . '/messages/send', static fn () => $messages->send($role));
        $router->post('/' . $role . '/messages/read', static fn () => $messages->read($role));
        $router->get('/' . $role . '/messages/thread', static fn () => $messages->thread($role));
    }
    $router->post('/customer/reservations', [$messages, 'requestReservation']);
    $router->post('/customer/reservations/cancel', [$messages, 'cancelReservation']);
    $router->post('/station-admin/reservations/respond', [$messages, 'respondReservation']);

    $reports = new ReportController($db, $current);
    $router->get('/station-admin/reports/export', [$reports, 'export']);
};
