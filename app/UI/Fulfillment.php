<?php
declare(strict_types=1);
namespace App\UI;
final class Fulfillment
{
    public static function mode(mixed $value): string { return is_string($value) && $value === 'pickup' ? 'pickup' : 'delivery'; }
    public static function label(string $mode): string { return $mode === 'pickup' ? 'Pickup' : 'Delivery'; }
    public static function status(array $order): string { return ($order[7] ?? 'delivery') === 'pickup' && $order[5] === 'Ready' ? 'Ready for pickup' : $order[5]; }
    public static function pickupProgress(): array { return [['Confirmed','The station received your pickup order.','9:00 AM'],['Preparing','Your containers are being prepared.','9:10 AM'],['Ready for pickup','Collect your water at the station during opening hours.','9:25 AM'],['Picked up','You collected your order at the station.','10:15 AM']]; }
}
