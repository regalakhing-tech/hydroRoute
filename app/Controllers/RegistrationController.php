<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\Session;
use App\Services\Registry;
use App\Services\SignupVerification as Check;
use App\Services\SupabaseException;
use RuntimeException;

/** Public station registration: a business applies, Super Admin reviews it later. */
final class RegistrationController extends Controller
{
    public const FIELDS = ['station_name', 'business_phone', 'business_email', 'barangay', 'business_address', 'administrator_name', 'administrator_email', 'administrator_mobile', 'business_role'];

    public function __construct(private readonly Registry $registry)
    {
    }

    public function submit(): Response
    {
        if ($failed = $this->csrfFailed('/register-station')) {
            return $failed;
        }
        $raw = [];
        foreach (self::FIELDS as $field) {
            $raw[$field] = self::input($field);
        }
        Session::keepInput($raw);

        $form = [
            'station_name' => self::text($raw['station_name'], 2, 120),
            'station_phone' => Check::phone($raw['business_phone']),
            'station_email' => $raw['business_email'] === '' ? '' : Check::email($raw['business_email']),
            'barangay' => self::text($raw['barangay'], 2, 80),
            'address' => self::text($raw['business_address'], 3, 300),
            'owner_name' => self::text($raw['administrator_name'], 2, 120),
            'owner_email' => Check::email($raw['administrator_email']),
            'owner_phone' => Check::phone($raw['administrator_mobile']),
            'owner_role' => self::text($raw['business_role'], 2, 80),
        ];
        $error = match (true) {
            $form['station_name'] === null => 'Enter the station name.',
            $form['station_phone'] === null => 'Enter the business number like 09XX XXX XXXX.',
            $form['station_email'] === null => 'Enter a valid business email, or leave it empty.',
            $form['barangay'] === null => 'Enter the barangay.',
            $form['address'] === null => 'Enter the street, sitio or purok.',
            $form['owner_name'] === null => 'Enter the administrator’s full name.',
            $form['owner_email'] === null => 'Enter the administrator’s email address.',
            $form['owner_phone'] === null => 'Enter the administrator’s mobile number like 09XX XXX XXXX.',
            $form['owner_role'] === null => 'Enter the administrator’s role at the station.',
            !isset($_POST['authorized']) => 'Please confirm you are authorized to register this station.',
            default => null,
        };
        if ($error !== null) {
            return $this->back('/register-station', 'error', $error);
        }

        try {
            $this->registry->submit($form);
        } catch (SupabaseException $exception) {
            return $this->back('/register-station', 'error', match ($exception->errorCode) {
                '23505' => 'A registration with this administrator email is already waiting for review.',
                '54000' => 'Many registrations are arriving right now. Please try again in a few minutes.',
                default => 'Please check the form details and try again.',
            });
        } catch (RuntimeException $exception) {
            error_log('Registration service error: ' . $exception->getMessage());
            return $this->back('/register-station', 'error', 'We could not reach the registration service. Please try again.');
        }
        Session::keepInput([]);
        $_SESSION['registration_submitted'] = ['station' => $form['station_name'], 'email' => $form['owner_email']];

        return $this->back('/register-station', 'success', 'Registration sent for review.');
    }

    private static function text(string $value, int $min, int $max): ?string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        $length = mb_strlen($value);

        return $length >= $min && $length <= $max && !preg_match('/[\p{Cc}<>]/u', $value) ? $value : null;
    }
}
