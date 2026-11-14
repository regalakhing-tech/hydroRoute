<?php
namespace App\UI;
final class RiderData {
 public static function all(): array { return [
 'name'=>'Juan Reyes','station'=>'Guintas Water Station','phone'=>'0917 000 0108','email'=>'juan.reyes@example.com','vehicle'=>'Motorcycle with sidecar','plate'=>'ANT 108',
 'trip'=>'Trip 08','area'=>'Guintas','completed'=>1,'total'=>3,
 'stop'=>['name'=>'Maria Santos','address'=>'Purok 2, Guintas, Hamtic','landmark'=>'Near the barangay hall','phone'=>'09170000101','quantity'=>3,'amount'=>70,'empties'=>3],
 'next'=>['name'=>'Juan Dela Cruz','address'=>'Purok 3, Guintas, Hamtic','landmark'=>'Beside the covered court'],
 'history'=>[
 ['id'=>'HMT-101','name'=>'Maria Santos','area'=>'Guintas','status'=>'Delivered','time'=>'9:40 AM','quantity'=>3,'empties'=>3],
 ['id'=>'HMT-099','name'=>'Luis Reyes','area'=>'Guintas','status'=>'Delivered','time'=>'8:50 AM','quantity'=>2,'empties'=>2],
 ['id'=>'HMT-097','name'=>'Ana Cruz','area'=>'Poblacion','status'=>'Customer unavailable','time'=>'8:10 AM','quantity'=>2,'empties'=>0],
 ['id'=>'HMT-095','name'=>'Pedro Garcia','area'=>'Guintas','status'=>'Delivered','time'=>'7:45 AM','quantity'=>1,'empties'=>1]
 ]]; }
}
