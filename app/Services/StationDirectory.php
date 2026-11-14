<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/** Public approved station metadata paired with database-derived availability. */
final class StationDirectory
{
    private ?array $cache = null;

    public function __construct(private readonly Gateway $db)
    {
    }

    public function approved(?DateTimeImmutable $now = null): array
    {
        if ($this->cache !== null) return $this->cache;
        try {
            $rows = $this->db->select('stations', ['select' => Registry::STATION_COLUMNS, 'order' => 'name.asc']);
            if ($rows === []) return $this->cache = [];
            $result = $this->db->rpc('list_station_availability', [
                'p_station_ids' => array_column($rows, 'id'),
                'p_requested_at' => $now?->format(DATE_ATOM),
            ], null);
            if (($result['ok'] ?? false) !== true || !is_array($result['data']['stations'] ?? null)) {
                throw new RuntimeException('Availability projection unavailable.');
            }
            $availability = array_column($result['data']['stations'], null, 'station_id');
            $stations = [];
            foreach ($rows as $row) {
                if (!isset($availability[$row['id']])) continue;
                $state = $availability[$row['id']];
                $accepting = ($state['accepting_asap'] ?? false) === true;
                $next = empty($state['next_open_at']) ? 'No opening time is currently available.'
                    : 'Next opening '.(new DateTimeImmutable($state['next_open_at']))->setTimezone(new DateTimeZone('Asia/Manila'))->format('M j, Y \a\t g:i A').'.';
                $hours = [];
                foreach ($state['preview'] ?? [] as $day) {
                    $windows = [];
                    foreach ($day['windows'] ?? [] as $window) {
                        $windows[] = substr($window['opens_at'], 0, 5).'–'.substr($window['closes_at'], 0, 5);
                    }
                    $hours[] = $windows === [] ? 'Closed' : implode(', ', $windows);
                }
                $stations[] = [
                    'id'=>(string)($row['slug'] ?? $row['id']), 'uuid'=>(string)$row['id'],
                    'name'=>(string)$row['name'], 'area'=>(string)($row['barangay'] ?: 'Hamtic'),
                    'address'=>(string)($row['address'] ?? ''), 'phone'=>(string)($row['phone'] ?? ''),
                    'status'=>(string)($state['label'] ?? 'Availability unavailable'),
                    'tone'=>$accepting ? 'green' : (($state['status_key'] ?? '') === 'closed' ? 'gray' : 'amber'),
                    'available'=>$accepting, 'can_book'=>($state['can_book'] ?? false) === true,
                    'hours'=>$hours[0] ?? 'Hours unavailable', 'sunday'=>'See station schedule',
                    'next'=>$accepting ? 'Accepting orders now.' : $next,
                ];
            }
            return $this->cache = $stations;
        } catch (RuntimeException $exception) {
            error_log('Station directory unavailable: '.$exception::class);
            return [];
        }
    }

    /** Shapes one stations row like the page templates expect; $now must be in Asia/Manila. */
    public static function present(array $row, DateTimeImmutable $now): array
    {
        $time = static fn (string $value): string => DateTimeImmutable::createFromFormat('H:i:s', substr($value . ':00', 0, 8))->format('g:i A');
        $range = static fn (?string $open, ?string $close): string => $open === null || $close === null ? 'Closed' : $time($open) . ' – ' . $time($close);
        $window = static function (DateTimeImmutable $day) use ($row): ?array {
            $sunday = $day->format('w') === '0';
            $open = $sunday ? $row['sunday_open'] : $row['weekday_open'];
            $close = $sunday ? $row['sunday_close'] : $row['weekday_close'];
            if ($open === null || $close === null) {
                return null;
            }
            $at = static fn (string $value): DateTimeImmutable => new DateTimeImmutable($day->format('Y-m-d') . ' ' . $value, $day->getTimezone());

            return [$at($open), $at($close)];
        };

        [$status, $tone, $available, $next] = (static function () use ($row, $now, $window): array {
            if (($row['closed_notice'] ?? null) !== null && $row['closed_notice'] !== '') {
                return [$row['closed_notice'], 'amber', false, 'Reopening time is not yet confirmed. Please choose another station.'];
            }
            $today = $window($now);
            if ($today !== null && $now >= $today[0] && $now < $today[1]) {
                $until = $today[1]->format('g:i A');
                return ['Open until ' . $until, 'green', true, 'Accepting orders today until ' . $until . '.'];
            }
            if ($today !== null && $now < $today[0]) {
                $opens = $today[0]->format('g:i A');
                return ['Opens today at ' . $opens, 'gray', false, 'Next available: today at ' . $opens . '.'];
            }
            for ($days = 1; $days <= 7; $days++) {
                $day = $now->modify('+' . $days . ' day');
                $next = $window($day);
                if ($next !== null) {
                    $label = $days === 1 ? 'tomorrow' : 'on ' . $day->format('l');
                    return ['Opens ' . $label . ' at ' . $next[0]->format('g:i A'), 'gray', false, 'Next available: ' . $next[0]->format('F j, Y \a\t g:i A') . '.'];
                }
            }

            return ['Closed', 'gray', false, 'No opening hours are set yet.'];
        })();

        return [
            'id' => (string) ($row['slug'] ?? $row['id']),
            'uuid' => (string) $row['id'],
            'name' => (string) $row['name'],
            'area' => (string) ($row['barangay'] ?: 'Hamtic'),
            'address' => (string) ($row['address'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'status' => $status,
            'tone' => $tone,
            'available' => $available,
            'hours' => $range($row['weekday_open'], $row['weekday_close']),
            'sunday' => $range($row['sunday_open'], $row['sunday_close']),
            'next' => $next,
        ];
    }
}
