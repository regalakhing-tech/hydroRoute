<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Response;
use App\Core\Session;
use App\Services\CurrentUser;
use App\Services\Gateway;
use App\Services\RoadRouter;
use RuntimeException;
use Throwable;

/** The assigned rider's actions: arrival with the automatic fee check, delivery result, trip close, location and road route. */
final class RiderController extends LiveController
{
    private readonly RoadRouter $router;

    public function __construct(Gateway $db, CurrentUser $current, ?RoadRouter $router = null)
    {
        parent::__construct($db, $current);
        $this->router = $router ?? new RoadRouter((string) (getenv('OSRM_URL') ?: 'https://router.project-osrm.org'));
    }

    /**
     * GET /rider/trips/route?lat=&lng=: road route from the rider (or the station, without GPS) to the next open stop
     * of their own active trip, or back to the station once every stop has a result. The destination always comes
     * from the database, never from the request.
     */
    public function route(): Response
    {
        return $this->json('rider', function (array $user, string $token): array {
            $result = $this->db->rpc('get_trip', ['p_trip_id' => null], $token);
            if (($result['ok'] ?? false) !== true) return ['ok' => false, 'error_code' => 'unavailable'];
            if (empty($result['data']['trip'])) return ['ok' => false, 'error_code' => 'not_found'];
            $station = is_array($result['data']['station'] ?? null) ? $result['data']['station'] : [];
            $stops = is_array($result['data']['stops'] ?? null) ? $result['data']['stops'] : [];
            usort($stops, static fn (array $a, array $b): int => (int) $a['sequence'] <=> (int) $b['sequence']);
            $stop = current(array_filter($stops, static fn (array $s): bool => in_array($s['state'] ?? '', ['pending', 'arrived'], true))) ?: null;
            $home = self::point($station['latitude'] ?? null, $station['longitude'] ?? null);
            $to = $stop !== null ? self::point($stop['order']['latitude'] ?? null, $stop['order']['longitude'] ?? null) : $home;
            if ($to === null) return ['ok' => false, 'error_code' => 'no_pin'];
            $here = self::point($_GET['lat'] ?? null, $_GET['lng'] ?? null);
            $from = $here ?? $home;
            if ($from === null) return ['ok' => false, 'error_code' => 'no_origin'];
            try {
                $route = $this->router->route($from, $to);
            } catch (RuntimeException $error) {
                error_log('Road route unavailable: ' . $error->getMessage());
                return ['ok' => false, 'error_code' => 'no_route'];
            }
            // OpenStreetMap misses many barangay roads, so the router can snap to a far highway (huge detour) or one point (0 m).
            $straight = self::meters($from, $to);
            $doubtful = $route['distance_m'] > max(3 * $straight, $straight + 3000) || ($route['distance_m'] < 50 && $straight > 200);
            return ['ok' => true, 'data' => $route + ['from' => $from, 'to' => $to, 'from_station' => $here === null, 'to_station' => $stop === null,
                'straight_m' => (int) round($straight), 'warning' => $doubtful ? 'Mapped roads here may be incomplete. Check the route against the address and landmark.' : null,
                'stop_id' => $stop['id'] ?? null, 'label' => $stop === null ? 'Back to the station' : (string) ($stop['order']['customer_name'] ?? 'Next stop')]];
        });
    }

    private static function meters(array $a, array $b): float
    {
        $rad = M_PI / 180;
        $h = sin(($b[0] - $a[0]) * $rad / 2) ** 2 + cos($a[0] * $rad) * cos($b[0] * $rad) * sin(($b[1] - $a[1]) * $rad / 2) ** 2;
        return 2 * 6371000 * asin(min(1.0, sqrt($h)));
    }

    /** A coordinate pair inside Antique province (the service area plus a margin), or null. */
    private static function point(mixed $lat, mixed $lng): ?array
    {
        if (!is_numeric($lat) || !is_numeric($lng)) return null;
        $lat = (float) $lat; $lng = (float) $lng;
        return $lat >= 10.2 && $lat <= 11.9 && $lng >= 121.7 && $lng <= 122.4 ? [round($lat, 6), round($lng, 6)] : null;
    }
    public function arrived(): Response
    {
        return $this->mutate('rider', '/rider', 'mark_arrived',
            static function (): array {
                $gps = is_string($_POST['latitude'] ?? null) && $_POST['latitude'] !== '' && is_string($_POST['longitude'] ?? null) && $_POST['longitude'] !== '';
                return ['p_stop_id' => self::uuid($_POST['stop_id'] ?? null),
                    'p_latitude' => $gps ? self::coordinate($_POST['latitude'], 90) : null, 'p_longitude' => $gps ? self::coordinate($_POST['longitude'], 180) : null,
                    'p_accuracy' => $gps ? self::coordinate($_POST['accuracy'] ?? '', 100000, 0) : null,
                    'p_request_key' => self::uuid($_POST['request_key'] ?? null), 'p_fallback_reason' => $gps ? null : self::input('fallback_reason', 300)];
            },
            static fn (array $a): array => match ($a['fee_decision'] ?? '') {
                'lowered' => ['success', 'Arrived. The delivery fee was lowered. Collect ' . self::pesos($a['amount_due_centavos']) . '.', null],
                'flagged' => ['info', 'Arrived. Collect the quoted ' . self::pesos($a['amount_due_centavos']) . '; the station will review the distance.', null],
                default => ['success', 'Arrived. Collect ' . self::pesos($a['amount_due_centavos']) . '.', null],
            });
    }

