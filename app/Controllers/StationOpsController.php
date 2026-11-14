<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Response;

/** Station admin order operations: queue steps, cancellation, pickups, cash, reviews, dispatch, trips, customers and riders. */
final class StationOpsController extends LiveController
{
    private const ROLE = 'station-admin';

    public function start(): Response { return $this->step('start_preparation', 'Preparation started.'); }

    public function ready(): Response { return $this->step('mark_order_ready', 'Order marked ready.'); }

    public function complete(): Response { return $this->step('complete_order', 'Order completed.'); }

    public function cancel(): Response
    {
        return $this->step('cancel_order', 'Order cancelled. The customer was notified.', static fn (): array => ['p_reason' => self::input('reason', 500)]);
    }

    public function reattempt(): Response
    {
        return $this->step('approve_reattempt', 'Reattempt approved. The order can join the next trip.', static fn (): array => ['p_reason' => self::input('reason', 500)]);
    }

    public function reviewFee(): Response
    {
        return $this->step('review_delivery_fee', 'Fee review saved. The customer still pays the quoted fee.', static fn (): array => ['p_decision' => 'keep_quote', 'p_reason' => self::input('reason', 500)]);
    }

    public function collectPickup(): Response
    {
        return $this->step('record_pickup_collection', 'Pickup recorded. Verify the cash to complete the order.',
            static fn (): array => ['p_payload' => ['tendered_centavos' => self::centavos($_POST['tendered'] ?? null), 'empties_returned' => self::count($_POST['empties'] ?? null)]],
            static fn (string $id): string => '/station-admin/pickups');
    }

    public function verifyPayment(): Response
    {
        $back = self::back_path();
        return $this->mutate(self::ROLE, $back, 'verify_payment',
            static fn (): array => ['p_payment_id' => self::uuid($_POST['payment_id'] ?? null), 'p_payload' => ['note' => self::input('note', 300)],
                'p_expected_version' => self::version($_POST['expected_version'] ?? null), 'p_request_key' => self::uuid($_POST['request_key'] ?? null)],
            static fn (): array => ['success', 'Cash verified.', null]);
    }

    public function resolveReview(): Response
    {
        return $this->mutate(self::ROLE, self::back_path(), 'resolve_order_review',
            static fn (): array => ['p_review_id' => self::uuid($_POST['review_id'] ?? null), 'p_action' => 'acknowledge', 'p_reason' => self::input('reason', 500),
                'p_expected_version' => self::version($_POST['expected_version'] ?? null), 'p_request_key' => self::uuid($_POST['request_key'] ?? null)],
            static fn (): array => ['success', 'Alert resolved.', null]);
    }

    public function dispatch(): Response
    {
        return $this->mutate(self::ROLE, '/station-admin/deliveries', 'dispatch_trip',
            static function (array $user): array {
                $ids = array_values(array_unique(array_map(self::uuid(...), is_array($_POST['order_ids'] ?? null) ? $_POST['order_ids'] : [])));
                $front = is_string($_POST['front_order_id'] ?? null) && $_POST['front_order_id'] !== '' ? self::uuid($_POST['front_order_id']) : null;
                return ['p_station_id' => self::station($user), 'p_rider_id' => self::uuid($_POST['rider_id'] ?? null), 'p_order_ids' => $ids,
                    'p_request_key' => self::uuid($_POST['request_key'] ?? null),
                    'p_proposal' => $front === null ? (object) [] : ['front_order_id' => $front, 'front_reason' => self::input('front_reason', 300)]];
            },
            static fn (array $trip): array => ['success', 'Trip ' . $trip['trip_no'] . ' dispatched. The rider and customers can see it now.', '/station-admin/trip?trip=' . rawurlencode((string) $trip['trip_id'])]);
    }

    public function closeTrip(): Response
    {
        $trip = is_string($_POST['trip_id'] ?? null) ? $_POST['trip_id'] : '';
        return $this->mutate(self::ROLE, '/station-admin/trip?trip=' . rawurlencode($trip), 'close_trip',
            static fn (): array => ['p_trip_id' => self::uuid($trip), 'p_expected_version' => self::version($_POST['expected_version'] ?? null),
                'p_reason' => self::input('reason', 300), 'p_request_key' => self::uuid($_POST['request_key'] ?? null)],
            static fn (): array => ['success', 'Trip closed. The rider is free for the next trip.', null]);
    }

