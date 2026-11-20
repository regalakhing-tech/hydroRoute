<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Session;
use RuntimeException;
use Throwable;

/**
 * Loads the live data a customer, station-admin or rider page shows when APP_PREVIEW=false.
 * Views call Workspace::get() so live pages never touch the preview fixtures. A failed read
 * becomes ['error' => ...] and the page shows an honest "could not load" state.
 */
final class Workspace
{
    private static ?self $instance = null;

    public function __construct(private readonly Gateway $db, private readonly CurrentUser $current) {}

    public static function boot(self $workspace): void { self::$instance = $workspace; }

    public static function get(): self
    {
        return self::$instance ?? throw new RuntimeException('Live workspace is not configured.');
    }

    /** Runs a loader; any failure becomes an error state instead of a 500 page or sample data. */
    public function load(callable $loader): array
    {
        try {
            return $loader($this);
        } catch (Throwable $error) {
            error_log('Live page unavailable: ' . $error::class);
            return ['error' => 'This page could not be loaded. Please refresh, or log in again if it keeps happening.'];
        }
    }

    public function token(): string
    {
        $token = $this->current->token();
        if ($token === null || $token === '') throw new RuntimeException('Session ended.');
        return $token;
    }

    /** Calls a read RPC and returns its data; a non-ok envelope is an error unless $allowed lists its code. */
    public function call(string $function, array $parameters, array $allowed = []): array
    {
        $result = $this->db->rpc($function, $parameters, $this->token());
        if (($result['ok'] ?? false) !== true) {
            if (in_array($result['error_code'] ?? null, $allowed, true)) return ['error_code' => $result['error_code']];
            throw new RuntimeException($function . ' returned ' . (string) ($result['error_code'] ?? 'no envelope'));
        }
        return is_array($result['data'] ?? null) ? $result['data'] : [];
    }

    public function stationId(): string
    {
        $id = Session::user()['station']['id'] ?? null;
        if (!is_string($id) || $id === '') throw new RuntimeException('No station in session.');
        return $id;
    }

    // Customer -------------------------------------------------------------------------------------

