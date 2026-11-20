<?php
declare(strict_types=1);
namespace App\Services;

use Closure;
use Throwable;

/**
 * Sends queued order-update emails (private.email_outbox) with the service key. Each email is claimed, sent and
 * reported back; a failure is retried later by the database's backoff. Never throws: email must not break a page.
 */
final class EmailOutbox
{
    /** Set when a database write succeeded during this request, so only real changes trigger a send after the response. */
    public static bool $due = false;

    /** @param Closure(string $to, string $name, string $subject, string $body, string $link): void $send */
    public function __construct(private readonly Gateway $db, private readonly Closure $send, private readonly string $appUrl)
    {
    }

    public static function fromConfig(Gateway $db, array $config): ?self
    {
        if (!empty($config['app']['preview'])) return null; // preview (sample data) never sends real email, from any entry point
        $mailer = new Mailer($config['mail'], dirname(__DIR__, 2) . '/public/assets/images/logo.png');
        if (!$mailer->isConfigured()) return null;
        return new self($db, $mailer->sendNotice(...), (string) $config['app']['url']);
    }

    /** @return array{sent: int, failed: int} */
    public function flush(int $limit = 5): array
    {
        $sent = $failed = 0;
        try {
            $emails = $this->db->adminRpc('claim_email_batch', ['p_limit' => $limit])['emails'] ?? [];
        } catch (Throwable $error) {
            error_log('Email outbox unavailable: ' . $error::class);
            return ['sent' => 0, 'failed' => 0];
        }
        foreach (is_array($emails) ? $emails : [] as $email) {
            if (!is_array($email) || !is_string($email['id'] ?? null)) continue;
            $ok = false; $why = null;
            try {
                $to = (string) ($email['email'] ?? '');
                if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) throw new \InvalidArgumentException('No valid email address.');
                $link = rtrim($this->appUrl, '/') . '/' . ltrim((string) ($email['link'] ?? '/customer/notifications'), '/');
                $subject = (string) ($email['subject'] ?? 'HydroRoute update') . (($email['station'] ?? '') !== '' ? ' · ' . $email['station'] : '');
                ($this->send)($to, (string) ($email['name'] ?? ''), $subject, (string) ($email['body'] ?? ''), $link);
                $ok = true;
            } catch (Throwable $error) {
                $why = mb_substr($error->getMessage(), 0, 280);
            }
            try {
                $this->db->adminRpc('finish_email', ['p_id' => $email['id'], 'p_ok' => $ok, 'p_error' => $why]);
            } catch (Throwable $error) {
                error_log('Email outbox finish failed: ' . $error::class); // the 2-minute lock expires and it is retried
            }
            $ok ? $sent++ : $failed++;
        }
        return ['sent' => $sent, 'failed' => $failed];
    }
}
