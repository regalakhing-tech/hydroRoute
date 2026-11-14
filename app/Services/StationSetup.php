<?php
declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/** Checked station setup calls. Money is parsed as decimal text into integer centavos. */
final class StationSetup
{
    public const OPERATIONS = [
        'profile' => 'save_station_profile', 'location' => 'save_station_location',
        'serviceAreas' => 'save_station_service_areas', 'settings' => 'save_station_settings',
        'fees' => 'save_delivery_fee_rules', 'product' => 'save_product',
        'hours' => 'save_station_hours', 'closure' => 'set_station_closure',
        'cancelClosure' => 'cancel_station_closure', 'reopen' => 'reopen_station',
    ];
    public const FIELDS = [
        'profile' => ['name','contact_name','phone','email','address','barangay_id','description'],
        'location' => ['latitude','longitude'], 'serviceAreas' => ['barangay_ids'],
        'settings' => ['max_order_units','max_distance_km','booking_days','duplicate_minutes','overdue_minutes','institution_bonus_enabled','urgency_asap','urgency_soon','urgency_later','urgency_immediate_minutes','urgency_soon_minutes','aging_interval_minutes','aging_points','aging_cap','institution_bonus'],
        'fees' => ['bands','max_distance_km'],
        'product' => ['product_id','name','pack_text','description','image_key','price','returnable','load_units','active'],
        'hours' => ['windows','confirm_closed'],
        'closure' => ['starts_at','ends_at','is_manual','closure_id','closure_version','mode','public_notice'],
        'cancelClosure' => ['closure_id','reason'], 'reopen' => [], 'switchStation' => ['station_id'],
    ];
    private readonly StationContext $context;

    public function __construct(private readonly Gateway $db, ?StationContext $context = null)
    {
        $this->context = $context ?? new StationContext($db);
    }

    public function load(string $token, string $stationId): array
    {
        $data = $this->context->load($token, $stationId);
        $data['memberships'] = self::ownerMemberships($data);
        $member = false;
        foreach ($data['memberships'] as $membership) {
            if ($membership['station_id'] === $stationId) {
                $member = true;
            }
        }
        if (!$member) {
            throw new RuntimeException('Station setup unavailable.');
        }
        foreach (['settings','barangays','service_areas','products','opening_hours','closures','availability','fee_rules'] as $field) {
            if (!is_array($data[$field] ?? null)) {
                throw new RuntimeException('Station setup unavailable.');
            }
        }
        return $data;
    }

    /** Safe owner choices before a station has been selected; no station setup is authorized here. */
    public function choices(string $token): array
    {
        return self::ownerMemberships($this->context->load($token, null));
    }

    private static function ownerMemberships(array $data): array
    {
        $roles = $data['actor']['roles'];
        $memberships = $data['memberships'];
        if (!array_is_list($roles) || !array_is_list($memberships)
            || !in_array('station_admin', $roles, true) || in_array('super_admin', $roles, true)) {
            throw new RuntimeException('Station setup unavailable.');
        }
        $choices = [];
        foreach ($memberships as $membership) {
            if (!is_array($membership) || !self::isUuid($membership['station_id'] ?? null)
                || !is_string($membership['station_name'] ?? null) || trim($membership['station_name']) === ''
                || !is_string($membership['slug'] ?? null) || trim($membership['slug']) === ''
                || !is_string($membership['role'] ?? null) || $membership['role'] === '') {
                throw new RuntimeException('Station setup unavailable.');
            }
            if ($membership['role'] !== 'station_admin') continue;
            $choices[] = [
                'station_id'=>$membership['station_id'], 'station_name'=>$membership['station_name'],
                'slug'=>$membership['slug'], 'role'=>'station_admin',
            ];
        }
        return $choices;
    }

