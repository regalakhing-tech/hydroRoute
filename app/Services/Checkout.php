<?php
declare(strict_types=1);
namespace App\Services;

/** Turns checkout form or query input into the quote/submit payload. Prices, fees and totals are never sent. */
final class Checkout
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di';

    /** Basket from qty[<product id>]=n, or bare <product id>=n keys from the station page. */
    public static function items(array $input): array
    {
        $source = is_array($input['qty'] ?? null) ? $input['qty'] : $input;
        $items = [];
        foreach ($source as $id => $quantity) {
            if (!is_string($id) || preg_match(self::UUID, $id) !== 1 || !is_scalar($quantity)) continue;
            $n = filter_var($quantity, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 9999]]);
            if ($n !== false && $n > 0) $items[strtolower($id)] = $n;
        }
        return $items;
    }

    public static function form(array $input): array
    {
        $text = static fn (string $key, int $max): string => is_string($input[$key] ?? null) ? trim(mb_substr($input[$key], 0, $max)) : '';
        $address = $text('address_id', 36);
        return [
            'fulfillment' => $text('fulfillment', 10) === 'pickup' ? 'pickup' : 'delivery',
            'address_id' => preg_match(self::UUID, $address) === 1 ? strtolower($address) : '',
            'schedule' => $text('schedule', 10) === 'scheduled' ? 'scheduled' : 'asap',
            'requested_at' => preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $text('requested_at', 16)) === 1 ? $text('requested_at', 16) : '',
            'note' => $text('note', 200),
        ];
    }

    public static function payload(array $items, array $form): array
    {
        $payload = ['fulfillment' => $form['fulfillment'], 'schedule' => $form['schedule'], 'note' => $form['note'],
            'items' => array_map(static fn (string $id, int $n): array => ['product_id' => $id, 'quantity' => $n], array_keys($items), array_values($items))];
        if ($form['fulfillment'] === 'delivery') $payload['address_id'] = $form['address_id'];
        if ($form['schedule'] === 'scheduled') $payload['requested_at'] = $form['requested_at'];
        return $payload;
    }

    /** Query string that reopens checkout with the same basket and choices. */
    public static function query(string $stationId, array $items, array $form): string
    {
        return http_build_query(['station' => $stationId, 'qty' => $items] + $form);
    }
}
