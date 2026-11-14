<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\Session;
use App\Services\AuthProvider;
use App\Services\CodeMailer;
use App\Services\SignupVerification as Signup;
use App\Services\SupabaseException;
use App\UI\Pages;
use Closure;
use RuntimeException;
use Throwable;

/**
 * Customer sign-up (email code first, account second), password login for every role,
 * and the set-password page that invited station admins, riders and Super Admins open from their email.
 */
final class AuthController extends Controller
{
    private readonly Closure $clock;

    public function __construct(
        private readonly AuthProvider $auth,
        private readonly CodeMailer $mailer,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    public function sendCode(): Response
    {
        if ($failed = $this->csrfFailed('/signup')) {
            return $failed;
        }
        Session::keepInput(array_map(
            static fn (mixed $value): string => is_string($value) ? mb_substr($value, 0, 254) : '',
            ['first_name' => $_POST['first_name'] ?? '', 'last_name' => $_POST['last_name'] ?? '', 'email' => $_POST['email'] ?? '', 'mobile' => $_POST['mobile'] ?? ''],
        ));
        $first = Signup::name($_POST['first_name'] ?? null);
        $last = Signup::name($_POST['last_name'] ?? null);
        $email = Signup::email($_POST['email'] ?? null);
        $phone = Signup::phone($_POST['mobile'] ?? null);
        $error = match (true) {
            $first === null || $last === null => 'Enter your first and last name.',
            $email === null => 'Enter a valid email address.',
            $phone === null => 'Enter a mobile number like 09XX XXX XXXX.',
            !isset($_POST['consent']) => 'Please agree to the terms to continue.',
            default => null,
        };
        if ($error !== null) {
            return $this->back('/signup', 'error', $error);
        }
        if (!$this->auth->canCreateUsers() || !$this->mailer->isConfigured()) {
            error_log('Sign-up unavailable: Supabase secret key or mail settings missing.');
            return $this->back('/signup', 'error', 'Sign-up is not available yet. Please try again later.');
        }

        $previous = $this->pending();
        $throttled = $this->throttled($previous);
        if ($throttled !== null) {
            return $this->back('/signup', 'warning', $throttled);
        }
        [$code, $pending] = Signup::issue(['email' => $email, 'first_name' => $first, 'last_name' => $last, 'phone' => $phone], ($this->clock)(), $previous);
        if (!$this->deliver($email, $first, $code)) {
            return $this->back('/signup', 'error', 'We could not send a code to that email. Check the address and try again.');
        }
        $_SESSION['signup'] = $pending;
        Session::keepInput([]);

        return $this->back('/signup/verify', 'success', 'We sent a 6-digit code to ' . Signup::maskEmail($email) . '.');
    }

    public function verifyForm(): Response
    {
        $pending = $this->pending();
        if ($pending === null) {
            return $this->back('/signup', 'info', 'Enter your details to get a verification code.');
        }

        return Response::view('layouts/auth', [
            'authView' => 'auth/verify', 'title' => 'Verify your email', 'role' => 'customer',
            'roles' => Pages::roles(), 'roleInfo' => Pages::roles()['customer'], 'email' => Signup::maskEmail($pending['email']),
        ]);
    }

    public function verify(): Response
    {
        if ($failed = $this->csrfFailed('/signup/verify')) {
            return $failed;
        }
        $pending = $this->pending();
        if ($pending === null) {
            return $this->back('/signup', 'info', 'Enter your details to get a verification code.');
        }
        $code = is_string($_POST['code'] ?? null) ? (string) preg_replace('/\s+/', '', $_POST['code']) : '';
        if (!preg_match('/^\d{6}$/D', $code)) {
            return $this->back('/signup/verify', 'error', 'Enter the 6-digit code from your email.');
        }
        $password = $_POST['password'] ?? null;
        $passwordError = Signup::passwordError($password, $_POST['confirm_password'] ?? null);
        if ($passwordError !== null) {
            return $this->back('/signup/verify', 'error', $passwordError);
        }

        [$result, $pending] = Signup::check($pending, $code, ($this->clock)());
        $_SESSION['signup'] = $pending;
        if ($result !== 'ok') {
            $left = Signup::MAX_ATTEMPTS - $pending['attempts'];
            return $this->back('/signup/verify', 'error', match ($result) {
                'expired' => 'This code has expired. Request a new one.',
                'locked' => 'Too many wrong codes. Request a new code.',
                default => 'That code is not correct. ' . $left . ($left === 1 ? ' try' : ' tries') . ' left.',
            });
        }

        $name = $pending['first_name'] . ' ' . $pending['last_name'];
        try {
            $this->auth->createConfirmedUser($pending['email'], $password, ['full_name' => $name, 'phone' => $pending['phone']]);
        } catch (SupabaseException $exception) {
            if (in_array($exception->errorCode, ['email_exists', 'user_already_exists'], true)) {
                unset($_SESSION['signup']);
                Session::keepInput(['email' => $pending['email']]);
                return $this->back('/login/customer', 'info', 'This email already has an account. Please log in.');
            }
            if ($exception->errorCode === 'weak_password') {
                return $this->back('/signup/verify', 'error', 'Choose a stronger password.');
            }
            error_log('Account creation failed: HTTP ' . $exception->status . ' ' . $exception->errorCode);
            return $this->back('/signup/verify', 'error', 'We could not create your account. Please try again.');
        } catch (RuntimeException $exception) {
            error_log('Account service error: ' . $exception->getMessage());
            return $this->back('/signup/verify', 'error', 'We could not reach the account service. Please try again.');
        }
        unset($_SESSION['signup']);

        try {
            $this->startSession($this->auth->signInWithPassword($pending['email'], $password), 'customer', null);
        } catch (Throwable) {
            Session::keepInput(['email' => $pending['email']]);
            return $this->back('/login/customer', 'success', 'Your account is ready. Please log in.');
        }
        try {
            $this->auth->saveDisplayName($_SESSION['user']['id'], $name, $_SESSION['auth']['access_token']);
        } catch (Throwable $exception) {
            error_log('Profile name not saved: ' . $exception::class);
        }

        return $this->back('/customer', 'success', 'Welcome to HydroRoute, ' . $pending['first_name'] . '!');
    }

    public function resend(): Response
    {
        if ($failed = $this->csrfFailed('/signup/verify')) {
            return $failed;
        }
        $pending = $this->pending();
        if ($pending === null) {
            return $this->back('/signup', 'info', 'Enter your details to get a verification code.');
        }
        $throttled = $this->throttled($pending);
        if ($throttled !== null) {
            return $this->back('/signup/verify', 'warning', $throttled);
        }
        [$code, $next] = Signup::issue($pending, ($this->clock)(), $pending);
        if (!$this->deliver($pending['email'], $pending['first_name'], $code)) {
            return $this->back('/signup/verify', 'error', 'We could not send a new code. Please try again.');
        }
        $_SESSION['signup'] = $next;

        return $this->back('/signup/verify', 'success', 'We sent a new code to ' . Signup::maskEmail($pending['email']) . '.');
    }

    public function login(string $role = 'customer'): Response
    {
        $loginPath = '/login/' . $role;
        if ($failed = $this->csrfFailed($loginPath)) {
            return $failed;
        }
        $rawEmail = $_POST['email'] ?? '';
        Session::keepInput(['email' => is_string($rawEmail) ? mb_substr($rawEmail, 0, 254) : '']);
        $email = Signup::email($rawEmail);
        $password = $_POST['password'] ?? null;
        if ($email === null || !is_string($password) || $password === '') {
            return $this->back($loginPath, 'error', 'Enter your email address and password.');
        }
        if (!$this->auth->isConfigured()) {
            return $this->back($loginPath, 'error', 'Login is not available yet. Please try again later.');
        }

        try {
            $session = $this->auth->signInWithPassword($email, $password);
            $access = $this->auth->access($session['access_token']);
        } catch (SupabaseException $exception) {
            return $this->back($loginPath, 'error', match (true) {
                $exception->errorCode === 'invalid_credentials' => 'Wrong email or password.',
                $exception->errorCode === 'email_not_confirmed' => 'Verify your email before logging in.',
                $exception->status === 429 => 'Too many attempts. Wait a minute and try again.',
                default => 'We could not log you in. Please try again.',
            });
        } catch (RuntimeException $exception) {
            error_log('Login service error: ' . $exception->getMessage());
            return $this->back($loginPath, 'error', 'We could not reach the login service. Please try again.');
        }

        $station = $access['stations'][0] ?? null;
        // Each login page accepts only its own kind of account. Drivers and owners also hold the customer
        // role, so the customer page refuses staff accounts. A wrong page reads like a wrong password.
        $staff = array_intersect(['super_admin', 'station_admin', 'rider'], $access['roles']) !== [];
        $allowed = in_array(self::ROLES[$role], $access['roles'], true)
            && !($role !== 'super-admin' && in_array('super_admin', $access['roles'], true))
            && !($role === 'customer' && $staff)
            && (!in_array($role, ['station-admin', 'rider'], true) || $station !== null);
        if (!$allowed) {
            $this->quietSignOut($session['access_token']);
            return $this->back($loginPath, 'error', 'Wrong email or password.');
        }
        if ($role === 'station-admin' && count($access['stations']) > 1) $station = null;
        $this->startSession($session, $role, in_array($role, ['station-admin', 'rider'], true) ? $station : null);
        Session::keepInput([]);

        return $this->back('/' . $role, 'success', 'Welcome back, ' . explode(' ', $_SESSION['user']['name'])[0] . '!');
    }

    public function logout(): Response
    {
        if (!Session::validCsrf($_GET['token'] ?? null)) {
            return Response::redirect('/');
        }
        $role = Session::user()['role'] ?? 'customer';
        $token = $_SESSION['auth']['access_token'] ?? null;
        if (is_string($token)) {
            $this->quietSignOut($token);
        }
        Session::logout();

        return $this->back('/login/' . (isset(self::ROLES[$role]) ? $role : 'customer'), 'success', 'You have been logged out.');
    }

    public function setupForm(): Response
    {
        $link = self::link($_GET);
        if ($link === null) {
            return $this->back('/login', 'error', 'This password link is incomplete. Open the newest link from your email.');
        }

        return Response::view('layouts/auth', [
            'authView' => 'auth/setup', 'title' => 'Set your password', 'role' => '',
            'roles' => Pages::roles(), 'roleInfo' => null, 'link' => $link,
        ]);
    }

    /** Verifying happens only on submit, so email scanners that open the link cannot use it up. */
    public function setup(): Response
    {
        $link = self::link($_POST);
        $retry = $link === null ? '/login' : '/account/setup?' . http_build_query(['token_hash' => $link['token_hash'], 'type' => $link['type']]);
        if ($failed = $this->csrfFailed($retry)) {
            return $failed;
        }
        if ($link === null) {
            return $this->back('/login', 'error', 'This password link is incomplete. Open the newest link from your email.');
        }
        $password = $_POST['password'] ?? null;
        $passwordError = Signup::passwordError($password, $_POST['confirm_password'] ?? null);
        if ($passwordError !== null) {
            return $this->back($retry, 'error', $passwordError);
        }

        try {
            $session = $this->auth->verifyLink($link['type'], $link['token_hash']);
        } catch (SupabaseException) {
            return $this->back('/login', 'error', 'This link has expired or was already used. Ask your administrator to send a new one.');
        } catch (RuntimeException) {
            return $this->back($retry, 'error', 'We could not reach the account service. Please try again.');
        }
        try {
            $this->auth->updatePassword($session['access_token'], $password);
            $access = $this->auth->access($session['access_token']);
        } catch (SupabaseException $exception) {
            return $this->back('/login', 'error', $exception->errorCode === 'weak_password'
                ? 'That password is too weak. Ask for a new link and choose a stronger one.'
                : 'We could not save your password. Ask your administrator for a new link.');
        } catch (RuntimeException) {
            return $this->back('/login', 'error', 'We could not reach the account service. Ask your administrator for a new link.');
        }

        $role = match (true) {
            in_array('super_admin', $access['roles'], true) => 'super-admin',
            in_array('station_admin', $access['roles'], true) && $access['stations'] !== [] => 'station-admin',
            in_array('rider', $access['roles'], true) && $access['stations'] !== [] => 'rider',
            default => 'customer',
        };
        $station = in_array($role, ['station-admin', 'rider'], true) ? $access['stations'][0] : null;
        if ($role === 'station-admin' && count($access['stations']) > 1) $station = null;
        $this->startSession($session, $role, $station);

        return $this->back('/' . $role, 'success', 'Your password is set. Welcome, ' . explode(' ', $_SESSION['user']['name'])[0] . '!');
    }

    private function startSession(array $session, string $role, ?array $station): void
    {
        $user = $session['user'];
        $name = trim((string) ($user['user_metadata']['full_name'] ?? ''));
        $email = (string) ($user['email'] ?? '');
        Session::login(
            ['id' => (string) $user['id'], 'email' => $email, 'name' => $name !== '' ? $name : $email, 'role' => $role,
             'station' => $station === null ? null : ['id' => (string) $station['id'], 'name' => (string) $station['name'], 'slug' => (string) ($station['slug'] ?? '')]],
            ['access_token' => $session['access_token'], 'refresh_token' => (string) ($session['refresh_token'] ?? ''), 'expires_at' => (int) ($session['expires_at'] ?? 0)],
        );
    }

    /** @return array{token_hash: string, type: string}|null */
    private static function link(array $source): ?array
    {
        $hash = $source['token_hash'] ?? null;
        $type = $source['type'] ?? null;

        return is_string($hash) && preg_match('/^[A-Za-z0-9_-]{20,128}$/D', $hash) && in_array($type, ['invite', 'recovery'], true)
            ? ['token_hash' => $hash, 'type' => $type] : null;
    }

    private function quietSignOut(string $token): void
    {
        try {
            $this->auth->signOut($token);
        } catch (Throwable) {
            // The local session ends anyway; the Supabase token expires on its own.
        }
    }

    private function deliver(string $email, string $name, string $code): bool
    {
        try {
            $this->mailer->sendVerificationCode($email, $name, $code);
            return true;
        } catch (Throwable $exception) {
            error_log('Verification email failed: ' . $exception->getMessage());
            return false;
        }
    }

    private function throttled(?array $pending): ?string
    {
        $wait = Signup::throttle($pending, ($this->clock)());

        return match (true) {
            $wait === null => null,
            $wait === -1 => 'Too many codes requested. Please try again in an hour.',
            default => 'Please wait ' . $wait . ' seconds before requesting another code.',
        };
    }

    private function pending(): ?array
    {
        return is_array($_SESSION['signup'] ?? null) ? $_SESSION['signup'] : null;
    }
}
