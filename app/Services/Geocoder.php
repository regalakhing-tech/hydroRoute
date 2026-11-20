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

    /** @return array{street: string, place: string, areas: list<string>, landmark: string} */
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
        // Barangay candidates in ALAB's order: a value that says "Barangay", then the usual OpenStreetMap place keys, then the
        // parts of the full place name. The page matches these to the official Hamtic list.
        $explicit = '';
        foreach ($a as $key => $value) {
            if (is_string($value) && !in_array($key, ['road', 'amenity', 'shop', 'tourism', 'building', 'country', 'state', 'region', 'postcode'], true) && preg_match('/\bbarangay\b/i', $value)) { $explicit = trim($value); break; }
        }
        $parts = is_string($data['display_name'] ?? null) ? array_map('trim', explode(',', $data['display_name'])) : [];
        $areas = array_values(array_unique(array_filter(array_merge([$explicit],
            array_map(static fn (string $k): string => is_string($a[$k] ?? null) ? trim($a[$k]) : '', ['village', 'quarter', 'suburb', 'city_district', 'district', 'hamlet', 'neighbourhood', 'residential']),
            $parts), static fn (string $v): bool => $v !== '' && mb_strlen($v) <= 80)));
        $street = mb_substr($pick(['road', 'pedestrian', 'footway', 'residential']), 0, 120);
        // Nearest named place (a store, school, chapel...) helps the rider find the gate; generic tags and the road itself are skipped.
        $landmark = '';
        foreach ([$data['name'] ?? '', $a['amenity'] ?? '', $a['shop'] ?? '', $a['tourism'] ?? '', $a['historic'] ?? '', $a['leisure'] ?? '', $a['office'] ?? '', $a['building'] ?? ''] as $candidate) {
            $candidate = is_string($candidate) ? trim($candidate) : '';
            if ($candidate !== '' && !in_array(mb_strtolower($candidate), ['yes', 'no', 'building', 'house', 'residential'], true) && $candidate !== $street) { $landmark = $candidate; break; }
        }
        return ['street' => $street, 'place' => mb_substr($pick(['hamlet', 'neighbourhood']), 0, 120), 'areas' => array_slice($areas, 0, 12), 'landmark' => mb_substr($landmark, 0, 120)];
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
