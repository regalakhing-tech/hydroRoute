<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Response;
use App\Core\Session;
use App\Services\AuthProvider;
use App\Services\CurrentUser;
use App\Services\Gateway;
use App\Services\SignupVerification as Signup;
use Throwable;

/** Customer account and address book (Phase 3). Writes go through RPCs; password and sign-out go through Supabase Auth. */
final class CustomerController extends LiveController
{
    public function __construct(Gateway $db, CurrentUser $current, private readonly AuthProvider $auth)
    {
        parent::__construct($db, $current);
    }

    public function saveProfile(): Response
    {
        return $this->mutate('customer', '/customer/account', 'save_customer_profile',
            static function (): array {
                $phone = Signup::phone($_POST['phone'] ?? null);
                if ($phone === null) throw new \InvalidArgumentException('phone');
                return ['p_payload' => ['display_name' => self::input('display_name', 120), 'phone' => $phone,
                    'order_updates' => isset($_POST['order_updates']) ? 'true' : 'false', 'station_updates' => isset($_POST['station_updates']) ? 'true' : 'false'],
                    'p_expected_version' => self::version($_POST['expected_version'] ?? null), 'p_request_key' => self::uuid($_POST['request_key'] ?? null)];
            },
            static function (array $d): array {
                $_SESSION['user']['name'] = self::input('display_name', 120);
                return ['success', 'Profile saved.', null];
            });
    }

    /** Confirms the current password with Supabase Auth before setting the new one. */
    public function changePassword(): Response
    {
        $user = $this->member('customer');
        if ($user instanceof Response) return $user;
        if ($failed = $this->csrfFailed('/customer/account')) return $failed;
        $token = $this->current->token();
        if ($token === null || $token === '') return $this->back('/login/customer', 'info', 'Your session ended. Please log in again.');
        $currentPassword = $_POST['current_password'] ?? '';
        if (!is_string($currentPassword) || $currentPassword === '') return $this->back('/customer/account', 'error', 'Enter your current password.');
        $error = Signup::passwordError($_POST['password'] ?? null, $_POST['confirm_password'] ?? null);
        if ($error !== null) return $this->back('/customer/account', 'error', $error);
        try {
            $this->auth->signInWithPassword((string) $user['email'], $currentPassword);
        } catch (Throwable) {
            return $this->back('/customer/account', 'error', 'Your current password is not correct.');
        }
        try {
            $this->auth->updatePassword($token, (string) $_POST['password']);
        } catch (Throwable $error) {
            error_log('Password change failed: ' . $error::class);
            return $this->back('/customer/account', 'error', 'We could not change your password. Choose a stronger one or try again.');
        }
        return $this->back('/customer/account', 'success', 'Password changed.');
    }

    /**
     * Supabase Auth's default sign-out scope is global: every device's refresh token is revoked.
     * shortcut: another device's PHP session ends at its next token refresh (within about an hour), not instantly;
     * add a per-user session epoch checked on each request if instant revocation is needed.
     */
    public function logoutEverywhere(): Response
    {
        $user = $this->member('customer');
        if ($user instanceof Response) return $user;
        if ($failed = $this->csrfFailed('/customer/account')) return $failed;
        $token = $_SESSION['auth']['access_token'] ?? null;
        try {
            if (is_string($token)) $this->auth->signOut($token);
        } catch (Throwable $error) {
            error_log('Global sign-out failed: ' . $error::class);
            return $this->back('/customer/account', 'error', 'We could not sign you out everywhere. Please try again.');
        }
        Session::logout();
        return $this->back('/login/customer', 'success', 'You are signed out on all devices.');
    }

    public function saveAddress(): Response
    {
        $id = is_string($_POST['address_id'] ?? null) ? $_POST['address_id'] : '';
        return $this->mutate('customer', '/customer/addresses' . ($id !== '' ? '?edit=' . rawurlencode($id) : ''), 'save_customer_address',
            static function () use ($id): array {
                $pinned = ($_POST['latitude'] ?? '') !== '' || ($_POST['longitude'] ?? '') !== '';
                $payload = ['label' => self::input('label', 60), 'barangay_id' => self::uuid($_POST['barangay_id'] ?? null), 'street' => self::input('street', 200),
                    'landmark' => self::input('landmark', 200), 'is_default' => isset($_POST['is_default']) ? 'true' : 'false'];
                if ($pinned) {
                    $payload += ['latitude' => self::input('latitude', 20), 'longitude' => self::input('longitude', 20),
                        'pin_source' => in_array($_POST['pin_source'] ?? '', ['map', 'device', 'manual'], true) ? $_POST['pin_source'] : 'manual'];
                }
                return ['p_address_id' => $id === '' ? null : self::uuid($id), 'p_payload' => $payload,
                    'p_expected_version' => $id === '' ? null : self::version($_POST['expected_version'] ?? null), 'p_request_key' => self::uuid($_POST['request_key'] ?? null)];
            },
            static fn (): array => ['success', 'Address saved.', '/customer/addresses']);
    }

    public function deleteAddress(): Response
    {
        return $this->addressStep('delete_customer_address', 'Address removed. Past orders keep the address they were delivered to.');
    }

    public function setDefaultAddress(): Response
    {
        return $this->addressStep('set_default_address', 'Default address updated.');
    }

    private function addressStep(string $function, string $message): Response
    {
        return $this->mutate('customer', '/customer/addresses', $function,
            static fn (): array => ['p_address_id' => self::uuid($_POST['address_id'] ?? null), 'p_expected_version' => self::version($_POST['expected_version'] ?? null),
                'p_request_key' => self::uuid($_POST['request_key'] ?? null)],
            static fn (): array => ['success', $message, null]);
    }
}
