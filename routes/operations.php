<?php
// Live ordering, station operations, rider delivery, messaging and report routes (Phases 4-8).
// Kept apart from routes/web.php so the station-setup work and this work merge without conflicts.
declare(strict_types=1);

use App\Controllers\OrderController;
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
};
