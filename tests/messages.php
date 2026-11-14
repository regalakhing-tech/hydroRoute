<?php
// Phase 7 PHP tests: live messages and reservations controller and page, with a fake Supabase.
// Run: php tests/messages.php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/support/fakes.php';

use App\Controllers\MessageController;
use App\Controllers\UIController;
use App\Core\Session;
use App\Services\CurrentUser;
use App\Services\StationDirectory;
use App\Services\Workspace;

const STN = '11111111-1111-4111-8111-111111111111';
const CONV = '22222222-2222-4222-8222-222222222222';
const MSG = '33333333-3333-4333-8333-333333333333';
const ORD = '44444444-4444-4444-8444-444444444444';
const RKEY = '55555555-5555-4555-8555-555555555555';
const RES = '66666666-6666-4666-8666-666666666666';
const PRD = '77777777-7777-4777-8777-777777777777';

$db = new FakeGateway();
$current = new CurrentUser(new FakeAuth());
Workspace::boot(new Workspace($db, $current));
$chat = new MessageController($db, $current);
$login = static function (string $role): void {
    $_SESSION = [];
    Session::login(['id' => 'u-1', 'role' => $role, 'name' => 'Lia Live', 'email' => 'lia@example.test', 'station' => $role === 'station-admin' ? ['id' => STN, 'name' => 'Live Station', 'slug' => 'live'] : null],
        ['access_token' => 'token-u-1', 'refresh_token' => 'refresh-u-1', 'expires_at' => 2_000_000_000]);
};
$flash = static fn (): array => Session::takeFlash() ?? ['type' => '', 'message' => ''];
$last = static function (string $fn) use ($db): ?array { $c = $db->called('rpc', $fn); return $c === [] ? null : end($c); };
$post = static fn (array $f): array => $_POST = $f + ['_token' => Session::csrfToken(), 'request_key' => RKEY];

$login('rider');
$post(['conversation_id' => CONV, 'body' => 'Hi']);
check($chat->send('customer')->headers['Location'] === '/login/customer' && $db->called('rpc', 'send_message') === [], 'Riders cannot use customer chat');
$login('customer');
$db->results['send_message'] = ['ok' => true, 'data' => ['message_id' => MSG]];
$post(['conversation_id' => CONV, 'body' => "  Hello\nstation  ", 'topic' => 'order', 'order_id' => ORD]);
$r = $chat->send('customer');
check($r->headers['Location'] === '/customer/messages?conversation=' . CONV && $last('send_message')[2] === ['p_conversation_id' => CONV, 'p_body' => "Hello\nstation", 'p_topic' => 'order', 'p_order_id' => ORD, 'p_request_key' => RKEY], 'Message sent with topic and order link');
$post(['conversation_id' => CONV, 'body' => 'Hi', 'topic' => 'spam', 'order_id' => '']);
$chat->send('customer');
check($last('send_message')[2]['p_topic'] === 'general' && $last('send_message')[2]['p_order_id'] === null, 'Unknown topic falls back to general');
$db->results['send_message'] = ['ok' => false, 'error_code' => 'rate_limited', 'field_errors' => ['body' => 'You are sending messages too quickly. Wait a minute and try again.'], 'data' => []];
$chat->send('customer');
check(str_contains($flash()['message'], 'too quickly'), 'Rate limit message');
$post(['conversation_id' => CONV, 'body' => 'Hi', '_token' => 'forged']);
$calls = count($db->called('rpc', 'send_message'));
$chat->send('customer');
check(count($db->called('rpc', 'send_message')) === $calls, 'CSRF failure sends nothing');

$db->results['request_reservation'] = ['ok' => true, 'data' => ['reservation_id' => RES, 'conversation_id' => CONV]];
$post(['station_id' => STN, 'conversation_id' => CONV, 'requested_at' => '2026-12-01T09:30', 'fulfillment' => 'pickup', 'qty' => [PRD => '30'], 'note' => 'Barangay fiesta']);
$chat->requestReservation();
check($last('request_reservation')[2]['p_payload'] === ['requested_at' => '2026-12-01T09:30', 'fulfillment' => 'pickup', 'items' => [['product_id' => PRD, 'quantity' => 30]], 'note' => 'Barangay fiesta'], 'Reservation payload carries no prices');
$post(['station_id' => STN, 'conversation_id' => CONV, 'requested_at' => 'tomorrow', 'fulfillment' => 'pickup', 'qty' => [PRD => '3']]);
$chat->requestReservation();
check($flash()['message'] === 'Check the form and try again.', 'Bad reservation time rejected locally');

