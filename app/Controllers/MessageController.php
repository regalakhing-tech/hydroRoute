<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Response;

/** Live inbox and chat for customers and station admins, plus reservation requests and decisions. Riders and Super Admin have no access. */
final class MessageController extends LiveController
{
    public function start(): Response
    {
        return $this->mutate('customer', '/customer/messages', 'start_conversation',
            static fn (): array => ['p_station_id' => self::uuid($_POST['station_id'] ?? null), 'p_request_key' => self::uuid($_POST['request_key'] ?? null)],
            static fn (array $d): array => ['success', 'Conversation opened.', '/customer/messages?conversation=' . rawurlencode((string) $d['conversation_id'])]);
    }

    public function send(string $role): Response
    {
        $id = is_string($_POST['conversation_id'] ?? null) ? $_POST['conversation_id'] : '';
        $back = '/' . $role . '/messages?conversation=' . rawurlencode($id);
        return $this->mutate($role, $back, 'send_message',
            static fn (): array => ['p_conversation_id' => self::uuid($id), 'p_body' => self::input('body', 2000),
                'p_topic' => in_array($_POST['topic'] ?? '', ['general', 'reservation', 'order', 'delivery'], true) ? $_POST['topic'] : 'general',
                'p_order_id' => ($_POST['order_id'] ?? '') === '' ? null : self::uuid($_POST['order_id']), 'p_request_key' => self::uuid($_POST['request_key'] ?? null)],
            static fn (): array => ['success', 'Message sent.', null]);
    }

    public function read(string $role): Response
    {
        $id = is_string($_POST['conversation_id'] ?? null) ? $_POST['conversation_id'] : '';
        $params = static fn (): array => ['p_conversation_id' => self::uuid($id), 'p_last_seen_message_id' => self::uuid($_POST['message_id'] ?? null), 'p_request_key' => self::key()];
        if (($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json') {
            return $this->json($role, fn (array $user, string $token): array => \App\Core\Session::validCsrf($_POST['_token'] ?? null)
                ? $this->db->rpc('mark_conversation_read', $params(), $token) : ['ok' => false, 'error_code' => 'csrf']);
        }
        return $this->mutate($role, '/' . $role . '/messages?conversation=' . rawurlencode($id), 'mark_conversation_read', $params, static fn (): array => ['success', 'Marked as read.', null]);
    }

    /** GET /{role}/messages/thread?conversation=&after_created_at=&after_id=: new messages for the open conversation. */
    public function thread(string $role): Response
    {
        return $this->json($role, function (array $user, string $token): array {
            $after = isset($_GET['after_id']) && $_GET['after_id'] !== '' ? ['created_at' => (string) ($_GET['after_created_at'] ?? ''), 'id' => self::uuid($_GET['after_id'])] : (object) [];
            if (is_array($after) && strtotime($after['created_at']) === false) throw new \InvalidArgumentException('cursor');
            $result = $this->db->rpc('get_messages', ['p_conversation_id' => self::uuid($_GET['conversation'] ?? null), 'p_after' => $after], $token);
            if (($result['ok'] ?? false) !== true) return ['ok' => false, 'error_code' => 'not_found'];
            return ['ok' => true, 'data' => ['messages' => array_map(static fn (array $m): array => [
                'id' => $m['id'], 'created_at' => $m['created_at'], 'body' => $m['body'], 'kind' => $m['kind'], 'mine' => (bool) $m['mine'],
                'time' => \App\UI\OrderStatus::time($m['created_at'], 'M j, g:i A'), 'order_no' => $m['order_no'], 'order_id' => $m['order_id'] ?? null,
                'reservation' => isset($m['reservation']['id']) ? ['id' => $m['reservation']['id'], 'state' => $m['reservation']['state'], 'version' => $m['reservation']['version'],
                    'when' => \App\UI\OrderStatus::time($m['reservation']['requested_at'], 'D M j, g:i A'), 'fulfillment' => $m['reservation']['fulfillment'],
                    'items' => implode(', ', array_map(static fn (array $i): string => $i['quantity'] . ' × ' . $i['name'], $m['reservation']['items'])),
                    'note' => $m['reservation']['note'], 'reason' => $m['reservation']['decision_reason'], 'order_no' => $m['reservation']['order_no']] : null,
            ], $result['data']['messages']),
                // First load of a thread also carries the header line and, for customers, the reservation form choices.
                'status' => $result['data']['conversation']['availability']['label'] ?? '', 'products' => $result['data']['products'] ?? null, 'addresses' => $result['data']['addresses'] ?? null]];
        });
    }

    public function requestReservation(): Response
    {
        $station = is_string($_POST['station_id'] ?? null) ? $_POST['station_id'] : '';
        $conversation = is_string($_POST['conversation_id'] ?? null) ? $_POST['conversation_id'] : '';
        return $this->mutate('customer', '/customer/messages?conversation=' . rawurlencode($conversation), 'request_reservation',
            static function () use ($station): array {
                $items = [];
                foreach (\App\Services\Checkout::items($_POST) as $id => $n) $items[] = ['product_id' => $id, 'quantity' => $n];
                if ($items === []) throw new \InvalidArgumentException('items');
                $delivery = ($_POST['fulfillment'] ?? '') === 'delivery';
                $payload = ['requested_at' => self::localTime($_POST['requested_at'] ?? null), 'fulfillment' => $delivery ? 'delivery' : 'pickup', 'items' => $items, 'note' => self::input('note', 500)];
                if ($delivery) $payload['address_id'] = self::uuid($_POST['address_id'] ?? null);
                return ['p_station_id' => self::uuid($station), 'p_payload' => $payload, 'p_request_key' => self::uuid($_POST['request_key'] ?? null)];
            },
            static fn (array $d): array => ['success', 'Reservation request sent. The station will accept, decline or reply here.', '/customer/messages?conversation=' . rawurlencode((string) $d['conversation_id'])]);
    }

    public function cancelReservation(): Response
    {
        return $this->reservation('customer', 'cancel_reservation', [], 'Reservation request cancelled.');
    }

    public function respondReservation(): Response
    {
        $accept = ($_POST['decision'] ?? '') === 'accept';
        return $this->reservation('station-admin', 'respond_reservation', ['p_decision' => $accept ? 'accept' : 'decline', 'p_reason' => self::input('reason', 500)],
            $accept ? 'Reservation accepted. A scheduled order was created.' : 'Reservation declined. The customer sees your reason.');
    }

    private function reservation(string $role, string $function, array $extra, string $message): Response
    {
        $conversation = is_string($_POST['conversation_id'] ?? null) ? $_POST['conversation_id'] : '';
        return $this->mutate($role, '/' . $role . '/messages?conversation=' . rawurlencode($conversation), $function,
            static fn (): array => ['p_reservation_id' => self::uuid($_POST['reservation_id'] ?? null)] + $extra
                + ['p_expected_version' => self::version($_POST['expected_version'] ?? null), 'p_request_key' => self::uuid($_POST['request_key'] ?? null)],
            static fn (): array => ['success', $message, null]);
    }

    /** datetime-local value checked for shape; the database interprets it in Asia/Manila. */
    private static function localTime(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $value) !== 1) throw new \InvalidArgumentException('time');
        return $value;
    }
}
