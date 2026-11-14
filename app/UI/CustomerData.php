<?php
declare(strict_types=1);
namespace App\UI;

final class CustomerData
{
    public static function all(): array
    {
        return [
            'stations' => [
                ['id' => 'guintas', 'name' => 'Guintas Water Station', 'area' => 'Guintas', 'status' => 'Open until 6:00 PM', 'tone' => 'green', 'available' => true, 'hours' => '7:00 AM – 6:00 PM', 'sunday' => '8:00 AM – 4:00 PM', 'next' => 'Accepting orders today until 6:00 PM.'],
                ['id' => 'poblacion', 'name' => 'Poblacion Water Station', 'area' => 'Poblacion', 'status' => 'Opens tomorrow at 7:00 AM', 'tone' => 'gray', 'available' => false, 'hours' => '7:00 AM – 5:00 PM', 'sunday' => 'Closed', 'next' => 'Next available: November 3, 2026 at 7:00 AM.'],
                ['id' => 'riverside', 'name' => 'Riverside Water Station', 'area' => 'Riverside', 'status' => 'Under maintenance', 'tone' => 'amber', 'available' => false, 'hours' => '8:00 AM – 5:00 PM', 'sunday' => 'Closed', 'next' => 'Reopening time is not yet confirmed. Please choose another station.'],
            ],
            'orders' => [
                ['fulfillment' => 'delivery', 'station_id' => 'guintas', 'stage' => 3, 'id' => 'HMT-104', 'date' => 'Nov 2, 2026', 'station' => 'Guintas Water Station', 'quantity' => 3, 'total' => 70, 'status' => 'Out for delivery', 'tone' => 'blue'],
                ['fulfillment' => 'pickup', 'station_id' => 'poblacion', 'stage' => 3, 'id' => 'HMT-098', 'date' => 'Nov 1, 2026', 'station' => 'Poblacion Water Station', 'quantity' => 2, 'total' => 40, 'status' => 'Picked up', 'tone' => 'green'],
                ['fulfillment' => 'delivery', 'station_id' => 'guintas', 'stage' => 4, 'id' => 'HMT-091', 'date' => 'Nov 1, 2026', 'station' => 'Guintas Water Station', 'quantity' => 3, 'total' => 70, 'status' => 'Delivered', 'tone' => 'green'],
            ],
            'progress' => [
                ['Confirmed', 'Your order has been received.', '9:00 AM'],
                ['Preparing', 'We’re getting your order ready.', '9:10 AM'],
                ['Ready', 'Your order is ready for delivery.', '9:25 AM'],
                ['Out for delivery', 'Your order is on the way to your address.', '9:40 AM'],
                ['Delivered', 'Waiting for confirmation.', '—'],
                ['Completed', 'Order fully completed.', '—'],
            ],
            'addresses' => [
                ['label' => 'Home', 'address' => 'Purok 2, Guintas, Hamtic, Antique', 'note' => 'Near barangay hall', 'default' => true, 'km' => 1.5],
                ['label' => 'Work', 'address' => 'National Road, Poblacion, Hamtic, Antique', 'note' => 'Reception, ground floor', 'default' => false, 'km' => 4.2],
            ],
            'notifications' => [
                ['title' => 'Your water is on the way', 'body' => 'Rider Juan Reyes is delivering order HMT-104 to your Home address.', 'time' => 'Today, 9:40 AM', 'icon' => 'truck', 'order_id' => 'HMT-104'],
                ['title' => 'Your order is confirmed', 'body' => 'Guintas Water Station has received your order for 3 refill containers.', 'time' => 'Today, 9:00 AM', 'icon' => 'check', 'order_id' => 'HMT-104'],
                ['title' => 'Pickup completed', 'body' => 'Order HMT-098 was picked up. Thank you for choosing local water stations.', 'time' => 'Nov 1, 2026 · 10:15 AM', 'icon' => 'bottle', 'order_id' => 'HMT-098'],
            ],
        ];
    }
}
