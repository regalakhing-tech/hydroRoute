<?php
declare(strict_types=1);
namespace App\UI;

/** Display names and badge tones for database order statuses (system plan section 9). */
final class OrderStatus
{
    public const ACTIVE = ['pending', 'on_hold', 'scheduled', 'confirmed', 'preparing', 'ready', 'out_for_delivery'];

    public static function label(string $status, string $fulfillment = 'delivery'): string
    {
        return match ($status) {
            'pending' => 'Processing', 'on_hold' => 'Waiting for your confirmation', 'scheduled' => 'Scheduled', 'confirmed' => 'Confirmed',
            'preparing' => 'Preparing', 'ready' => $fulfillment === 'pickup' ? 'Ready for pickup' : 'Ready for delivery',
            'out_for_delivery' => 'Out for delivery', 'delivered' => 'Delivered', 'collected' => 'Picked up',
            'completed' => 'Completed', 'cancelled' => 'Cancelled', default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    public static function tone(string $status): string
    {
        return match ($status) {
            'ready', 'delivered', 'collected', 'completed' => 'green', 'on_hold', 'scheduled' => 'amber',
            'cancelled' => 'red', 'pending' => 'gray', default => 'blue',
        };
    }

    public static function badge(string $status, string $fulfillment = 'delivery'): string
    {
        return UI::badge(self::label($status, $fulfillment), self::tone($status));
    }

    public static function pesos(int|string|null $centavos): string
    {
        return UI::money(((int) $centavos) / 100);
    }

    /** ISO instant to Manila display text. */
    public static function time(?string $iso, string $format = 'M j, Y · g:i A'): string
    {
        if ($iso === null || $iso === '') return '';
        try {
            return (new \DateTimeImmutable($iso))->setTimezone(new \DateTimeZone('Asia/Manila'))->format($format);
        } catch (\Exception) {
            return '';
        }
    }

    public static function image(?string $key): string
    {
        return '/assets/images/' . (in_array($key, ['water-jug', 'faucet-container', 'water-bottle'], true) ? $key : 'water-jug') . '.webp';
    }
}
