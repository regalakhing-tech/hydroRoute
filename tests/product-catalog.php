<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use App\UI\Products;
use App\UI\UI;
$checks = 0;
function check(bool $condition, string $message): void { global $checks; if (!$condition) throw new RuntimeException($message); $checks++; }
$all = Products::all();
check(count($all) === 7, 'Seven products');
check(array_map(fn($p) => $p['price'], $all) === ['round'=>20,'small'=>10,'faucet'=>20,'jug'=>190,'bottle350'=>220,'bottle500'=>270,'bottle1l'=>260], 'Confirmed prices');
$mixed = Products::quote(['round'=>'2','faucet'=>'1','bottle350'=>'3']);
check($mixed['total'] === 720 && $mixed['quantity'] === 6, 'Mixed basket calculation');
check($mixed['returns'] === 3, 'Only refills need empties');
check(Products::quote(['small'=>'2'])['returns'] === 2 && Products::quote(['small'=>'2'])['total'] === 20, 'Small container refill');
check(Products::quote([])['total'] === 60, 'Default three round refills');
check(Products::quote(['round'=>'0','faucet'=>'0','bottle350'=>'0'])['quantity'] === 0, 'Explicit empty basket stays empty');
check(Products::quote(['bottle350'=>'4','jug'=>'1'])['returns'] === 0, 'Bottled water and new jugs require no returns');
check(Products::quote(['round'=>['5'],'faucet'=>'bad','bottle350'=>'1.5'])['quantity'] === 0, 'Array and noninteger quantities rejected');
check(Products::quote(['round'=>'-1','faucet'=>'999'])['quantity'] === 20, 'Bounds enforced');
check(Products::get(['round']) === null && Products::get('unknown') === null, 'Unknown references rejected');
check(Products::quote(['round'=>'1','unknown'=>'20'])['total'] === 20, 'Unknown basket keys ignored');
check([Products::deliveryFee(0.5), Products::deliveryFee(1), Products::deliveryFee(3), Products::deliveryFee(3.1), Products::deliveryFee(5)] === [5, 10, 10, 15, 15], 'Delivery fee distance tiers');
check(str_contains(UI::productImage(), 'water-jug.png'), 'Existing image helper defaults round');
check(str_contains(UI::productImage('h-20', 'bottle350'), 'water-bottle.png'), 'Bottle image identity');
check(str_contains(UI::productImage('h-20', 'faucet'), 'faucet-container.png'), 'Faucet image identity');
echo "$checks catalogue assertions passed.\n";
