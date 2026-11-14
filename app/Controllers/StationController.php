<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\Session;
use App\Services\AuthProvider;
use App\Services\CurrentUser;
use App\Services\Registry;
use App\Services\StationSetup;
use App\Services\SignupVerification as Check;
use App\Services\SupabaseException;
use RuntimeException;
use Throwable;

/** Station admin actions on their own station. */
final class StationController extends Controller
{
    public function __construct(
        private readonly AuthProvider $auth,
        private readonly Registry $registry,
        private readonly CurrentUser $current,
        private readonly StationSetup $setup,
    ) {
    }

    /** New password for one of this station's drivers, for example when they forget it. */
    public function setDriverPassword(): Response
    {
        $user = $this->member('station-admin');
        if ($user instanceof Response) {
            return $user;
        }
        if ($failed = $this->csrfFailed('/station-admin/drivers')) {
            return $failed;
        }
        $password = $_POST['password'] ?? null;
        $error = Check::passwordError($password, $_POST['confirm_password'] ?? null);
        if ($error !== null) {
            return $this->back('/station-admin/drivers', 'error', $error);
        }
        $token = $this->current->token();
        if ($token === null) {
            return $this->back('/login/station-admin', 'info', 'Your session ended. Please log in again.');
        }
        if (!StationSetup::isUuid($user['station']['id'] ?? null)) {
            return $this->back('/station-admin', 'info', 'Choose your station workspace to continue.');
        }
        $id = self::input('user_id', 64);
        try {
            // Row-level security returns only this station's drivers, so another station's driver is never found.
            $driver = current(array_filter($this->registry->drivers($token, $user['station']['id']), static fn (array $row): bool => $row['user_id'] === $id));
            if ($driver && !$this->registry->canManageDriverCredentials($token, $user['station']['id'], $id)) {
                return $this->back('/station-admin/drivers', 'error', 'This account requires administrator-managed password recovery.');
            }
        } catch (RuntimeException) {
            return $this->back('/station-admin/drivers', 'error', 'We could not reach the driver list. Please try again.');
        }
        if (!$driver) {
            return $this->back('/station-admin/drivers', 'error', 'That driver is not registered to your station.');
        }
        try {
            $this->auth->setUserPassword($driver['user_id'], $password);
        } catch (SupabaseException $exception) {
            return $this->back('/station-admin/drivers', 'error', $exception->errorCode === 'weak_password' ? 'Choose a stronger password.' : 'We could not change the password. Please try again.');
        } catch (RuntimeException $exception) {
            error_log('Driver password error: ' . $exception->getMessage());
            return $this->back('/station-admin/drivers', 'error', 'We could not reach the account service. Please try again.');
        }

        return $this->back('/station-admin/drivers', 'success', 'Password changed. Give ' . $driver['full_name'] . ' the new password for ' . $driver['email'] . '.');
    }

    /** The admin types the driver's password and hands over the email and password in person; no email is sent. */
    public function addDriver(): Response
    {
        $user = $this->member('station-admin');
        if ($user instanceof Response) {
            return $user;
        }
        if ($failed = $this->csrfFailed('/station-admin/drivers/add')) {
            return $failed;
        }
        $raw = [];
        foreach (['first_name', 'last_name', 'phone', 'email', 'vehicle', 'plate', 'capacity'] as $field) {
            $raw[$field] = self::input($field, 254);
        }
        Session::keepInput($raw);
        $first = Check::name($raw['first_name']);
        $last = Check::name($raw['last_name']);
        $driver = [
            'full_name' => $first !== null && $last !== null ? $first . ' ' . $last : null,
            'phone' => Check::phone($raw['phone']),
            'email' => Check::email($raw['email']),
            'vehicle' => in_array($raw['vehicle'], Registry::VEHICLES, true) ? $raw['vehicle'] : null,
            'plate' => preg_match('/^[A-Za-z0-9 -]{2,20}$/D', $raw['plate']) ? strtoupper($raw['plate']) : null,
            'capacity' => ctype_digit($raw['capacity']) && (int) $raw['capacity'] >= 1 && (int) $raw['capacity'] <= 100 ? (int) $raw['capacity'] : null,
        ];
        $password = $_POST['password'] ?? null;
        $error = match (true) {
            $driver['full_name'] === null => 'Enter the driver’s first and last name.',
            $driver['phone'] === null => 'Enter a mobile number like 09XX XXX XXXX.',
            $driver['email'] === null => 'Enter the email address the driver will log in with.',
            $driver['vehicle'] === null => 'Choose a vehicle type.',
            $driver['plate'] === null => 'Enter the plate number (letters, numbers, spaces or dashes).',
            $driver['capacity'] === null => 'Enter a container capacity from 1 to 100.',
            default => Check::passwordError($password, $_POST['confirm_password'] ?? null)
                ?? (!isset($_POST['verified']) ? 'Please confirm you reviewed the driver’s details.' : null),
        };
        if ($error !== null) {
            return $this->back('/station-admin/drivers/add', 'error', $error);
        }

        $token = $this->current->token();
        if ($token === null) {
            return $this->back('/login/station-admin', 'info', 'Your session ended. Please log in again.');
        }
        try {
            $this->setup->load($token, (string) ($user['station']['id'] ?? ''));
        } catch (Throwable $exception) {
            error_log('Driver registration authorization failed: '.$exception::class);
            return $this->back('/station-admin/drivers/add', 'error', 'Your station access could not be verified. Refresh the page or log in again.');
        }

        try {
            $account = $this->auth->createConfirmedUser($driver['email'], $password, ['full_name' => $driver['full_name'], 'phone' => $driver['phone']]);
        } catch (SupabaseException $exception) {
            return $this->back('/station-admin/drivers/add', 'error', match ($exception->errorCode) {
                'email_exists', 'user_already_exists' => 'This email already has a HydroRoute account. Use a different email for the driver.',
                'weak_password' => 'Choose a stronger password for the driver.',
                default => 'We could not create the driver account. Please check the details and try again.',
            });
        } catch (RuntimeException $exception) {
            error_log('Driver account error: ' . $exception->getMessage());
            return $this->back('/station-admin/drivers/add', 'error', 'We could not reach the account service. Please try again.');
        }

        try {
            $this->registry->registerDriver((string) $account['id'], $user['station']['id'], $driver, $user['id']);
        } catch (Throwable $exception) {
            // Undo the half-made account so the same email can be used again.
            try {
                $this->auth->deleteUser((string) $account['id']);
            } catch (Throwable $cleanup) {
                error_log('Driver account cleanup failed: ' . $cleanup->getMessage());
            }
            error_log('Driver registration error: ' . $exception->getMessage());
            return $this->back('/station-admin/drivers/add', 'error', 'We could not add the driver to your station. Please try again.');
        }
        Session::keepInput([]);

        return $this->back('/station-admin/drivers', 'success', $driver['full_name'] . ' can now log in at Rider login with ' . $driver['email'] . ' and the password you set.');
    }
}
