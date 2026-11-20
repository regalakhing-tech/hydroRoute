<?php

declare(strict_types=1);

namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;

final class Mailer implements CodeMailer
{
    public const LOGO_CID = 'hydroroute-logo';

    public function __construct(private readonly array $config, private readonly string $logoPath)
    {
    }

    public function isConfigured(): bool
    {
        return $this->config['host'] !== '' && $this->config['username'] !== ''
            && $this->config['password'] !== '' && $this->config['from_address'] !== '';
    }

    public function sendVerificationCode(string $to, string $name, string $code): void
    {
        $this->verificationEmail($to, $name, $code)->send();
    }

    public function sendInvite(string $to, string $name, string $intro, string $link): void
    {
        $this->inviteEmail($to, $name, $intro, $link)->send();
    }

    /** Builds the message without sending, so tests can inspect it. Throws PHPMailer\Exception on bad input. */
    public function verificationEmail(string $to, string $name, string $code): PHPMailer
    {
        $mail = $this->message($to, $name, 'Your HydroRoute verification code');
        $safeCode = self::e($code);
        $mail->Body = self::layout($name, <<<HTML
<tr><td style="padding:8px 32px 0;font-size:16px;line-height:24px">Use this code to finish creating your HydroRoute account:</td></tr>
<tr><td style="padding:20px 32px"><div style="background:#e3f4fa;border-radius:12px;padding:18px;text-align:center;font-size:34px;font-weight:bold;letter-spacing:10px;color:#00779a">{$safeCode}</div></td></tr>
<tr><td style="padding:0 32px;font-size:14px;line-height:22px;color:#557185">The code expires in 10 minutes. Never share it with anyone, including station staff.</td></tr>
<tr><td style="padding:16px 32px 28px;font-size:14px;line-height:22px;color:#557185">If you did not try to create an account, you can ignore this email. No account is created without this code.</td></tr>
HTML);
        $mail->AltBody = "Hi {$name},\n\nYour HydroRoute verification code is {$code}.\n\n"
            . "It expires in 10 minutes. If you did not try to create an account, ignore this email.\n";

        return $mail;
    }

    public function inviteEmail(string $to, string $name, string $intro, string $link): PHPMailer
    {
        $mail = $this->message($to, $name, 'Set your HydroRoute password');
        $safeIntro = self::e($intro);
        $safeLink = self::e($link);
        $mail->Body = self::layout($name, <<<HTML
<tr><td style="padding:8px 32px 0;font-size:16px;line-height:24px">{$safeIntro}</td></tr>
<tr><td style="padding:8px 32px 0;font-size:16px;line-height:24px">Choose a password to open your dashboard:</td></tr>
<tr><td style="padding:22px 32px" align="center"><a href="{$safeLink}" style="display:inline-block;background:#00779a;color:#ffffff;text-decoration:none;font-weight:bold;font-size:16px;padding:14px 28px;border-radius:10px">Set my password</a></td></tr>
<tr><td style="padding:0 32px;font-size:13px;line-height:20px;color:#557185;word-break:break-all">If the button does not work, open this link: <a href="{$safeLink}" style="color:#00779a">{$safeLink}</a></td></tr>
<tr><td style="padding:16px 32px 28px;font-size:14px;line-height:22px;color:#557185">The link works once and expires after 24 hours. If you did not expect this email, you can ignore it.</td></tr>
HTML);
        $mail->AltBody = "Hi {$name},\n\n{$intro}\n\nSet your password here (the link works once and expires after 24 hours):\n{$link}\n";

        return $mail;
    }

    /** Order and reservation updates from the email outbox (plain notice plus a button to the order). */
    public function sendNotice(string $to, string $name, string $subject, string $body, string $link): void
    {
        $this->noticeEmail($to, $name, $subject, $body, $link)->send();
    }

    public function noticeEmail(string $to, string $name, string $subject, string $body, string $link): PHPMailer
    {
        $mail = $this->message($to, $name, $subject);
        $safeSubject = self::e($subject);
        $safeBody = nl2br(self::e($body));
        $safeLink = self::e($link);
        $mail->Body = self::layout($name, <<<HTML
<tr><td style="padding:8px 32px 0;font-size:18px;line-height:26px;font-weight:bold">{$safeSubject}</td></tr>
<tr><td style="padding:8px 32px 0;font-size:16px;line-height:24px">{$safeBody}</td></tr>
<tr><td style="padding:22px 32px" align="center"><a href="{$safeLink}" style="display:inline-block;background:#00779a;color:#ffffff;text-decoration:none;font-weight:bold;font-size:16px;padding:14px 28px;border-radius:10px">Open in HydroRoute</a></td></tr>
<tr><td style="padding:0 32px 28px;font-size:13px;line-height:20px;color:#557185">You get these emails because order updates are on in your HydroRoute account. Turn them off on your Account page.</td></tr>
HTML);
        $mail->AltBody = "Hi {$name},\n\n{$subject}\n\n{$body}\n\nOpen in HydroRoute: {$link}\n\nTurn these emails off on your Account page.\n";

        return $mail;
    }

    private function message(string $to, string $name, string $subject): PHPMailer
    {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $this->config['host'];
        $mail->Port = $this->config['port'];
        $mail->SMTPAuth = true;
        $mail->Username = $this->config['username'];
        $mail->Password = $this->config['password'];
        $mail->SMTPSecure = $this->config['encryption'] === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Timeout = 15;
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->setFrom($this->config['from_address'], $this->config['from_name']);
        $mail->addAddress($to, $name);
        $mail->Subject = $subject;
        $mail->addEmbeddedImage($this->logoPath, self::LOGO_CID, 'hydroroute.png', PHPMailer::ENCODING_BASE64, 'image/png');
        $mail->isHTML(true);

        return $mail;
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Inline styles and tables: most email clients ignore <style> blocks and modern layout. */
    private static function layout(string $name, string $rows): string
    {
        $safeName = self::e($name);
        $cid = self::LOGO_CID;

        return <<<HTML
<!doctype html>
<html lang="en"><body style="margin:0;background:#f3fafd;font-family:Arial,Helvetica,sans-serif;color:#083c53">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3fafd;padding:32px 16px">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px;background:#ffffff;border:1px solid #d8e9f1;border-radius:16px">
<tr><td style="padding:28px 32px 8px"><img src="cid:{$cid}" alt="HydroRoute" width="176" style="display:block;width:176px;height:auto;border:0"></td></tr>
<tr><td style="padding:16px 32px 0;font-size:16px;line-height:24px">Hi {$safeName},</td></tr>
{$rows}
</table>
<p style="margin:16px 0 0;font-size:12px;color:#557185">HydroRoute · Water refills in Hamtic, Antique</p>
</td></tr>
</table>
</body></html>
HTML;
    }
}
