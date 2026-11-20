<?php
declare(strict_types=1);
namespace App\Services;

use Closure;
use RuntimeException;

/**
 * Road route between two points from an OSRM server (OpenStreetMap roads). Only coordinates are sent: never names,
 * phones or order numbers. The /route service keeps the waypoint order, so the SSTF stop order is never replaced.
 * shortcut: defaults to the public OSRM demo server (demo use, about 1 request per second, no uptime promise); set
 * OSRM_URL to a self-hosted or contracted server before real operations.
 */
final class RoadRouter
{
    private readonly Closure $fetch;

    public function __construct(private readonly string $base = 'https://router.project-osrm.org', ?Closure $fetch = null)
    {
        $this->fetch = $fetch ?? self::curl(...);
    }

    /**
     * @param array{0: float, 1: float} $from [lat, lng]
     * @param array{0: float, 1: float} $to [lat, lng]
     * @return array{distance_m: int, duration_s: int, path: list<array{0: float, 1: float}>, steps: list<array{text: string, distance_m: int}>}
     */
    public function route(array $from, array $to): array
    {
        foreach ([$from, $to] as [$lat, $lng]) {
            if (!is_float($lat) || !is_float($lng) || abs($lat) > 90 || abs($lng) > 180) throw new RuntimeException('Bad coordinates.');
        }
        $points = sprintf('%.6F,%.6F;%.6F,%.6F', $from[1], $from[0], $to[1], $to[0]);
        $data = json_decode(($this->fetch)(rtrim($this->base, '/') . '/route/v1/driving/' . $points . '?overview=full&geometries=geojson&steps=true'), true);
        $route = $data['routes'][0] ?? null;
        if (($data['code'] ?? '') !== 'Ok' || !is_array($route) || !is_array($route['geometry']['coordinates'] ?? null)) throw new RuntimeException('No road route.');
        $path = [];
        foreach (array_slice($route['geometry']['coordinates'], 0, 5000) as $point) {
            if (is_array($point) && is_numeric($point[0] ?? null) && is_numeric($point[1] ?? null)) $path[] = [round((float) $point[1], 6), round((float) $point[0], 6)];
        }
        if (count($path) < 2) throw new RuntimeException('No road route.');
        $steps = [];
        foreach ($route['legs'][0]['steps'] ?? [] as $step) {
            if (count($steps) >= 60 || !is_array($step)) break;
            $steps[] = ['text' => self::instruction($step), 'distance_m' => (int) round((float) ($step['distance'] ?? 0))];
        }
        return ['distance_m' => (int) round((float) ($route['distance'] ?? 0)), 'duration_s' => (int) round((float) ($route['duration'] ?? 0)), 'path' => $path, 'steps' => $steps];
    }

    /** OSRM gives maneuvers, not sentences: "Turn left onto Rizal Street". */
    public static function instruction(array $step): string
    {
        $type = (string) ($step['maneuver']['type'] ?? '');
        $modifier = str_replace('_', ' ', (string) ($step['maneuver']['modifier'] ?? ''));
        $name = trim(mb_substr((string) ($step['name'] ?? ''), 0, 80));
        $onto = $name !== '' ? ' onto ' . $name : '';
        return match ($type) {
            'depart' => 'Start' . ($name !== '' ? ' on ' . $name : '') . ($modifier !== '' ? ', heading ' . $modifier : ''),
            'arrive' => 'Arrive at the delivery point',
            'roundabout', 'rotary' => 'Enter the roundabout' . (isset($step['maneuver']['exit']) ? ' and take exit ' . (int) $step['maneuver']['exit'] : '') . $onto,
            'turn', 'end of road', 'fork', 'on ramp', 'off ramp' => match ($modifier) {
                'straight' => 'Go straight' . $onto,
                'uturn' => 'Make a U-turn' . $onto,
                '' => 'Continue' . $onto,
                default => (str_contains($modifier, 'slight') ? 'Keep ' . str_replace('slight ', '', $modifier) : 'Turn ' . str_replace('sharp ', '', $modifier)) . $onto,
            },
            default => 'Continue' . $onto,
        };
    }

    private static function curl(string $url): string
    {
        $handle = curl_init($url);
        if ($handle === false) throw new RuntimeException('Router unavailable.');
        $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 6, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXFILESIZE => 4000000,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_USERAGENT => 'HydroRoute/1.0 (water delivery routing, Hamtic, Antique)'];
        if (defined('CURLOPT_PROTOCOLS_STR')) $options[CURLOPT_PROTOCOLS_STR] = 'https'; else $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) $options[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
        curl_setopt_array($handle, $options);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        if ($body === false || $status !== 200) throw new RuntimeException('Router request failed (' . $status . ').');
        return (string) $body;
    }
}
