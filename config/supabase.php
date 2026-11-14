<?php

declare(strict_types=1);

return [
    'url' => rtrim(getenv('SUPABASE_URL') ?: '', '/'),
    'publishable_key' => getenv('SUPABASE_PUBLISHABLE_KEY') ?: '',
    // Server-only sb_secret_ key, used solely to create accounts after the email code is verified.
    'secret_key' => getenv('SUPABASE_SECRET_KEY') ?: '',
    'timeout_seconds' => 10,
];
