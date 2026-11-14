<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Pure rules for the email-code sign-up step. No account exists until check() returns 'ok'.
 * The pending record lives server-side (session); it stores a hash of the code, never the code.
 */
final class SignupVerification
{
    public const CODE_TTL = 600;
    public const MAX_ATTEMPTS = 5;
    public const RESEND_WAIT = 60;
    public const MAX_SENDS = 5;
    public const SEND_WINDOW = 3600;

    /**
     * Seconds to wait before another code may be sent, -1 when the hourly cap is reached, or null when allowed.
     * Counts every send in this session, whatever email it went to.
     * shortcut: per-session only, so a new cookie resets it; add a per-email/IP table in Supabase before public launch.
     */
    public static function throttle(?array $previous, int $now): ?int
    {
        if ($previous === null || $now - $previous['first_sent_at'] >= self::SEND_WINDOW) {
            return null;
        }
        if ($previous['sends'] >= self::MAX_SENDS) {
            return -1;
        }
        $wait = self::RESEND_WAIT - ($now - $previous['sent_at']);

        return $wait > 0 ? $wait : null;
    }

    /** @return array{0: string, 1: array} the code to email and the pending record to keep */
    public static function issue(array $details, int $now, ?array $previous = null): array
    {
        $continues = $previous !== null && $now - $previous['first_sent_at'] < self::SEND_WINDOW;
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        return [$code, [
            'email' => $details['email'],
            'first_name' => $details['first_name'],
            'last_name' => $details['last_name'],
            'phone' => $details['phone'],
            'code_hash' => password_hash($code, PASSWORD_DEFAULT),
            'expires_at' => $now + self::CODE_TTL,
            'attempts' => 0,
            'sent_at' => $now,
            'first_sent_at' => $continues ? $previous['first_sent_at'] : $now,
            'sends' => $continues ? $previous['sends'] + 1 : 1,
        ]];
    }

    /** @return array{0: 'ok'|'wrong'|'expired'|'locked', 1: array} result and the updated pending record */
    public static function check(array $pending, string $code, int $now): array
    {
        if ($now > $pending['expires_at']) {
            return ['expired', $pending];
        }
        if ($pending['attempts'] >= self::MAX_ATTEMPTS) {
            return ['locked', $pending];
        }
        if (password_verify($code, $pending['code_hash'])) {
            return ['ok', $pending];
        }
        $pending['attempts']++;

        return [$pending['attempts'] >= self::MAX_ATTEMPTS ? 'locked' : 'wrong', $pending];
    }

    public static function email(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $email = strtolower(trim($value));

        return strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    public static function name(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $name = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $name !== '' && mb_strlen($name) <= 60 && !preg_match('/[\p{Cc}<>]/u', $name) ? $name : null;
    }

    /** Accepts 09XXXXXXXXX or +639XXXXXXXXX (spaces and dashes ignored); stores +639XXXXXXXXX. */
    public static function phone(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $phone = (string) preg_replace('/[\s-]/', '', $value);
        if (preg_match('/^09\d{9}$/D', $phone)) {
            return '+63' . substr($phone, 1);
        }

        return preg_match('/^\+639\d{9}$/D', $phone) ? $phone : null;
    }

    public static function passwordError(mixed $password, mixed $confirm): ?string
    {
        if (!is_string($password) || $password === '') {
            return 'Enter a password.';
        }
        if (strlen($password) < 8) {
            return 'Use at least 8 characters for your password.';
        }
        if (strlen($password) > 72) {
            return 'Use 72 characters or fewer for your password.';
        }

        return $password === $confirm ? null : 'Passwords do not match.';
    }

    public static function maskEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2) + [1 => ''];

        return mb_substr($local, 0, 1) . str_repeat('•', max(2, min(6, mb_strlen($local) - 1))) . '@' . $domain;
    }
}
