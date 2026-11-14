<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/** A non-2xx Supabase reply; errorCode is the provider's machine-readable reason, never shown raw to users. */
final class SupabaseException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status, public readonly string $errorCode = '')
    {
        parent::__construct($message, $status);
    }
}
