<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Response;
use App\Core\Session;
use App\Services\CurrentUser;
use App\Services\Gateway;
use App\Services\SupabaseException;
use InvalidArgumentException;
use Throwable;

/**
 * Shared plumbing for the live order, operations, rider and message controllers.
 * Every write is one RPC with the user's token; the database decides prices, permissions and transitions.
 */
abstract class LiveController extends Controller
{
    public function __construct(protected readonly Gateway $db, protected readonly CurrentUser $current) {}

    /**
     * Runs one mutating RPC from a POST form. $params gets the session user and returns RPC parameters
     * (throw InvalidArgumentException for bad input). $done gets the RPC data and returns [type, message, path].
     */
    protected function mutate(string $role, string $back, string $function, callable $params, callable $done): Response
    {
        // Pages that send with fetch (the chat) ask for JSON; forms get a flash message and a redirect.
        $json = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
        $fail = fn (string $message, int $status, string $code = 'error'): Response => $json ? Response::json(['ok' => false, 'error_code' => $code, 'message' => $message], $status) : $this->back($back, 'error', $message);
        $user = Session::user();
        if (($user['role'] ?? null) !== $role) return $json ? Response::json(['ok' => false, 'error_code' => 'unauthorized'], 401) : $this->member($role);
        if (!Session::validCsrf($_POST['_token'] ?? null)) return $fail('Your session expired. Please try again.', 419, 'csrf');
        $token = $this->current->token();
        if ($token === null || $token === '') return $json ? Response::json(['ok' => false, 'error_code' => 'unauthorized'], 401) : $this->back('/login/' . $role, 'info', 'Your session ended. Please log in again.');
        try {
            $parameters = $params($user);
        } catch (InvalidArgumentException) {
            return $fail('Check the form and try again.', 422, 'validation');
        }
        try {
            $result = $this->db->rpc($function, $parameters, $token);
        } catch (Throwable $error) {
            error_log('Live action failed: ' . $function . ' ' . $error::class);
            return self::forbidden($error) ? $fail('You cannot do that.', 403, 'forbidden') : $fail('We could not confirm this action. Please try again; it will not be done twice.', 503, 'unavailable');
        }
        if (($result['ok'] ?? false) !== true) {
            if ($json) return Response::json(['ok' => false, 'error_code' => $result['error_code'] ?? 'error', 'message' => self::message($result)], 422);
            $_SESSION['form_errors'] = array_filter(is_array($result['field_errors'] ?? null) ? $result['field_errors'] : [], 'is_string');
            return $this->back($back, 'error', self::message($result));
        }
        unset($_SESSION['form_errors']);
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        [$type, $message, $path] = $done($data);
        return $json ? Response::json(['ok' => true, 'data' => $data, 'message' => $message]) : $this->back($path ?? $back, $type, $message);
    }

    /** JSON read endpoint for polling. $read gets [user, token] and returns the response body. */
    protected function json(string $role, callable $read): Response
    {
        $user = Session::user();
        $token = ($user['role'] ?? null) === $role ? $this->current->token() : null;
        if ($token === null || $token === '') return Response::json(['ok' => false, 'error_code' => 'unauthorized'], 401);
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close(); // a poll never holds the session open
        try {
            $body = $read($user, $token);
        } catch (InvalidArgumentException) {
            return Response::json(['ok' => false, 'error_code' => 'validation'], 422);
        } catch (Throwable $error) {
            error_log('Live poll failed: ' . $error::class);
            return Response::json(['ok' => false, 'error_code' => self::forbidden($error) ? 'forbidden' : 'unavailable'], self::forbidden($error) ? 403 : 503);
        }
        return Response::json($body, ($body['ok'] ?? true) === false && ($body['error_code'] ?? '') === 'not_found' ? 404 : 200);
    }

    /** The database writes friendly field messages; show the first, or a message for the error code. */
    protected static function message(array $result): string
    {
        foreach (is_array($result['field_errors'] ?? null) ? $result['field_errors'] : [] as $message) {
            if (is_string($message) && $message !== '') return $message;
        }
        return match ($result['error_code'] ?? null) {
            'stale_version' => 'This was changed a moment ago. Review the latest details and try again.',
            'idempotency_conflict' => 'This form was already sent with different details. Reload the page and try again.',
            'invalid_transition' => 'That step is not allowed for this order right now.',
            'not_found' => 'We could not find that record.',
            'rate_limited' => 'You are sending messages too quickly. Wait a moment and try again.',
            default => 'We could not complete this action. Check the details and try again.',
        };
    }

    protected static function forbidden(Throwable $error): bool
    {
        return $error instanceof SupabaseException && ($error->status === 403 || $error->errorCode === '42501');
    }

    public static function key(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    protected static function uuid(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di', $value) !== 1) {
            throw new InvalidArgumentException('uuid');
        }
        return strtolower($value);
    }

    protected static function version(mixed $value): int
    {
        if (!is_string($value) && !is_int($value) || preg_match('/^[1-9][0-9]{0,8}$/D', (string) $value) !== 1) throw new InvalidArgumentException('version');
        return (int) $value;
    }

    /** Pesos typed as "120" or "120.50" to integer centavos, as a digit string for the payload. */
    protected static function centavos(mixed $value): string
    {
        $value = is_string($value) ? trim(str_replace([',', ' '], '', $value)) : '';
        if (preg_match('/^(\d{1,7})(?:\.(\d{1,2}))?$/D', $value, $m) !== 1) throw new InvalidArgumentException('amount');
        return (string) ((int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0'));
    }

    protected static function count(mixed $value, int $max = 9999): string
    {
        $n = is_string($value) ? filter_var(trim($value), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => $max]]) : false;
        if ($n === false) throw new InvalidArgumentException('count');
        return (string) $n;
    }

    /** Manila "YYYY-MM-DDTHH:MM" from a datetime-local field to an ISO instant. */
    protected static function manila(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/D', $value) !== 1) throw new InvalidArgumentException('time');
        $date = \DateTimeImmutable::createFromFormat(strlen($value) === 16 ? 'Y-m-d\TH:i' : 'Y-m-d\TH:i:s', $value, new \DateTimeZone('Asia/Manila'));
        if ($date === false) throw new InvalidArgumentException('time');
        return $date->format(DATE_ATOM);
    }
}
