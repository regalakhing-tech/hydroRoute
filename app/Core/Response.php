<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    public function __construct(
        public readonly string $body,
        public readonly int $status = 200,
        public readonly array $headers = [],
    ) {
    }

    public static function json(array $data, int $status = 200): self
    {
        return new self(
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }

    /** Location must be an application path chosen by a controller, never request input. */
    public static function redirect(string $location): self
    {
        return new self('', 303, ['Location' => $location]);
    }

    public static function view(string $view, array $data): self
    {
        // View names are supplied by controllers, never from request parameters.
        $file = dirname(__DIR__) . '/Views/' . $view . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('The requested template is unavailable.');
        }

        $e = static fn (string $value): string => htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );

        ob_start();
        try {
            require $file;
            $body = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        return new self($body, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public function send(bool $headOnly = false): void
    {
        http_response_code($this->status);
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('Cache-Control: no-store');
        header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'");

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        if (!$headOnly) {
            echo $this->body;
        }
    }
}
