<?php
// Unit tests for customer sign-up and login. No network: Supabase and SMTP are replaced by fakes.
// Run: php tests/auth.php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use App\Controllers\AuthController;
use App\Controllers\UIController;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Services\Mailer;
use App\Services\SignupVerification as SV;
use App\Services\SupabaseException;

require __DIR__.'/support/fakes.php';

$now = 1_800_000_000;
$clock = static function () use (&$now): int { return $now; };
$mailer = new FakeMailer();
$auth = new FakeAuth();
$controller = new AuthController($auth, $mailer, $clock);
$post = static function (array $fields): void { $_POST = $fields + ['_token' => Session::csrfToken()]; };
$flash = static fn (): array => Session::takeFlash() ?? ['type' => '', 'message' => ''];
$details = ['first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'email' => ' Juan@Example.com ', 'mobile' => '0917 123 4567', 'consent' => 'yes'];
$_SESSION = [];

// --- Verification code rules ---
[$code, $pending] = SV::issue(['email' => 'a@b.co', 'first_name' => 'A', 'last_name' => 'B', 'phone' => '+639171234567'], $now);
check((bool) preg_match('/^\d{6}$/D', $code), 'code is six digits');
check(!in_array($code, $pending, true) && password_verify($code, $pending['code_hash']), 'only a hash of the code is kept');
check(SV::check($pending, $code, $now)[0] === 'ok', 'right code accepted');
check(SV::check($pending, $code, $now + SV::CODE_TTL + 1)[0] === 'expired', 'code expires after 10 minutes');
[$result, $wrong] = SV::check($pending, $code === '000000' ? '111111' : '000000', $now);
check($result === 'wrong' && $wrong['attempts'] === 1, 'wrong code counts an attempt');
for ($i = 0; $i < SV::MAX_ATTEMPTS; $i++) [$result, $wrong] = SV::check($wrong, $code === '000000' ? '111111' : '000000', $now);
check(SV::check($wrong, $code, $now)[0] === 'locked', 'right code refused after five wrong attempts');
check(SV::throttle(null, $now) === null && SV::throttle($pending, $now + 10) === 50 && SV::throttle($pending, $now + 60) === null, 'resend waits 60 seconds');
$chain = $pending;
for ($i = 1; $i < SV::MAX_SENDS; $i++) [, $chain] = SV::issue($chain, $now + $i * 61, $chain);
check(SV::throttle($chain, $now + 400) === -1 && SV::throttle($chain, $now + SV::SEND_WINDOW) === null, 'five codes per hour per session');
check(SV::email(' Juan@Example.COM ') === 'juan@example.com' && SV::email('not-an-email') === null && SV::email(['x']) === null, 'email normalized and validated');
check(SV::phone('0917 123 4567') === '+639171234567' && SV::phone('+639171234567') === '+639171234567' && SV::phone('12345') === null, 'phone normalized');
check(SV::name('  Ana   Marie ') === 'Ana Marie' && SV::name('<b>') === null && SV::name('') === null, 'names trimmed and validated');
check(SV::passwordError('short', 'short') !== null && SV::passwordError('longenough', 'different') === 'Passwords do not match.' && SV::passwordError('longenough', 'longenough') === null, 'password rules');
check(SV::maskEmail('juan@example.com') === 'j•••@example.com', 'email masked on screen');

// --- Email message (PHPMailer, built but not sent) ---
$smtp = ['host' => 'smtp.example.com', 'port' => 587, 'username' => 'sender@example.com', 'password' => 'secret', 'encryption' => 'tls', 'from_address' => 'sender@example.com', 'from_name' => 'HydroRoute'];
$message = (new Mailer($smtp, dirname(__DIR__).'/public/assets/images/logo.png'))->verificationEmail('juan@example.com', 'Juan <script>', '482913');
check($message->preSend(), 'email builds');
$mime = $message->getSentMIMEMessage();
check(str_contains($message->Body, '482913') && str_contains($message->AltBody, '482913'), 'code in HTML and plain text');
check(str_contains($message->Body, 'src="cid:hydroroute-logo"') && str_contains($mime, 'Content-ID: <hydroroute-logo>') && str_contains($mime, 'image/png'), 'logo embedded in the email');
check(!str_contains($message->Body, '<script>') && str_contains($message->Body, 'Juan &lt;script&gt;'), 'name escaped in HTML');
check($message->Subject === 'Your HydroRoute verification code' && str_contains($mime, 'juan@example.com'), 'subject and recipient');
check(!(new Mailer(['host' => '', 'username' => '', 'password' => '', 'from_address' => ''] + $smtp, 'x'))->isConfigured(), 'missing SMTP settings detected');

// --- Sign-up: code first, account only after the right code ---
$_POST = $details;
$response = $controller->sendCode();
check($mailer->sent === [] && $flash()['type'] === 'error' && $response->headers['Location'] === '/signup', 'missing CSRF token sends nothing');
$post(['email' => 'bad'] + $details);
$controller->sendCode();
check($mailer->sent === [] && $flash()['message'] === 'Enter a valid email address.', 'invalid email sends nothing');
$post($details);
$response = $controller->sendCode();
check(count($mailer->sent) === 1 && $mailer->sent[0]['to'] === 'juan@example.com' && $mailer->sent[0]['name'] === 'Juan', 'one code emailed to the normalized address');
check($response->status === 303 && $response->headers['Location'] === '/signup/verify' && $flash()['type'] === 'success', 'redirect to code entry with success toast');
check($auth->users === [], 'no account exists after sending the code');
$post($details);
$controller->sendCode();
check(count($mailer->sent) === 1 && str_contains($flash()['message'], 'wait'), 'second code within 60 seconds refused');

$right = $mailer->lastCode();
$badCode = $right === '000000' ? '111111' : '000000';
$post(['code' => $badCode, 'password' => 'waterpass1', 'confirm_password' => 'waterpass1']);
$controller->verify();
check($auth->users === [] && $flash()['message'] === 'That code is not correct. 4 tries left.', 'wrong code creates no account');
$post(['code' => $right, 'password' => 'waterpass1', 'confirm_password' => 'different1']);
$controller->verify();
check($auth->users === [] && $flash()['message'] === 'Passwords do not match.' && $_SESSION['signup']['attempts'] === 1, 'password mismatch creates no account and costs no attempt');
$post(['code' => $right, 'password' => 'waterpass1', 'confirm_password' => 'waterpass1']);
$response = $controller->verify();
check(count($auth->users) === 1 && isset($auth->users['juan@example.com']), 'right code creates exactly one account');
check($auth->users['juan@example.com']['user_metadata'] === ['full_name' => 'Juan Dela Cruz', 'phone' => '+639171234567'], 'name and phone saved with the account');
check(Session::user() === ['id' => 'user-0', 'email' => 'juan@example.com', 'name' => 'Juan Dela Cruz', 'role' => 'customer', 'station' => null] && $_SESSION['auth']['access_token'] === 'token-user-0', 'new customer is logged in');
check(!isset($_SESSION['signup']) && $auth->profiles === ['user-0' => 'Juan Dela Cruz'], 'pending code cleared and profile name saved');
check($response->headers['Location'] === '/customer' && $flash()['message'] === 'Welcome to HydroRoute, Juan!', 'welcome toast on the dashboard');

// Same email again: verified, but the account already exists.
$_SESSION = [];
$now += 3600;
$post($details);
$controller->sendCode();
$post(['code' => $mailer->lastCode(), 'password' => 'otherpass1', 'confirm_password' => 'otherpass1']);
$response = $controller->verify();
check($response->headers['Location'] === '/login/customer' && Session::user() === null && $flash()['type'] === 'info' && count($auth->users) === 1, 'existing email sent to login without a second account');

// Expired and locked codes.
$_SESSION = [];
$now += 3600;
$post(['email' => 'ana@example.com'] + $details);
$controller->sendCode();
$now += SV::CODE_TTL + 1;
$post(['code' => $mailer->lastCode(), 'password' => 'waterpass1', 'confirm_password' => 'waterpass1']);
$controller->verify();
check(!isset($auth->users['ana@example.com']) && str_contains($flash()['message'], 'expired'), 'expired code creates no account');
$now += 61;
$post([]);
$controller->resend();
check($flash()['type'] === 'success' && count($mailer->sent) === 4, 'new code can be requested');
$fresh = $mailer->lastCode();
for ($i = 0; $i < SV::MAX_ATTEMPTS; $i++) { $post(['code' => $fresh === '000000' ? '111111' : '000000', 'password' => 'waterpass1', 'confirm_password' => 'waterpass1']); $controller->verify(); $flash(); }
$post(['code' => $fresh, 'password' => 'waterpass1', 'confirm_password' => 'waterpass1']);
$controller->verify();
check(!isset($auth->users['ana@example.com']) && str_contains($flash()['message'], 'Too many wrong codes'), 'locked code creates no account');

// Email delivery failure keeps nothing pending.
$_SESSION = [];
$now += 3600;
$mailer->fail = true;
$post(['email' => 'pedro@example.com'] + $details);
$controller->sendCode();
check(!isset($_SESSION['signup']) && $flash()['type'] === 'error', 'failed email leaves no pending sign-up');
$mailer->fail = false;
check($controller->verifyForm()->headers['Location'] === '/signup', 'code page needs a pending sign-up');

// --- Login and logout ---
$_SESSION = [];
$post(['email' => 'juan@example.com', 'password' => 'wrongpass']);
$response = $controller->login();
check(Session::user() === null && $flash()['message'] === 'Wrong email or password.' && $response->headers['Location'] === '/login/customer', 'wrong password refused with toast');
check(Session::takeInput() === ['email' => 'juan@example.com'], 'email kept for the retry, password never kept');
$post(['email' => 'JUAN@example.com', 'password' => 'waterpass1']);
$response = $controller->login();
check(Session::user()['id'] === 'user-0' && $response->headers['Location'] === '/customer' && $flash()['message'] === 'Welcome back, Juan!', 'right password logs in');
$_GET = ['token' => 'forged'];
$controller->logout();
check(Session::user() !== null && $auth->signedOut === [], 'logout ignores a forged token');
$_GET = ['token' => Session::csrfToken()];
$response = $controller->logout();
check(Session::user() === null && $auth->signedOut === ['token-user-0'] && $flash()['type'] === 'success' && $response->headers['Location'] === '/login/customer', 'logout ends the session');

// --- Customer pages need login ---
$_SESSION = [];
$guarded = (new UIController(false))->page('customer', 'overview');
check($guarded->status === 303 && $guarded->headers['Location'] === '/login/customer', 'customer pages redirect to login');
$flash();
Session::login(['id' => 'user-0', 'email' => 'juan@example.com', 'name' => 'Juan Dela Cruz', 'role' => 'customer', 'station' => null], ['access_token' => 't', 'expires_at' => 2_000_000_000]);
// The live home page reads Supabase through the workspace; an empty fake gateway gives its error state.
App\Services\Workspace::boot(new App\Services\Workspace(new FakeGateway(), new App\Services\CurrentUser($auth)));
$page = (new UIController(false))->page('customer', 'overview');
check($page->status === 200 && preg_match('/Good (morning|afternoon|evening), Juan/', $page->body) === 1 && str_contains($page->body, '>JD<'), 'logged-in customer sees own name and initials');
check(str_contains($page->body, '/logout?token='.Session::csrfToken()), 'logout link carries the CSRF token');
$_SESSION = [];
check((new UIController(true))->page('customer', 'overview')->status === 200, 'preview mode still shows sample customer pages');

// --- Router methods ---
$router = new Router();
$router->get('/signup', static fn () => new Response('form'));
$router->post('/signup', static fn () => new Response('posted'));
$router->get('/only-get', static fn () => new Response('x'));
check($router->dispatch('POST', '/signup')->body === 'posted' && $router->dispatch('HEAD', '/signup')->body === 'form', 'GET, HEAD and POST routes');
$denied = $router->dispatch('POST', '/only-get');
check($denied->status === 405 && $denied->headers['Allow'] === 'GET, HEAD' && $router->dispatch('GET', '/missing')->status === 404, '405 and 404 kept');

echo "$checks auth assertions passed.\n";
