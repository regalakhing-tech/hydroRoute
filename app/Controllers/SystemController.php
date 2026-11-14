<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Services\SupabaseClient;

final class SystemController
{
    public function __construct(private readonly SupabaseClient $supabase)
    {
    }

    public function health(): Response
    {
        return Response::json([
            'status' => 'ok',
            'stage' => 'project-starter',
            'supabase_configured' => $this->supabase->isConfigured(),
            'supabase_connection_tested' => false,
        ]);
    }
}
