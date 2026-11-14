<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\Session;
use App\UI\Pages;

/** Shared helpers: every outcome is a redirect plus a flash message that the page shows as a toast. */
abstract class Controller
{
    /** Application role slugs (URLs) mapped to database role names. */
    public const ROLES = ['customer' => 'customer', 'station-admin' => 'station_admin', 'rider' => 'rider', 'super-admin' => 'super_admin'];

    protected function back(string $path, string $type, string $message): Response
    {
        Session::flash($type, $message);

        return Response::redirect($path);
    }

    /** The session user when logged in with this role, otherwise a redirect to that role's login. */
    protected function member(string $role): array|Response
    {
        $user = Session::user();
        if ($user === null || ($user['role'] ?? null) !== $role) {
            return $this->back('/login/' . $role, 'info', 'Log in with your ' . Pages::roles()[$role]['label'] . ' account to continue.');
        }

        return $user;
    }

    protected function csrfFailed(string $path): ?Response
    {
        return Session::validCsrf($_POST['_token'] ?? null) ? null : $this->back($path, 'error', 'Your session expired. Please try again.');
    }

    protected static function input(string $key, int $max = 300): string
    {
        $value = $_POST[$key] ?? '';

        return is_string($value) ? trim(mb_substr($value, 0, $max)) : '';
    }
}
