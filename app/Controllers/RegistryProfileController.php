<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Services\SignupVerification as Signup;
use InvalidArgumentException;

/** The Super Admin's own name and contact number (Account page). Email changes go through Supabase Auth. */
final class RegistryProfileController extends LiveController
{
    public function save(): Response
    {
        return $this->mutate('super-admin', '/super-admin/account', 'save_registry_profile',
            static function (): array {
                $phone = Signup::phone($_POST['phone'] ?? null);
                if ($phone === null) throw new InvalidArgumentException('phone');
                return ['p_payload' => ['display_name' => self::input('name', 120), 'phone' => $phone],
                    'p_expected_version' => self::version($_POST['expected_version'] ?? null), 'p_request_key' => self::uuid($_POST['request_key'] ?? null)];
            },
            static function (): array {
                $_SESSION['user']['name'] = self::input('name', 120);
                return ['success', 'Profile saved.', null];
            });
    }
}
