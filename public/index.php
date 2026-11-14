<?php

declare(strict_types=1);

use App\Core\Response;
use App\Core\Router;

ini_set('display_errors', '0');

try {
    $config = require dirname(__DIR__) . '/bootstrap.php';
    $router = new Router();
    $registerRoutes = require dirname(__DIR__) . '/routes/web.php';
    $registerRoutes($router, $config);

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $path = is_string($path) ? $path : '/';

    // Explicit asset allowlist works identically in local PHP and on Vercel.
    // No filesystem fallback may expose config, .env, or thesis documents.
    $assets = [
        '/assets/css/app.css' => ['file' => __DIR__ . '/assets/css/app.css', 'type' => 'text/css; charset=utf-8'],
        '/assets/js/app.js' => ['file' => __DIR__ . '/assets/js/app.js', 'type' => 'text/javascript; charset=utf-8'],
        '/assets/js/messages.js' => ['file' => __DIR__ . '/assets/js/messages.js', 'type' => 'text/javascript; charset=utf-8'],
        '/assets/js/fulfillment.js' => ['file' => __DIR__ . '/assets/js/fulfillment.js', 'type' => 'text/javascript; charset=utf-8'],
        '/assets/images/hero-water.png' => ['file' => __DIR__ . '/assets/images/hero-water.png', 'type' => 'image/png'],
        '/assets/images/water-jug.png' => ['file' => __DIR__ . '/assets/images/water-jug.png', 'type' => 'image/png'],
        '/assets/images/water-bottle.png' => ['file' => __DIR__ . '/assets/images/water-bottle.png', 'type' => 'image/png'],
        '/assets/images/faucet-container.png' => ['file' => __DIR__ . '/assets/images/faucet-container.png', 'type' => 'image/png'],
        '/assets/images/logo.png' => ['file' => __DIR__ . '/assets/images/logo.png', 'type' => 'image/png'],
        '/assets/images/logo-icon.png' => ['file' => __DIR__ . '/assets/images/logo-icon.png', 'type' => 'image/png'],
        '/assets/fonts/inter-latin-wght.woff2' => ['file' => __DIR__ . '/assets/fonts/inter-latin-wght.woff2', 'type' => 'font/woff2'],
        '/assets/vendor/sweetalert2.min.js' =>['file' => __DIR__ . '/assets/vendor/sweetalert2.min.js', 'type' => 'text/javascript; charset=utf-8'],
        '/assets/vendor/sweetalert2.min.css' => ['file' => __DIR__ . '/assets/vendor/sweetalert2.min.css', 'type' => 'text/css; charset=utf-8'],
        '/assets/images/station-illustration.svg' =>['file' => __DIR__ . '/assets/images/station-illustration.svg', 'type' => 'image/svg+xml'],
    ];

    if (isset($assets[$path]) && in_array($method, ['GET', 'HEAD'], true)) {
        $body = file_get_contents($assets[$path]['file']);
        if ($body === false) {
            throw new RuntimeException('An application asset is unavailable.');
        }
        $response = new Response($body, 200, ['Content-Type' => $assets[$path]['type']]);
    } else {
        App\Core\Session::start(str_starts_with($config['app']['url'], 'https://'));
        $response = $router->dispatch($method, $path);
    }

    $response->send($method === 'HEAD');
} catch (Throwable $exception) {
    // Do not print exception messages that could contain configuration or data.
    error_log('Application request failed: ' . $exception::class);
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        echo '{"error":"The application is temporarily unavailable."}';
    }
}