    public function checkout(array $query): array
    {
        $stationId = is_string($query['station'] ?? null) ? strtolower($query['station']) : '';
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $stationId) !== 1) return ['missing' => true];
        $token = $this->token();
        $station = $this->db->select('stations', ['select' => 'id,name,address,barangay,phone', 'id' => 'eq.' . $stationId], $token)[0] ?? null;
        if ($station === null) return ['missing' => true];
        $availability = $this->db->rpc('get_station_availability', ['p_station_id' => $stationId, 'p_requested_at' => null], $token)['data'] ?? [];
        $products = $this->db->select('products', ['select' => 'id,name,pack_text,description,image_key,price_centavos,returnable,load_units',
            'station_id' => 'eq.' . $stationId, 'active' => 'eq.true', 'order' => 'name.asc'], $token);
        $addresses = $this->db->select('addresses', ['select' => 'id,label,address,latitude,longitude,is_default', 'deleted_at' => 'is.null', 'order' => 'is_default.desc,label.asc'], $token);
        $known = array_column($products, null, 'id');
        $items = array_intersect_key(Checkout::items($query), $known);
        $form = Checkout::form($query);
        if ($form['address_id'] === '' || !in_array($form['address_id'], array_column($addresses, 'id'), true)) {
            $pinned = array_values(array_filter($addresses, static fn (array $a): bool => $a['latitude'] !== null));
            $form['address_id'] = (string) ($pinned[0]['id'] ?? $addresses[0]['id'] ?? '');
        }
        $quote = $items === [] ? null : $this->db->rpc('quote_order', ['p_station_id' => $stationId, 'p_payload' => Checkout::payload($items, $form)], $token);
        // Fee per pinned address, so switching addresses updates the total at once; the order total is still recalculated by the database.
        $fees = [];
        foreach ($addresses as $a) {
            if ($a['latitude'] === null) continue;
            $terms = $this->db->rpc('quote_delivery', ['p_station_id' => $stationId, 'p_address_id' => $a['id']], $token)['data'] ?? [];
            if (!empty($terms['eligible'])) $fees[$a['id']] = ((int) $terms['fee_centavos']) / 100;
        }
        // Other customers' 5-minute slots (times only) and refills left per date; the database rechecks both on submit.
        try {
            $booked = $this->db->rpc('get_booked_slots', ['p_station_id' => $stationId], $token)['data'] ?? [];
        } catch (RuntimeException) {
            $booked = []; // Checkout still works; a taken time is then refused when the order is placed.
        }
        return ['station' => $station, 'availability' => is_array($availability) ? $availability : [], 'products' => $known, 'addresses' => $addresses,
            'items' => $items, 'form' => $form, 'quote' => $quote, 'fees' => $fees,
            'booked' => ['slots' => array_values(array_filter((array) ($booked['slots'] ?? []), 'is_string')), 'refills_left' => (array) ($booked['refills_left'] ?? []),
                'rules' => (array) ($booked['rules'] ?? [])]];
    }

    /** Customer home page. */
    public function home(): array
    {
        return $this->call('get_customer_overview', []) + ['stations' => array_slice((new StationDirectory($this->db))->approved(), 0, 6)];
    }

    public function directory(): array
    {
        return ['stations' => (new StationDirectory($this->db))->approved()];
    }

    /** Public station page by slug or id: availability, active products, served barangays and the default-address quote. */
    public function station(mixed $ref): array
    {
        if (!is_string($ref) || preg_match('/^[a-z0-9][a-z0-9-]{1,59}$|^[0-9a-f-]{36}$/D', $ref) !== 1) return ['missing' => true];
        $token = $this->token();
        $byId = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $ref) === 1;
        $station = $this->db->select('stations', ['select' => 'id,name,slug,address,barangay,phone,description', $byId ? 'id' : 'slug' => 'eq.' . $ref], $token)[0] ?? null;
        if ($station === null) return ['missing' => true];
        $id = $station['id'];
        $availability = $this->db->rpc('get_station_availability', ['p_station_id' => $id, 'p_requested_at' => null], $token)['data'] ?? [];
        $products = $this->db->select('products', ['select' => 'id,name,pack_text,description,image_key,price_centavos,returnable,load_units',
            'station_id' => 'eq.' . $id, 'active' => 'eq.true', 'order' => 'name.asc'], $token);
        $served = $this->db->select('station_service_areas', ['select' => 'barangay_id,barangays(name)', 'station_id' => 'eq.' . $id, 'served' => 'eq.true'], $token);
        $address = $this->db->select('addresses', ['select' => 'id,label,address,latitude', 'is_default' => 'eq.true', 'deleted_at' => 'is.null'], $token)[0] ?? null;
        $quote = $address !== null && $address['latitude'] !== null
            ? ($this->db->rpc('quote_delivery', ['p_station_id' => $id, 'p_address_id' => $address['id']], $token)['data'] ?? null) : null;
        return ['station' => $station, 'availability' => is_array($availability) ? $availability : [], 'products' => $products,
            'served' => array_values(array_filter(array_map(static fn (array $r): string => (string) ($r['barangays']['name'] ?? ''), $served))),
            'address' => $address, 'quote' => $quote];
    }

    public function account(): array
    {
        $id = Session::user()['id'] ?? '';
        $profile = $this->db->select('profiles', ['select' => 'id,display_name,phone,order_updates,station_updates,version', 'id' => 'eq.' . $id], $this->token())[0] ?? null;
        if ($profile === null) throw new RuntimeException('Profile unavailable.');
        $token = $this->token();
        $addresses = $this->db->select('addresses', ['select' => 'id,label,address,landmark,is_default', 'deleted_at' => 'is.null', 'order' => 'is_default.desc,label.asc'], $token);
        $orders = $this->db->select('orders', ['select' => 'id,created_at', 'order' => 'created_at.asc'], $token);
        return ['profile' => $profile, 'email' => (string) (Session::user()['email'] ?? ''), 'addresses' => $addresses, 'orders' => count($orders),
            'since' => isset($orders[0]['created_at']) ? substr((string) $orders[0]['created_at'], 0, 4) : date('Y')];
    }

    public function addresses(): array
    {
        $token = $this->token();
        return ['addresses' => $this->db->select('addresses', ['select' => 'id,label,address,street,landmark,barangay_id,latitude,longitude,pin_source,is_default,version',
                'deleted_at' => 'is.null', 'order' => 'is_default.desc,label.asc'], $token),
            'barangays' => $this->db->select('barangays', ['select' => 'id,name,psgc_code', 'active' => 'eq.true', 'order' => 'name.asc'], $token)];
    }

    public function orders(string $group): array
    {
        return $this->call('list_customer_orders', ['p_filters' => ['group' => $group], 'p_cursor' => (object) []]);
    }

    public function order(mixed $id): array
    {
        if (!is_string($id) || preg_match('/^[0-9a-f-]{36}$/D', $id) !== 1) return ['error_code' => 'not_found'];
        $order = $this->call('get_customer_order', ['p_order_id' => $id], ['not_found']);
        // The "Change time" form needs the station's bookable windows and other customers' taken slots.
        if (!empty($order['can_reschedule']) && isset($order['station']['id'])) {
            try {
                $order['availability'] = $this->call('get_station_availability', ['p_station_id' => $order['station']['id'], 'p_requested_at' => null]);
                $order['booked'] = $this->call('get_booked_slots', ['p_station_id' => $order['station']['id']]);
            } catch (RuntimeException) {
                $order['can_reschedule'] = false; // no windows to offer; the rest of the page still works
            }
        }
        return $order;
    }

    public function notifications(?string $stationId = null): array
    {
        return $this->call('list_notifications', ['p_station_id' => $stationId, 'p_cursor' => (object) []]);
    }

    // Station admin --------------------------------------------------------------------------------

    public function overview(): array
    {
        return $this->call('get_station_overview', ['p_station_id' => $this->stationId()]);
    }

    /** Changes whenever an order enters, leaves or changes in any queue list; drives page reloads. */
    public static function fingerprint(array $queue): string
    {
        $rows = [];
        foreach (['preparing', 'queue', 'ready', 'on_hold', 'scheduled', 'awaiting_completion', 'reviews'] as $list) {
            foreach ($queue[$list] ?? [] as $row) $rows[] = $list . ':' . ($row['id'] ?? '') . ':' . ($row['version'] ?? '');
        }
        sort($rows);
        return substr(hash('sha256', implode('|', $rows)), 0, 16);
    }

    public function queue(): array
    {
        $queue = $this->call('get_preparation_queue', ['p_station_id' => $this->stationId()]);
        // Recent cancellations for the "Cancelled" filter; the queue still works before 20261122000300 is applied.
        try {
            $queue['cancelled'] = $this->call('get_cancelled_orders', ['p_station_id' => $this->stationId()])['orders'] ?? [];
        } catch (RuntimeException) {
            $queue['cancelled'] = [];
        }
        return $queue;
    }

    public function dispatch(): array
    {
        $station = $this->stationId();
        return $this->call('get_dispatch_queue', ['p_station_id' => $station]) + ['preview' => $this->call('preview_trip', ['p_station_id' => $station])];
    }

    public function stationOrder(mixed $id): array
    {
        if (!is_string($id) || preg_match('/^[0-9a-f-]{36}$/D', $id) !== 1) return ['error_code' => 'not_found'];
        return $this->call('get_station_order', ['p_order_id' => $id], ['not_found']);
    }

    public function customers(string $search): array
    {
        return $this->call('get_station_customers', ['p_station_id' => $this->stationId(), 'p_filters' => ['search' => $search], 'p_cursor' => (object) []]);
    }

    /** A trip for its station's owner (by id) or the signed-in rider's active trip (null id). */
    public function trip(?string $id): array
    {
        if ($id !== null && preg_match('/^[0-9a-f-]{36}$/D', $id) !== 1) return ['error_code' => 'not_found'];
        return $this->call('get_trip', ['p_trip_id' => $id]);
    }

    public function report(string $kind, string $start, string $end): array
    {
        return $this->call('get_station_report', ['p_station_id' => $this->stationId(), 'p_report_kind' => $kind, 'p_start_date' => $start, 'p_end_date' => $end, 'p_filters' => (object) []]);
    }

    // Messages (customer: null station; station admin: own station) -----------------------------------

    public function inbox(bool $station, array $filters): array
    {
        return $this->call('list_inbox', ['p_station_id' => $station ? $this->stationId() : null, 'p_filters' => $filters, 'p_cursor' => (object) []]);
    }

    public function thread(mixed $id): array
    {
        if (!is_string($id) || preg_match('/^[0-9a-f-]{36}$/D', $id) !== 1) return ['error_code' => 'not_found'];
        return $this->call('get_messages', ['p_conversation_id' => $id, 'p_after' => (object) []]);
    }

    public function riderAccount(): array
    {
        $token = $this->token();
        $driver = $this->db->select('drivers', ['select' => 'full_name,phone,email,vehicle,plate_number,capacity,active,station_id', 'user_id' => 'eq.' . (Session::user()['id'] ?? '')], $token)[0] ?? null;
        $station = $driver ? ($this->db->select('stations', ['select' => 'name', 'id' => 'eq.' . $driver['station_id']], $token)[0]['name'] ?? '') : '';
        return ['driver' => $driver, 'station' => $station];
    }

    public function riderHistory(): array
    {
        return $this->call('list_rider_history', ['p_cursor' => (object) []]);
    }
}