    public function classify(): Response
    {
        return $this->mutate(self::ROLE, '/station-admin/customers', 'set_customer_classification',
            static function (array $user): array {
                $version = $_POST['expected_version'] ?? '';
                return ['p_station_id' => self::station($user), 'p_customer_id' => self::uuid($_POST['customer_id'] ?? null),
                    'p_classification' => ($_POST['classification'] ?? '') === 'institution' ? 'institution' : 'household',
                    'p_expected_version' => $version === '' || $version === '0' ? null : self::version($version), 'p_request_key' => self::uuid($_POST['request_key'] ?? null)];
            },
            static fn (array $d): array => ['success', 'Customer saved as ' . $d['classification'] . '.', null]);
    }

    public function saveRider(): Response
    {
        return $this->mutate(self::ROLE, '/station-admin/deliveries', 'save_rider',
            static fn (): array => ['p_rider_id' => self::uuid($_POST['rider_id'] ?? null), 'p_payload' => ['vehicle' => self::input('vehicle', 40),
                'plate_number' => self::input('plate_number', 20), 'capacity' => self::count($_POST['capacity'] ?? null, 100), 'enabled' => isset($_POST['enabled']) ? 'true' : 'false'],
                'p_expected_version' => self::version($_POST['expected_version'] ?? null), 'p_request_key' => self::uuid($_POST['request_key'] ?? null)],
            static fn (): array => ['success', 'Rider vehicle saved.', null]);
    }

    public function readNotification(): Response
    {
        return $this->mutate(self::ROLE, '/station-admin', 'mark_notification_read',
            static fn (): array => ['p_notification_id' => self::uuid($_POST['notification_id'] ?? null), 'p_request_key' => self::uuid($_POST['request_key'] ?? null)],
            static fn (): array => ['success', 'Marked as read.', null]);
    }

    /** GET /station-admin/orders/updates: a fingerprint of the queue so open pages reload when it changes. */
    public function updates(): Response
    {
        return $this->json(self::ROLE, function (array $user, string $token): array {
            $result = $this->db->rpc('get_preparation_queue', ['p_station_id' => self::station($user)], $token);
            if (($result['ok'] ?? false) !== true) return ['ok' => false, 'error_code' => 'unavailable'];
            return ['ok' => true, 'data' => ['version' => \App\Services\Workspace::fingerprint($result['data'])]];
        });
    }

    /** GET /station-admin/trips/status?trip=: trip version and resolved stops, for the trip page poll. */
    public function tripStatus(): Response
    {
        return $this->json(self::ROLE, function (array $user, string $token): array {
            $result = $this->db->rpc('get_trip', ['p_trip_id' => self::uuid($_GET['trip'] ?? null)], $token);
            if (($result['ok'] ?? false) !== true || empty($result['data']['trip'])) return ['ok' => false, 'error_code' => 'not_found'];
            $done = count(array_filter($result['data']['stops'], static fn (array $s): bool => in_array($s['state'], ['delivered', 'failed'], true)));
            return ['ok' => true, 'data' => ['version' => $result['data']['trip']['version'] . ':' . $done]];
        });
    }

    public function counts(): Response
    {
        return $this->json(self::ROLE, fn (array $user, string $token): array => $this->db->rpc('get_navigation_counts', ['p_station_id' => self::station($user)], $token));
    }

    /** One order step from the order panel: id, version and key come from the form; the rest from $extra. */
    private function step(string $function, string $message, ?callable $extra = null, ?callable $next = null): Response
    {
        $id = is_string($_POST['order_id'] ?? null) ? $_POST['order_id'] : '';
        return $this->mutate(self::ROLE, self::back_path(), $function,
            static fn (): array => ['p_order_id' => self::uuid($id)] + ($extra ? $extra() : [])
                + ['p_expected_version' => self::version($_POST['expected_version'] ?? null), 'p_request_key' => self::uuid($_POST['request_key'] ?? null)],
            static fn (): array => ['success', $message, $next ? $next($id) : null]);
    }

    /** Return to the page the form came from; only local station-admin pages are allowed. */
    private static function back_path(): string
    {
        $back = $_POST['back'] ?? '';
        return is_string($back) && preg_match('#^/station-admin(/[a-z/-]*)?(\?[A-Za-z0-9=&%_.-]*)?$#D', $back) === 1 ? $back : '/station-admin/orders';
    }

    private static function station(array $user): string
    {
        return self::uuid($user['station']['id'] ?? null);
    }
}
