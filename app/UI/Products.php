<?php
declare(strict_types=1);
namespace App\UI;

final class Products
{
    public static function all(): array
    {
        return [
            'round' => ['pack'=>'1 piece', 'name'=>'Water container refill (18.9 L)', 'price'=>20, 'image'=>'/assets/images/water-jug.webp', 'description'=>'Refill for your 18.9 L water container.', 'unit'=>'containers', 'per'=>'container', 'returnable'=>true],
            'small' => ['pack'=>'1 piece', 'name'=>'Small water container refill (9.5 L)', 'price'=>10, 'image'=>'/assets/images/water-jug.webp', 'description'=>'Refill for your 9.5 L small water container.', 'unit'=>'containers', 'per'=>'container', 'returnable'=>true],
            'faucet' => ['pack'=>'1 piece', 'name'=>'Water jug with faucet refill', 'price'=>20, 'image'=>'/assets/images/faucet-container.webp', 'description'=>'Refill for your water jug with faucet.', 'unit'=>'containers', 'per'=>'container', 'returnable'=>true],
            'jug' => ['pack'=>'1 piece', 'name'=>'New water jug with faucet', 'price'=>190, 'image'=>'/assets/images/faucet-container.webp', 'description'=>'New water jug with faucet, filled with water.', 'unit'=>'jugs', 'per'=>'jug', 'returnable'=>false],
            'bottle350' => ['pack'=>'1 box for 40 pcs', 'name'=>'Bottled water 350 ml', 'price'=>220, 'image'=>'/assets/images/water-bottle.webp', 'description'=>'1 box of 40 bottles.', 'unit'=>'boxes', 'per'=>'box', 'returnable'=>false],
            'bottle500' => ['pack'=>'1 box for 35 pcs', 'name'=>'Bottled water 500 ml', 'price'=>270, 'image'=>'/assets/images/water-bottle.webp', 'description'=>'1 box of 35 bottles.', 'unit'=>'boxes', 'per'=>'box', 'returnable'=>false],
            'bottle1l' => ['pack'=>'1 box for 20 pcs', 'name'=>'Bottled water 1 L', 'price'=>260, 'image'=>'/assets/images/water-bottle.webp', 'description'=>'1 box of 20 bottles.', 'unit'=>'boxes', 'per'=>'box', 'returnable'=>false],
        ];
    }

    /** Station price list: PHP 5 under 1 km, PHP 10 for 1-3 km, PHP 15 beyond 3 km. */
    public static function deliveryFee(float $km): int
    {
        return $km < 1 ? 5 : ($km <= 3 ? 10 : 15);
    }

    public static function get(mixed $id): ?array
    {
        return is_string($id) ? (self::all()[$id] ?? null) : null;
    }

    public static function quote(array $query): array
    {
        $explicit = false;
        foreach (array_keys(self::all()) as $id) $explicit = $explicit || array_key_exists($id, $query);
        $items = []; $total = 0; $quantity = 0; $returns = 0;
        foreach (self::all() as $id => $product) {
            $value = $query[$id] ?? (!$explicit && $id === 'round' ? 3 : 0);
            $count = (is_string($value) || is_int($value)) && preg_match('/^-?\d+$/D', (string)$value) ? max(0, min(20, (int)$value)) : 0;
            $items[$id] = $product + ['id'=>$id, 'quantity'=>$count, 'subtotal'=>$count * $product['price']];
            $total += $items[$id]['subtotal']; $quantity += $count;
            if ($product['returnable']) $returns += $count;
        }
        return ['items'=>$items, 'total'=>$total, 'quantity'=>$quantity, 'returns'=>$returns];
    }
}
