<?php

declare(strict_types=1);

return [
    'name' => getenv('APP_NAME') ?: 'HydroRoute',
    'environment' => getenv('APP_ENV') ?: 'production',
    'url' => getenv('APP_URL') ?: 'http://localhost:8000',
    'timezone' => getenv('APP_TIMEZONE') ?: 'Asia/Manila',
    // true = sample data and no logins (demos and automated page tests). Never enable in production.
    'preview' => getenv('APP_PREVIEW') === 'true',
];
