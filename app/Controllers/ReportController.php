<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Response;
use App\Core\Session;
use App\Services\Workspace;
use Throwable;

/** CSV export of a station report. The same scoped RPC and filters as the report page. */
final class ReportController extends LiveController
{
    public function export(): Response
    {
        $user = $this->member('station-admin');
        if ($user instanceof Response) return $user;
        [$kind, $start, $end] = self::range($_GET);
        try {
            $report = Workspace::get()->report($kind, $start, $end);
        } catch (Throwable $error) {
            error_log('Report export failed: ' . $error::class);
            return $this->back('/station-admin/reports', 'error', 'The report could not be exported. Please try again.');
        }
        $rows = self::rows($kind, $report);
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        foreach ($rows as $row) fputcsv($out, array_map(self::cell(...), $row), ',', '"', '');
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        return new Response($csv, 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="hydroroute-' . $kind . '-' . $start . '-to-' . $end . '.csv"', 'Cache-Control' => 'no-store']);
    }

    /** [kind, start, end] from the query; defaults to the last 7 days in Manila. */
    public static function range(array $query): array
    {
        $kind = in_array($query['kind'] ?? '', ['sales', 'orders', 'deliveries'], true) ? $query['kind'] : 'sales';
        $today = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Manila'));
        $valid = static fn (mixed $d): bool => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $d) === 1 && \DateTimeImmutable::createFromFormat('!Y-m-d', $d)?->format('Y-m-d') === $d;
        $end = $valid($query['end'] ?? null) ? $query['end'] : $today->format('Y-m-d');
        $start = $valid($query['start'] ?? null) ? $query['start'] : $today->modify('-6 days')->format('Y-m-d');
        return [$kind, $start, $end];
    }

    /** Spreadsheet formula injection: text starting with = + - @ is prefixed with an apostrophe. */
    private static function cell(mixed $value): string
    {
        $text = (string) $value;
        return preg_match('/^[=+\-@\t\r]/', $text) === 1 ? "'" . $text : $text;
    }

    private static function pesos(mixed $centavos): string
    {
        return number_format(((int) $centavos) / 100, 2, '.', '');
    }

    private static function rows(string $kind, array $r): array
    {
        return match ($kind) {
            'sales' => array_merge([['Date', 'Completed orders', 'Completed order totals (PHP)', 'Cash collected (PHP)', 'Cash verified (PHP)']],
                array_map(static fn (array $d): array => [$d['date'], $d['completed_orders'], self::pesos($d['completed_total_centavos']), self::pesos($d['collected_centavos']), self::pesos($d['verified_centavos'])], $r['daily']),
                [['Total', $r['completed_orders'], self::pesos($r['completed_total_centavos']), self::pesos($r['cash_collected_centavos']), self::pesos($r['cash_verified_centavos'])],
                    ['Delivery fees in completed orders (PHP)', self::pesos($r['delivery_fee_centavos'])], ['Unverified cash now (PHP)', self::pesos($r['unverified_now_centavos'])], ['Date basis', $r['date_basis']]]),
            'orders' => array_merge([['Date', 'Placed', 'Completed', 'Cancelled', 'Items (not cancelled)']],
                array_map(static fn (array $d): array => [$d['date'], $d['placed'], $d['completed'], $d['cancelled'], $d['units']], $r['daily']),
                [[], ['Product', 'Quantity', 'Amount (PHP)']], array_map(static fn (array $p): array => [$p['name'], $p['quantity'], self::pesos($p['amount_centavos'])], $r['by_product']),
                [[], ['Cancellation reason', 'Orders']], array_map(static fn (array $c): array => [$c['reason'], $c['orders']], $r['cancel_reasons']), [['Date basis', $r['date_basis']]]),
            default => array_merge([['Date', 'Trips', 'Delivered stops', 'Failed stops']],
                array_map(static fn (array $d): array => [$d['date'], $d['trips'], $d['delivered'], $d['failed']], $r['daily']),
                [[], ['Rider', 'Trips', 'Delivered', 'Failed', 'Items delivered', 'Cash collected (PHP)']],
                array_map(static fn (array $x): array => [$x['rider'], $x['trips'], $x['delivered'], $x['failed'], $x['units'], self::pesos($x['cash_centavos'])], $r['by_rider']),
                [['Date basis', $r['date_basis']]]),
        };
    }
}
