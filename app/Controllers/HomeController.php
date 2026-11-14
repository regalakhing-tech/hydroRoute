<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;

final class HomeController
{
    public function __construct(private readonly array $config)
    {
    }

    public function index(): Response
    {
        return Response::view('home', $this->config);
    }
}
