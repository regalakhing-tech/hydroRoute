<?php

declare(strict_types=1);

use App\Core\Environment;

// Composer provides PHPMailer; the App autoloader below keeps working without it.
if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = __DIR__ . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// Vercel supplies environment variables. Local development may use .env.
if (getenv('VERCEL') === false) {
    Environment::load(__DIR__ . '/.env');
}

$config = [
    'app' => require __DIR__ . '/config/app.php',
    'thesis' => require __DIR__ . '/config/thesis.php',
    'supabase' => require __DIR__ . '/config/supabase.php',
    'mail' => require __DIR__ . '/config/mail.php',
];

date_default_timezone_set($config['app']['timezone']);

return $config;