    public static function requestKey(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    public static function isUuid(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di', $value) === 1;
    }

    /** Caller supplies a server-derived station; all payload and RPC names are allowlisted here. */
    public function mutate(string $token, string $stationId, string $operation, array $input): array
    {
        if (!isset(self::OPERATIONS[$operation])) {
            throw new InvalidArgumentException('operation');
        }
        $context = $this->load($token, $stationId);
        try {
            $key = self::uuid($input['request_key'] ?? null, 'request_key');
            $creating = $operation === 'product' && self::text($input, 'product_id', 36) === '';
            $version = $creating ? null : self::integer($input['expected_version'] ?? null, 'expected_version', 1, 2147483647);
            $parameters = ['p_station_id' => $stationId];
            switch ($operation) {
                case 'profile':
                    $payload = [];
                    foreach (self::FIELDS['profile'] as $field) {
                        $payload[$field] = self::text($input, $field, $field === 'description' ? 2000 : ($field === 'address' ? 500 : 254), in_array($field, ['name','contact_name','phone','address','barangay_id'], true));
                    }
                    $payload['barangay_id'] = self::knownId($payload['barangay_id'], 'barangay_id', $context['barangays']);
                    $payload['phone'] = SignupVerification::phone($payload['phone']) ?? throw new InvalidArgumentException('phone');
                    if ($payload['email'] !== '') {
                        $payload['email'] = SignupVerification::email($payload['email']) ?? throw new InvalidArgumentException('email');
                    }
                    $parameters['p_payload'] = $payload;
                    break;
                case 'location':
                    $parameters['p_latitude'] = self::decimal($input['latitude'] ?? null, 'latitude', 10.584854, 10.746484);
                    $parameters['p_longitude'] = self::decimal($input['longitude'] ?? null, 'longitude', 121.961241, 122.073730);
                    break;
                case 'serviceAreas':
                    $ids = self::rows($input['barangay_ids'] ?? [], 'barangay_ids', 47);
                    $parameters['p_barangay_ids'] = [];
                    foreach ($ids as $id) {
                        $parameters['p_barangay_ids'][] = self::knownId($id, 'barangay_ids', $context['barangays']);
                    }
                    $parameters['p_barangay_ids'] = array_values(array_unique($parameters['p_barangay_ids']));
                    break;
                case 'settings':
                    $payload = [];
                    foreach (self::FIELDS['settings'] as $field) {
                        $payload[$field] = match ($field) {
                            'institution_bonus_enabled' => self::boolean($input[$field] ?? '0', $field),
                            'max_distance_km' => self::decimal($input[$field] ?? null, $field, 0.001, 1000),
                            'booking_days' => self::integer($input[$field] ?? null, $field, 1, 7),
                            default => self::integer($input[$field] ?? null, $field, in_array($field, ['max_order_units','duplicate_minutes','overdue_minutes','urgency_immediate_minutes','urgency_soon_minutes','aging_interval_minutes'], true) ? 1 : 0, 1000000),
                        };
                    }
                    if ($payload['urgency_soon_minutes'] < $payload['urgency_immediate_minutes']) {
                        throw new InvalidArgumentException('urgency_soon_minutes');
                    }
                    $parameters['p_payload'] = $payload;
                    break;
                case 'product':
                    $parameters['p_product_id'] = $creating ? null : self::knownId($input['product_id'] ?? null, 'product_id', $context['products']);
                    $image = self::text($input, 'image_key', 40, true);
                    if (!in_array($image, ['water-jug','faucet-container','water-bottle'], true)) {
                        throw new InvalidArgumentException('image_key');
                    }
                    $load = self::text($input, 'load_units', 10);
                    $parameters['p_payload'] = [
                        'name' => self::text($input, 'name', 120, true),
                        'pack_text' => self::text($input, 'pack_text', 120, true),
                        'description' => self::text($input, 'description', 2000), 'image_key' => $image,
                        'price_centavos' => self::pesos($input['price'] ?? null, 'price'),
                        'returnable' => self::boolean($input['returnable'] ?? '0', 'returnable'),
                        'load_units' => $load === '' ? null : self::integer($load, 'load_units', 1, 1000000),
                        'active' => self::boolean($input['active'] ?? '0', 'active'),
                    ];
                    break;
                case 'fees':
                    $parameters['p_max_km'] = self::decimal($input['max_distance_km'] ?? null, 'max_distance_km', 0.001, 1000);
                    $bands = self::rows($input['bands'] ?? null, 'bands', 50);
                    if ($bands === []) throw new InvalidArgumentException('bands');
                    $parameters['p_bands'] = [];
                    foreach ($bands as $row) {
                        if (!is_array($row)) throw new InvalidArgumentException('bands');
                        $max = self::text($row, 'max_km', 30);
                        $parameters['p_bands'][] = [
                            'min_km' => self::decimal($row['min_km'] ?? null, 'bands', 0, 100000),
                            'max_km' => $max === '' ? null : self::decimal($max, 'bands', 0, 100000),
                            'min_inclusive' => self::boolean($row['min_inclusive'] ?? '0', 'bands'),
                            'max_inclusive' => self::boolean($row['max_inclusive'] ?? '0', 'bands'),
                            'fee_centavos' => self::pesos($row['fee'] ?? null, 'bands'),
                        ];
                    }
                    self::checkBands($parameters['p_bands']);
                    break;
                case 'hours':
                    [$parameters['p_windows'], $parameters['p_cutoffs']] = self::hours($input);
                    break;
                case 'closure':
                    $manual = self::boolean($input['is_manual'] ?? '0', 'is_manual');
                    $start = self::instant($input['starts_at'] ?? null, 'starts_at');
                    $end = self::text($input, 'ends_at', 60);
                    $end = $end === '' && $manual ? null : self::instant($end, 'ends_at');
                    if (($end !== null && new DateTimeImmutable($end) <= new DateTimeImmutable($start))
                        || ($manual && new DateTimeImmutable($start) > new DateTimeImmutable('now'))) {
                        throw new InvalidArgumentException('ends_at');
                    }
                    $interval = ['starts_at' => $start, 'ends_at' => $end, 'is_manual' => $manual];
                    if (self::text($input, 'closure_id', 36) !== '') {
                        $interval['closure_id'] = self::knownId($input['closure_id'], 'closure_id', $context['closures']);
                        $interval['closure_version'] = self::integer($input['closure_version'] ?? null, 'closure_version', 1, 2147483647);
                    }
                    $mode = self::text($input, 'mode', 40, true);
                    if (!in_array($mode, ['closed','maintenance','no_operations'], true)) throw new InvalidArgumentException('mode');
                    $parameters += ['p_interval' => $interval, 'p_mode' => $mode, 'p_public_notice' => self::text($input, 'public_notice', 500)];
                    break;
                case 'cancelClosure':
                    unset($parameters['p_station_id']);
                    $parameters['p_closure_id'] = self::knownId($input['closure_id'] ?? null, 'closure_id', $context['closures']);
                    $parameters['p_reason'] = self::text($input, 'reason', 500);
                    break;
            }
            $parameters['p_expected_version'] = $version;
            $parameters['p_request_key'] = $key;
        } catch (InvalidArgumentException $error) {
            return ['ok' => false, 'data' => [], 'error_code' => 'invalid_input', 'field_errors' => [$error->getMessage() => 'Check this value.']];
        }
        // Do not compare expected versions locally: the database must check committed replays first.
        $result = $this->db->rpc(self::OPERATIONS[$operation], $parameters, $token);
        if (!is_bool($result['ok'] ?? null) || !is_array($result['data'] ?? null)
            || !is_array($result['field_errors'] ?? null)) {
            throw new RuntimeException('Station save outcome unavailable.');
        }
        if ($result['ok'] === true && (($result['data']['station_id'] ?? null) !== $stationId
            || !is_int($result['data']['version'] ?? null) || !is_int($result['data']['availability_version'] ?? null))) {
            throw new RuntimeException('Station save outcome unavailable.');
        }
        return $result;
    }

    public static function oldInput(string $operation, array $input): array
    {
        $out = ['_form' => $operation];
        foreach (array_merge(self::FIELDS[$operation] ?? [], ['request_key','expected_version']) as $field) {
            if (!array_key_exists($field, $input)) continue;
            $value = $input[$field];
            if (is_array($value) && in_array($field, ['bands','windows'], true)) {
                $allowed = $field === 'bands'
                    ? ['min_km','max_km','min_inclusive','max_inclusive','fee']
                    : ['weekday','window_no','opens_at','closes_at','order_cutoff','delivery_cutoff','enabled'];
                $rows = [];
                foreach (array_slice($value, 0, 50) as $row) {
                    if (!is_array($row)) continue;
                    $safe = [];
                    foreach ($allowed as $key) if (is_string($row[$key] ?? null)) $safe[$key] = self::bounded($row[$key]);
                    $rows[] = $safe;
                }
                $out[$field] = $rows;
            } elseif ($field === 'barangay_ids' && is_array($value)) {
                $out[$field] = array_values(array_filter(array_slice($value, 0, 47), static fn (mixed $id): bool => self::isUuid($id)));
            } elseif (is_string($value)) {
                $out[$field] = self::bounded($value);
            }
        }
        return $out;
    }

    private static function bounded(mixed $value, int $depth = 0): mixed
    {
        if (is_string($value)) return mb_substr($value, 0, 3000);
        if (!is_array($value) || $depth >= 3) return '';
        $out = [];
        foreach (array_slice($value, 0, 100, true) as $key => $item) {
            if (is_int($key) || (is_string($key) && preg_match('/^[a-z_]{1,40}$/D', $key))) $out[$key] = self::bounded($item, $depth + 1);
        }
        return $out;
    }

    private static function text(array $input, string $field, int $max, bool $required = false): string
    {
        $value = $input[$field] ?? '';
        if (!is_string($value) || mb_strlen($value) > $max) throw new InvalidArgumentException($field);
        $value = trim($value);
        if ($required && $value === '') throw new InvalidArgumentException($field);
        return $value;
    }

    private static function uuid(mixed $value, string $field): string
    {
        if (!self::isUuid($value)) throw new InvalidArgumentException($field);
        return $value;
    }

    private static function knownId(mixed $value, string $field, array $rows): string
    {
        $value = self::uuid($value, $field);
        foreach ($rows as $row) if (is_array($row) && ($row['id'] ?? null) === $value) return $value;
        throw new InvalidArgumentException($field);
    }

    private static function integer(mixed $value, string $field, int $min, int $max): int
    {
        if (!is_string($value) || !preg_match('/^\d{1,10}$/D', $value) || (int)$value < $min || (int)$value > $max) throw new InvalidArgumentException($field);
        return (int)$value;
    }

    private static function decimal(mixed $value, string $field, float $min, float $max): string
    {
        if (!is_string($value) || !preg_match('/^\d{1,6}(?:\.\d{1,6})?$/D', $value) || (float)$value < $min || (float)$value > $max) throw new InvalidArgumentException($field);
        return $value;
    }

    private static function pesos(mixed $value, string $field): int
    {
        if (!is_string($value) || !preg_match('/^(\d{1,8})(?:\.(\d{1,2}))?$/D', $value, $matches)) throw new InvalidArgumentException($field);
        $centavos = (int)$matches[1] * 100 + (int)str_pad($matches[2] ?? '', 2, '0');
        if ($centavos > 2147483647) throw new InvalidArgumentException($field);
        return $centavos;
    }

    private static function boolean(mixed $value, string $field): bool
    {
        return match ($value) {
            true, '1', 'on', 'yes' => true,
            false, '0', '' => false,
            default => throw new InvalidArgumentException($field),
        };
    }

    private static function rows(mixed $value, string $field, int $limit): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $limit) throw new InvalidArgumentException($field);
        return $value;
    }

    private static function checkBands(array $bands): void
    {
        $previous = null;
        foreach ($bands as $index => $band) {
            if (($index === 0 && ((float)$band['min_km'] !== 0.0 || !$band['min_inclusive']))
                || ($previous !== null && ($previous['max_km'] === null || (float)$previous['max_km'] !== (float)$band['min_km'] || $previous['max_inclusive'] === $band['min_inclusive']))
                || ($band['max_km'] !== null && (float)$band['max_km'] <= (float)$band['min_km'])
                || ($band['max_km'] === null && ($index !== count($bands)-1 || $band['max_inclusive']))) {
                throw new InvalidArgumentException('bands');
            }
            $previous = $band;
        }
        if ($previous['max_km'] !== null) throw new InvalidArgumentException('bands');
    }

    private static function hours(array $input): array
    {
        $windows = []; $cutoffs = []; $seen = [];
        foreach (self::rows($input['windows'] ?? [], 'windows', 14) as $row) {
            if (!is_array($row)) throw new InvalidArgumentException('windows');
            $day = self::integer($row['weekday'] ?? null, 'windows', 0, 6);
            $number = self::integer($row['window_no'] ?? null, 'windows', 1, 2);
            if (isset($seen[$day][$number])) throw new InvalidArgumentException('windows');
            $seen[$day][$number] = true;
            if (!self::boolean($row['enabled'] ?? '0', 'windows')) continue;
            $open = self::time($row['opens_at'] ?? null); $close = self::time($row['closes_at'] ?? null);
            if ($open >= $close) throw new InvalidArgumentException('windows');
            foreach ($windows as $window) if ($window['weekday'] === $day && $open < $window['closes_at'] && $close > $window['opens_at']) throw new InvalidArgumentException('windows');
            $windows[] = ['weekday'=>$day,'window_no'=>$number,'opens_at'=>$open,'closes_at'=>$close,'enabled'=>true];
            $cutoff = ['weekday'=>$day,'window_no'=>$number];
            foreach (['order_cutoff','delivery_cutoff'] as $field) {
                $value = self::text($row, $field, 8);
                $cutoff[$field] = $value === '' ? null : self::time($value);
                if ($cutoff[$field] !== null && ($cutoff[$field] < $open || $cutoff[$field] >= $close)) throw new InvalidArgumentException('windows');
            }
            $cutoffs[] = $cutoff;
        }
        if ($windows === [] && !self::boolean($input['confirm_closed'] ?? '0', 'confirm_closed')) throw new InvalidArgumentException('confirm_closed');
        return [$windows, $cutoffs];
    }

    private static function time(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/D', $value)) throw new InvalidArgumentException('windows');
        return strlen($value) === 5 ? $value . ':00' : $value;
    }

    private static function instant(mixed $value, string $field): string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})?$/D', $value)) throw new InvalidArgumentException($field);
        try {
            $date = new DateTimeImmutable($value, new DateTimeZone('Asia/Manila'));
            $errors = DateTimeImmutable::getLastErrors();
            if (is_array($errors) && ($errors['warning_count'] || $errors['error_count'])) throw new InvalidArgumentException($field);
            return $date->format('Y-m-d\TH:i:sP');
        } catch (\Exception) {
            throw new InvalidArgumentException($field);
        }
    }
}