    public function deliver(): Response
    {
        return $this->mutate('rider', '/rider', 'record_delivery',
            static function (): array {
                $delivered = ($_POST['result'] ?? '') === 'delivered';
                $payload = ['stop_id' => self::uuid($_POST['stop_id'] ?? null), 'version' => self::version($_POST['expected_version'] ?? null), 'result' => $delivered ? 'delivered' : 'failed'];
                $payload += $delivered
                    ? ['tendered_centavos' => self::centavos($_POST['tendered'] ?? null), 'empties_returned' => self::count($_POST['empties'] ?? null)]
                    : ['failure_reason' => trim(self::input('failure_reason', 100) . ' ' . self::input('failure_detail', 380))];
                return ['p_payload' => $payload, 'p_request_key' => self::uuid($_POST['request_key'] ?? null)];
            },
            static fn (array $r): array => $r['result'] === 'delivered'
                ? ['success', 'Delivered. Give ' . self::pesos($r['change_centavos']) . ' change.', null]
                : ['info', 'Failed attempt saved. The station will decide on another try.', null]);
    }

    public function closeTrip(): Response
    {
        return $this->mutate('rider', '/rider', 'close_trip',
            static fn (): array => ['p_trip_id' => self::uuid($_POST['trip_id'] ?? null), 'p_expected_version' => self::version($_POST['expected_version'] ?? null),
                'p_reason' => self::input('reason', 300), 'p_request_key' => self::uuid($_POST['request_key'] ?? null)],
            static fn (): array => ['success', 'Trip closed. Welcome back!', null]);
    }

    /** "Re-plan from here": reorder the remaining stops nearest-first from the rider's current position (plan M11). */
    public function replan(): Response
    {
        return $this->mutate('rider', '/rider', 'resequence_trip',
            static function (): array {
                $lat = $_POST['latitude'] ?? ''; $lng = $_POST['longitude'] ?? '';
                if (!is_string($lat) || !is_string($lng) || $lat === '' || $lng === '') throw new \InvalidArgumentException('location');
                return ['p_trip_id' => self::uuid($_POST['trip_id'] ?? null), 'p_latitude' => self::coordinate($lat, 90), 'p_longitude' => self::coordinate($lng, 180),
                    'p_request_key' => self::uuid($_POST['request_key'] ?? null)];
            },
            static fn (array $r): array => ['success', $r['stops'] > 0 ? 'Stops re-planned from where you are. Follow the new order.' : 'Nothing to re-plan: fewer than two stops are left.', null]);
    }

    /** POST /rider/trips/location from the trip page during an active trip; JSON, CSRF-checked. */
    /** Poll for the "My trip" page: changes when a trip is assigned, closed or a stop changes state. */
    public function current(): Response
    {
        return $this->json('rider', function (array $user, string $token): array {
            $result = $this->db->rpc('get_trip', ['p_trip_id' => null], $token);
            if (($result['ok'] ?? false) !== true) return $result;
            $trip = $result['data']['trip'] ?? null;
            $states = array_map(static fn (array $s): string => $s['id'] . ':' . $s['state'], $result['data']['stops'] ?? []);
            return ['ok' => true, 'data' => ['version' => $trip === null ? 'none' : substr(hash('sha256', $trip['id'] . ':' . $trip['version'] . '|' . implode('|', $states)), 0, 16)]];
        });
    }

    public function location(): Response
    {
        $user = Session::user();
        if (($user['role'] ?? null) !== 'rider' || !Session::validCsrf($_POST['_token'] ?? null)) return Response::json(['ok' => false, 'error_code' => 'unauthorized'], 401);
        $token = $this->current->token();
        if ($token === null || $token === '') return Response::json(['ok' => false, 'error_code' => 'unauthorized'], 401);
        session_write_close();
        try {
            $result = $this->db->rpc('record_rider_location', ['p_trip_id' => self::uuid($_POST['trip_id'] ?? null), 'p_latitude' => self::coordinate($_POST['latitude'] ?? '', 90),
                'p_longitude' => self::coordinate($_POST['longitude'] ?? '', 180), 'p_accuracy' => self::coordinate($_POST['accuracy'] ?? '', 100000, 0), 'p_request_key' => self::key()], $token);
        } catch (\InvalidArgumentException) {
            return Response::json(['ok' => false, 'error_code' => 'validation'], 422);
        } catch (Throwable $error) {
            error_log('Rider location failed: ' . $error::class);
            return Response::json(['ok' => false, 'error_code' => self::forbidden($error) ? 'forbidden' : 'unavailable'], self::forbidden($error) ? 403 : 503);
        }
        return Response::json(['ok' => ($result['ok'] ?? false) === true]);
    }

    private static function coordinate(mixed $value, float $limit, ?float $min = null): float
    {
        if (!is_string($value) || !is_numeric($value)) throw new \InvalidArgumentException('coordinate');
        $n = (float) $value;
        if ($n > $limit || $n < ($min ?? -$limit)) throw new \InvalidArgumentException('coordinate');
        return round($n, 7);
    }

    private static function pesos(mixed $centavos): string
    {
        return \App\UI\OrderStatus::pesos((int) $centavos);
    }
}
