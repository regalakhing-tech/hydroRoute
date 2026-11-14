<?php

declare(strict_types=1);

// SMTP settings for PHPMailer. Gmail: smtp.gmail.com, port 587, tls, and a Google App Password.
return [
    'host' => getenv('MAIL_HOST') ?: '',
    'port' => (int) (getenv('MAIL_PORT') ?: 587),
    'username' => getenv('MAIL_USERNAME') ?: '',
    'password' => getenv('MAIL_PASSWORD') ?: '',
    'encryption' => getenv('MAIL_ENCRYPTION') === 'ssl' ? 'ssl' : 'tls',
    'from_address' => getenv('MAIL_FROM_ADDRESS') ?: (getenv('MAIL_USERNAME') ?: ''),
    'from_name' => getenv('MAIL_FROM_NAME') ?: 'HydroRoute',
];
