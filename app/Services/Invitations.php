<?php

declare(strict_types=1);

namespace App\Services;

use Throwable;

/**
 * Gives someone a HydroRoute role: creates (or reuses) their account, links the role in the database,
 * then emails a one-time set-password link. Returns false when only the email failed.
 */
final class Invitations
{
    public function __construct(
        private readonly AuthProvider $auth,
        private readonly Registry $registry,
        private readonly CodeMailer $mailer,
        private readonly string $appUrl,
    ) {
    }

    public function stationAdmin(string $email, string $name, string $stationId, string $stationName): bool
    {
        $link = $this->auth->inviteLink($email, ['full_name' => $name]);
        $this->registry->provisionMember($link['user_id'], $stationId, 'station_admin');

        return $this->send($email, $name, 'Your station, ' . $stationName . ', is approved on HydroRoute. You are its station admin.', $link);
    }

    public function platformAdmin(string $email, string $name): bool
    {
        $link = $this->auth->inviteLink($email, ['full_name' => $name]);
        $this->registry->provisionPlatformAdmin($link['user_id']);

        return $this->send($email, $name, 'You are the platform administrator for the HydroRoute station registry.', $link);
    }

    public function setupUrl(array $link): string
    {
        return rtrim($this->appUrl, '/') . '/account/setup?' . http_build_query(['token_hash' => $link['token_hash'], 'type' => $link['type']]);
    }

    private function send(string $email, string $name, string $intro, array $link): bool
    {
        try {
            $this->mailer->sendInvite($email, $name, $intro, $this->setupUrl($link));
            return true;
        } catch (Throwable $exception) {
            error_log('Invitation email failed: ' . $exception->getMessage());
            return false;
        }
    }
}
