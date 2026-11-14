<?php

declare(strict_types=1);

namespace App\Core;

final class Router
{
    private array $routes = ['GET' => [], 'POST' => []];

    public function get(string $path, callable $handler): void
    {
        $this->routes['GET'][$path] = $handler;
    }

    public function post(string $path, callable $handler): void
    {
        $this->routes['POST'][$path] = $handler;
    }

    public function dispatch(string $method, string $path): Response
    {
        $lookup = $method === 'HEAD' ? 'GET' : $method;
        if (isset($this->routes[$lookup][$path])) {
            return ($this->routes[$lookup][$path])();
        }

        $allowed = array_keys(array_filter($this->routes, static fn (array $routes): bool => isset($routes[$path])));
        if ($allowed === []) {
            return Response::json(['error' => 'Not found.'], 404);
        }
        if (in_array('GET', $allowed, true)) {
            $allowed[] = 'HEAD';
        }

        return new Response(
            '{"error":"Method not allowed."}',
            405,
            ['Content-Type' => 'application/json; charset=utf-8', 'Allow' => implode(', ', $allowed)],
        );
    }
}
