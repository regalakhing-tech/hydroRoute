<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/** Request-local cache of actor-checked context; never substitutes another station. */
final class StationContext
{
    private array $cache = [];

    public function __construct(private readonly Gateway $db) {}

    public function load(string $token, ?string $stationId): array
    {
        if ($token === '' || ($stationId !== null && !StationSetup::isUuid($stationId))) {
            throw new RuntimeException('Station context unavailable.');
        }
        $key = hash('sha256', $token . "\0" . ($stationId ?? ''));
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        $result = $this->db->rpc('get_operation_context', ['p_station_id' => $stationId], $token);
        $data = $result['data'] ?? null;
        if (($result['ok'] ?? null) !== true || !is_array($data)
            || !is_array($data['actor'] ?? null) || !is_array($data['actor']['roles'] ?? null)
            || !is_array($data['memberships'] ?? null)
            || ($stationId !== null && ($data['station']['id'] ?? null) !== $stationId)) {
            throw new RuntimeException('Station context unavailable.');
        }
        return $this->cache[$key] = $data;
    }
}
