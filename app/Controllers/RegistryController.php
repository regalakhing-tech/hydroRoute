<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Services\CurrentUser;
use App\Services\Invitations;
use App\Services\Registry;
use App\Services\SupabaseException;
use RuntimeException;

/** Super Admin decisions on station registrations. */
final class RegistryController extends Controller
{
    public function __construct(
        private readonly Registry $registry,
        private readonly Invitations $invitations,
        private readonly CurrentUser $current,
    ) {
    }

    public function decide(): Response
    {
        $user = $this->member('super-admin');
        if ($user instanceof Response) {
            return $user;
        }
        $id = self::input('id', 64);
        $back = self::isUuid($id) ? '/super-admin/application?id=' . $id : '/super-admin/applications';
        if ($failed = $this->csrfFailed($back)) {
            return $failed;
        }
        $decision = self::input('decision', 10);
        $reason = self::input('reason', 500);
        if (!self::isUuid($id) || !in_array($decision, ['approve', 'reject'], true)) {
            return $this->back('/super-admin/applications', 'error', 'Choose an application and a decision.');
        }
        if ($decision === 'reject' && $reason === '') {
            return $this->back($back, 'error', 'Write a reason before rejecting, so the applicant knows what to fix.');
        }
        $token = $this->current->token();
        if ($token === null) {
            return $this->back('/login/super-admin', 'info', 'Your session ended. Please log in again.');
        }

        try {
            $result = $this->registry->review($token, $id, $decision, $reason === '' ? null : $reason);
        } catch (SupabaseException $exception) {
            return $this->back($back, 'error', match ($exception->errorCode) {
                '55000' => 'This application was already reviewed.',
                'P0002' => 'This application no longer exists.',
                '42501' => 'Only the platform administrator can review applications.',
                default => 'We could not save the decision. Please try again.',
            });
        } catch (RuntimeException $exception) {
            error_log('Registry review error: ' . $exception->getMessage());
            return $this->back($back, 'error', 'We could not reach the registry. Please try again.');
        }
        if (($result['status'] ?? '') === 'rejected') {
            return $this->back($back, 'success', 'Application rejected. The reason is saved with the registration.');
        }

        return $this->inviteOwner($back, $result['owner_email'], $result['owner_name'], $result['station_id'], $result['station_name'], 'Station approved and listed on HydroRoute.');
    }

    /** Sends the owner a fresh set-password link, for example when the first email failed or expired. */
    public function resendInvite(): Response
    {
        $user = $this->member('super-admin');
        if ($user instanceof Response) {
            return $user;
        }
        $id = self::input('id', 64);
        $back = self::isUuid($id) ? '/super-admin/application?id=' . $id : '/super-admin/applications';
        if ($failed = $this->csrfFailed($back)) {
            return $failed;
        }
        $token = $this->current->token();
        if ($token === null) {
            return $this->back('/login/super-admin', 'info', 'Your session ended. Please log in again.');
        }
        try {
            $registration = current(array_filter($this->registry->registrations($token), static fn (array $row): bool => $row['id'] === $id));
        } catch (RuntimeException) {
            return $this->back($back, 'error', 'We could not reach the registry. Please try again.');
        }
        if (!$registration || $registration['status'] !== 'approved' || !$registration['station_id']) {
            return $this->back($back, 'error', 'Only approved applications can receive an invitation.');
        }

        return $this->inviteOwner($back, $registration['owner_email'], $registration['owner_name'], $registration['station_id'], $registration['station_name'], 'Invitation sent again.');
    }

    /** Suspend, remove (archive) or reactivate a registered station; the database enforces every rule. */
    public function stationStatus(): Response
    {
        $user = $this->member('super-admin');
        if ($user instanceof Response) {
            return $user;
        }
        $back = '/super-admin/stations';
        if ($failed = $this->csrfFailed($back)) {
            return $failed;
        }
        $id = self::input('station_id', 64);
        $status = self::input('status', 20);
        $expected = self::input('expected_status', 20);
        $key = self::input('request_key', 64);
        $reason = self::input('reason', 500);
        if (!self::isUuid($id) || !self::isUuid($key) || !in_array($status, ['approved', 'suspended', 'archived'], true) || !in_array($expected, ['approved', 'suspended'], true)) {
            return $this->back($back, 'error', 'Choose a station and an action.');
        }
        if (mb_strlen($reason) < 3) {
            return $this->back($back, 'error', 'Write a reason (at least 3 characters). It is kept with the station record.');
        }
        $token = $this->current->token();
        if ($token === null) {
            return $this->back('/login/super-admin', 'info', 'Your session ended. Please log in again.');
        }
        try {
            $result = $this->registry->setStationStatus($token, $id, $status, $expected, $reason, $key);
        } catch (SupabaseException $exception) {
            return $this->back($back, 'error', $exception->errorCode === '42501' ? 'Only the platform administrator can change a station.' : 'We could not save the change. Please try again.');
        } catch (RuntimeException $exception) {
            error_log('Station status error: ' . $exception->getMessage());
            return $this->back($back, 'error', 'We could not reach the registry. Please try again; it will not be done twice.');
        }
        if (($result['ok'] ?? false) !== true) {
            $message = current(array_filter((array) ($result['field_errors'] ?? []), 'is_string')) ?: 'We could not save the change.';
            return $this->back($back, 'error', $message);
        }
        return $this->back($back, 'success', match ($status) {
            'suspended' => 'Station suspended. Customers no longer see it; the owner can still finish open orders.',
            'archived' => 'Station removed. Its staff can no longer sign in to it. Order history is kept.',
            default => 'Station reactivated and listed again.',
        });
    }

    private function inviteOwner(string $back, string $email, string $name, string $stationId, string $stationName, string $done): Response
    {
        try {
            $sent = $this->invitations->stationAdmin($email, $name, $stationId, $stationName);
        } catch (RuntimeException $exception) {
            error_log('Owner invitation failed: ' . $exception->getMessage());
            return $this->back($back, 'warning', $done . ' The owner account could not be created yet; use “Send invitation again”.');
        }

        return $sent
            ? $this->back($back, 'success', $done . ' We emailed ' . $email . ' a link to set their password.')
            : $this->back($back, 'warning', $done . ' The invitation email failed; use “Send invitation again”.');
    }

    private static function isUuid(string $value): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $value);
    }
}
