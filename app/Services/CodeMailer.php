<?php

declare(strict_types=1);

namespace App\Services;

/** Account emails (sign-up codes and set-password invitations); tests swap in a fake. */
interface CodeMailer
{
    public function isConfigured(): bool;

    public function sendVerificationCode(string $to, string $name, string $code): void;

    public function sendInvite(string $to, string $name, string $intro, string $link): void;
}
