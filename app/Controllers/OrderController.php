<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Response;
use App\Services\Checkout;
use App\UI\OrderStatus;

/** Customer ordering: submit, duplicate decision, cancel, reschedule, live quote, tracking poll and notifications. */
final class OrderController extends LiveController
{
    public function submit(): Response
    {
        $station = is_string($_POST['station'] ?? null) ? strtolower($_POST['station']) : '';
        $items = Checkout::items($_POST);
        $form = Checkout::form($_POST);
        $back = '/customer/checkout?' . Checkout::query($station, $items, $form);
        return $this->mutate('customer', $back, 'submit_order',
            static function () use ($station, $items, $form): array {
                if ($items === []) throw new \InvalidArgumentException('items');
                return ['p_station_id' => self::uuid($station), 'p_payload' => Checkout::payload($items, $form), 'p_request_key' => self::uuid($_POST['request_key'] ?? null)];
            },
            static fn (array $order): array => match ($order['status'] ?? '') {
                'on_hold' => ['warning', 'This looks like a repeat of order ' . ($order['duplicate_order_no'] ?? '') . '. Confirm it below or cancel it.', self::tracking($order)],
                'scheduled' => ['success', 'Order ' . $order['order_no'] . ' is scheduled.', self::tracking($order)],
                default => ['success', 'Order ' . $order['order_no'] . ' was sent to the station.', self::tracking($order)],
            });
    }

    /** "Update total" without JavaScript: re-open checkout with the same choices; no token goes into the URL. */
    public function review(): Response
    {
        $station = is_string($_POST['station'] ?? null) ? strtolower($_POST['station']) : '';
        $back = '/customer/checkout?' . Checkout::query($station, Checkout::items($_POST), Checkout::form($_POST));
        return $this->csrfFailed($back) ?? Response::redirect($back);
    }

    public function confirmDuplicate(): Response
    {
        return $this->orderAction('confirm_duplicate', static fn (string $id): array => [],
            static fn (array $o): array => ['success', 'Order confirmed. The station has it now.', null]);
    }

    public function cancel(): Response
    {
        return $this->orderAction('cancel_order', static fn (string $id): array => ['p_reason' => self::input('reason', 500) ?: 'Cancelled by customer'],
            static fn (array $o): array => ['success', 'Order cancelled.', null]);
    }

    public function reschedule(): Response
    {
        return $this->orderAction('reschedule_order', static fn (string $id): array => ['p_requested_at' => self::manila($_POST['requested_at'] ?? null)],
            static fn (array $o): array => ['success', 'Order rescheduled.', null]);
    }

    /** GET /customer/checkout/quote: the database quote for the current form, for live totals. */
    public function quote(): Response
    {
        return $this->json('customer', function (array $user, string $token): array {
            $items = Checkout::items($_GET);
            if ($items === []) return ['ok' => false, 'error_code' => 'validation', 'message' => 'Choose at least one product.'];
            $result = $this->db->rpc('quote_order', ['p_station_id' => self::uuid($_GET['station'] ?? null), 'p_payload' => Checkout::payload($items, Checkout::form($_GET))], $token);
            if (($result['ok'] ?? false) !== true) return ['ok' => false, 'error_code' => $result['error_code'] ?? 'validation', 'message' => self::message($result)];
            $q = $result['data'];
            return ['ok' => true, 'data' => [
                'subtotal' => OrderStatus::pesos($q['subtotal_centavos']), 'fee' => $q['fulfillment'] === 'pickup' ? 'Free (pickup)' : OrderStatus::pesos($q['fee_centavos']),
                'total' => OrderStatus::pesos($q['total_centavos']), 'distance' => isset($q['delivery']['distance_display']) ? $q['delivery']['distance_display'] . ' km' : '',
                'units' => (int) $q['total_units'],
                'lines' => array_map(static fn (array $l): array => [$l['quantity'] . ' × ' . $l['name'], OrderStatus::pesos($l['line_total_centavos'])], $q['items'])]];
        });
    }

    /** GET /customer/tracking/status?order=: version and status only, so the page reloads when something changed. */
    public function status(): Response
    {
        return $this->json('customer', function (array $user, string $token): array {
            $result = $this->db->rpc('get_customer_order', ['p_order_id' => self::uuid($_GET['order'] ?? null)], $token);
            if (($result['ok'] ?? false) !== true) return ['ok' => false, 'error_code' => 'not_found'];
            $order = $result['data']['order'];
            return ['ok' => true, 'data' => ['version' => (int) $order['version'], 'status' => $order['status'], 'label' => OrderStatus::label($order['status'], $order['fulfillment'])]];
        });
    }

    public function readNotification(): Response
    {
        return $this->mutate('customer', '/customer/notifications', 'mark_notification_read',
            static fn (): array => ['p_notification_id' => self::uuid($_POST['notification_id'] ?? null), 'p_request_key' => self::uuid($_POST['request_key'] ?? null)],
            static fn (): array => ['success', 'Marked as read.', self::safeNext($_POST['next'] ?? null)]);
    }

    public function readAll(): Response
    {
        return $this->mutate('customer', '/customer/notifications', 'mark_all_notifications_read',
            static fn (): array => ['p_cutoff' => null, 'p_request_key' => self::uuid($_POST['request_key'] ?? null)],
            static fn (): array => ['success', 'All notifications marked as read.', null]);
    }

    public function counts(): Response
    {
        return $this->json('customer', fn (array $user, string $token): array => $this->db->rpc('get_navigation_counts', ['p_station_id' => null], $token));
    }

    private function orderAction(string $function, callable $extra, callable $done): Response
    {
        $id = is_string($_POST['order_id'] ?? null) ? $_POST['order_id'] : '';
        $back = '/customer/tracking?order=' . rawurlencode($id);
        return $this->mutate('customer', $back, $function,
            static fn (): array => ['p_order_id' => self::uuid($id), 'p_expected_version' => self::version($_POST['expected_version'] ?? null)]
                + $extra($id) + ['p_request_key' => self::uuid($_POST['request_key'] ?? null)],
            $done);
    }

    private static function tracking(array $order): string
    {
        return '/customer/tracking?order=' . rawurlencode((string) $order['order_id']);
    }

    /** Only a local tracking link may follow a notification click. */
    private static function safeNext(mixed $next): ?string
    {
        return is_string($next) && preg_match('#^/customer/tracking\?order=[0-9a-f-]{36}$#D', $next) === 1 ? $next : null;
    }
}