$login('station-admin');
$db->results['respond_reservation'] = ['ok' => true, 'data' => ['state' => 'accepted']];
$post(['reservation_id' => RES, 'expected_version' => '1', 'conversation_id' => CONV, 'decision' => 'accept']);
$chat->respondReservation();
check($last('respond_reservation')[2]['p_decision'] === 'accept' && str_contains($flash()['message'], 'scheduled order'), 'Owner accepts a reservation');
$post(['reservation_id' => RES, 'expected_version' => '1', 'conversation_id' => CONV, 'decision' => 'bogus', 'reason' => 'Full']);
$chat->respondReservation();
check($last('respond_reservation')[2]['p_decision'] === 'decline', 'Anything but accept is a decline');

$db->results['get_messages'] = ['ok' => true, 'data' => ['role' => 'station', 'messages' => [['id' => MSG, 'created_at' => '2026-11-16T01:00:00+00:00', 'body' => '<script>x</script>', 'kind' => 'text', 'mine' => false, 'order_no' => null]]]];
$_GET = ['conversation' => CONV, 'after_id' => MSG, 'after_created_at' => '2026-11-16T00:59:00+00:00'];
$body = json_decode($chat->thread('station-admin')->body, true);
check($body['data']['messages'][0]['body'] === '<script>x</script>' && $last('get_messages')[2]['p_after'] === ['created_at' => '2026-11-16T00:59:00+00:00', 'id' => MSG], 'Thread poll uses the cursor; text is inserted with textContent by the page');
$_GET = ['conversation' => CONV, 'after_id' => MSG, 'after_created_at' => 'not a date'];
check($chat->thread('station-admin')->status === 422, 'Bad cursor rejected');

// Page.
$ui = new UIController(false, new StationDirectory($db), new App\Services\Registry($db), $current, fakeStationContext($db, STN));
$db->results['list_inbox'] = ['ok' => true, 'data' => ['conversations' => [['id' => CONV, 'station_id' => STN, 'customer_id' => ORD, 'name' => 'Lia Live', 'last_body' => '<b>Hi</b>', 'last_at' => '2026-11-16T01:00:00+00:00', 'last_mine' => false, 'unread' => 2]]]];
$db->results['get_messages'] = ['ok' => true, 'data' => ['role' => 'station',
    'conversation' => ['id' => CONV, 'station_id' => STN, 'customer_id' => ORD, 'station_name' => 'Live Station', 'customer_name' => 'Lia Live', 'availability' => ['label' => 'Open now', 'accepting_asap' => true]],
    'messages' => [['id' => MSG, 'body' => '<b>Hi</b>', 'kind' => 'text', 'topic' => 'general', 'created_at' => '2026-11-16T01:00:00+00:00', 'mine' => false, 'order_id' => null, 'order_no' => null, 'reservation' => null],
        ['id' => RKEY, 'body' => 'Reservation request', 'kind' => 'reservation', 'topic' => 'reservation', 'created_at' => '2026-11-16T01:01:00+00:00', 'mine' => false, 'order_id' => null, 'order_no' => null,
            'reservation' => ['id' => RES, 'state' => 'requested', 'version' => 1, 'requested_at' => '2026-12-01T01:30:00+00:00', 'fulfillment' => 'pickup', 'note' => '', 'address' => null, 'decision_reason' => null, 'order_id' => null, 'order_no' => null, 'items' => [['name' => 'Round refill', 'quantity' => 30]]]]],
    'orders' => [], 'products' => null, 'addresses' => null]];
$_GET = ['conversation' => CONV];
$page = $ui->page('station-admin', 'messages')->body;
$seed = static fn (string $html): array => preg_match('#data-chat-live-seed>(.*?)</script>#s', $html, $m) === 1 ? json_decode($m[1], true) : [];
check(!str_contains($page, '<b>Hi</b>') && str_contains($page, '\u003Cb\u003EHi') && $seed($page)['threads'][0]['last'] === '<b>Hi</b>', 'Message text is JSON-escaped in the page and inserted with textContent');
$urls = $seed($page)['urls'] ?? [];
check(str_contains($page, 'data-chat-live') && $urls['send'] === '/station-admin/messages/send' && $urls['respond'] === '/station-admin/reservations/respond' && $urls['thread'] === '/station-admin/messages/thread' && $seed($page)['threads'][0]['id'] === CONV, 'Live chat posts messages, decides reservations and polls the thread');
check(!str_contains($page, 'data-chat-seed') && !str_contains($page, 'Maria Santos'), 'Live page never loads the preview chat fixtures');
$login('customer');
$db->results['list_inbox'] = ['ok' => true, 'data' => ['conversations' => []]];
$_GET = ['station' => STN];
$page = $ui->page('customer', 'messages')->body;
check(($seed($page)['urls']['start'] ?? '') === '/customer/messages/start' && str_contains($page, 'action="/customer/reservations"'), 'A customer can start a conversation and request a reservation');

echo $checks . " messaging checks passed.\n";
