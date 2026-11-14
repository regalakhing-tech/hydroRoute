<?php

declare(strict_types=1);

namespace App\Services;

/** Data access to Supabase PostgREST; tests swap in a fake. */
interface Gateway
{
    public function select(string $table, array $query = [], ?string $accessToken = null): array;

    /** Null token = visitor (publishable key only). Scalar results come back as ['value' => …]. */
    public function rpc(string $function, array $parameters, ?string $accessToken): array;

    /** Server-only call with the secret key, for provisioning functions granted to service_role. */
    public function adminRpc(string $function, array $parameters): array;
}
