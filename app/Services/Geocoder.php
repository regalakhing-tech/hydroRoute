<?php
declare(strict_types=1);
namespace App\Services;

use Closure;
use RuntimeException;

/**
 * Street suggestion for a map pin from OpenStreetMap Nominatim (reverse geocoding).
 * Only the coordinates are sent; no account details. The barangay is detected locally from boundaries.
 * shortcut: no shared rate limit; Nominatim allows about 1 request per second, fine for one station town; add a cache/queue if traffic grows.
 */
final class Geocoder
{
    private readonly Closure $fetch;

    public function __construct(private readonly string $userAgent, ?Closure $fetch = null)
    {
        $this->fetch = $fetch ?? self::curl(...);
    }

    /** @return array{street: string, place: string, areas: list<string>} */
    public function reverse(float $lat, float $lng): array
    {
        $url = 'https://nominatim.openstreetmap.org/reverse?' . http_build_query(['format' => 'jsonv2', 'lat' => round($lat, 6), 'lon' => round($lng, 6),
            'zoom' => 18, 'addressdetails' => 1, 'accept-language' => 'en']);
        $data = json_decode(($this->fetch)($url, $this->userAgent), true);
        $a = is_array($data['address'] ?? null) ? $data['address'] : [];
        $pick = static function (array $keys) use ($a): string {
            foreach ($keys as $key) if (is_string($a[$key] ?? null) && trim($a[$key]) !== '') return trim($a[$key]);
            return '';
        };
        // OpenStreetMap usually tags Philippine barangays as village or suburb; the page matches these to the official list.
        $areas = array_values(array_unique(array_filter(array_map(static fn (string $k): string => is_string($a[$k] ?? null) ? trim($a[$k]) : '', ['village', 'suburb', 'quarter', 'neighbourhood', 'hamlet']))));
        return ['street' => mb_substr($pick(['road', 'pedestrian', 'footway', 'residential']), 0, 120),
            'place' => mb_substr($pick(['hamlet', 'neighbourhood']), 0, 120), 'areas' => array_slice($areas, 0, 5)];
    }

    private static function curl(string $url, string $userAgent): string
    {
        $handle = curl_init($url);
        if ($handle === false) throw new RuntimeException('Geocoder unavailable.');
        $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_USERAGENT => $userAgent];
        if (defined('CURLOPT_PROTOCOLS_STR')) $options[CURLOPT_PROTOCOLS_STR] = 'https'; else $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) $options[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
        curl_setopt_array($handle, $options);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        if ($body === false || $status !== 200) throw new RuntimeException('Geocoder request failed.');
        return (string) $body;
    }
}
