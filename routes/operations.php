<?php
// Live ordering, station operations, rider delivery, messaging and report routes (Phases 4-8).
// Kept apart from routes/web.php so the station-setup work and this work merge without conflicts.
declare(strict_types=1);

use App\Controllers\MessageController;
use App\Controllers\OrderController;
use App\Controllers\RiderController;
use App\Controllers\StationOpsController;
use App\Core\Router;
use App\Services\CurrentUser;
use App\Services\Gateway;
use App\Services\Workspace;

return static function (Router $router, Gateway $db, CurrentUser $current): void {
    Workspace::boot(new Workspace($db, $current));

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

    $ops = new StationOpsController($db, $current);
    foreach ([
        'orders/start' => 'start', 'orders/ready' => 'ready', 'orders/complete' => 'complete', 'orders/cancel' => 'cancel',
        'orders/reattempt' => 'reattempt', 'orders/fee-review' => 'reviewFee', 'pickups/collect' => 'collectPickup',
        'payments/verify' => 'verifyPayment', 'reviews/resolve' => 'resolveReview', 'dispatch' => 'dispatch', 'trips/close' => 'closeTrip',
        'customers/classify' => 'classify', 'riders/save' => 'saveRider', 'notifications/read' => 'readNotification',
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
};
