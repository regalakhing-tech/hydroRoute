<?php
declare(strict_types=1);
namespace App\UI;

final class StationData
{
    public static function all(): array
    {
        return [
            'station' => 'Guintas Water Station',
            'trips' => [
                '08' => ['driver'=>'Juan Reyes','initials'=>'JR','area'=>'Guintas','status'=>'In progress','capacity'=>15,'stops'=>[
                    ['HMT-098','Nora Flores',4,'Delivered'],['HMT-099','Jose Ramos',4,'Next stop'],['HMT-100','Rosa Perez',4,'Pending'],
                ]],
                '07' => ['driver'=>'Carlo Diaz','initials'=>'CD','area'=>'Poblacion','status'=>'Returning','capacity'=>15,'stops'=>[
                    ['HMT-096','Leo Garcia',4,'Delivered'],['HMT-097','Mila Perez',4,'Delivered'],
                ]],
                '06' => ['driver'=>'Ben Cruz','initials'=>'BC','area'=>'Guintas','status'=>'Completed','capacity'=>15,'stops'=>[
                    ['HMT-093','Linda Ramos',4,'Delivered'],['HMT-094','Rene Cruz',3,'Delivered'],['HMT-095','Rita Santos',3,'Delivered'],
                ]],
            ],
            'orders' => [
                ['HMT-104', 'Maria Santos', 'Guintas', 3, 60, 'Preparing', '10:20 AM', 'delivery'],
                ['HMT-105', 'Luis Reyes', 'Guintas', 2, 55, 'Confirmed', '10:35 AM', 'pickup'],
                ['HMT-106', 'Ana Cruz', 'Poblacion', 4, 45, 'Ready', '10:12 AM', 'delivery'],
                ['HMT-107', 'Lito Garcia', 'Guintas', 3, 40, 'Ready', '10:40 AM', 'delivery'],
                ['HMT-109', 'Emilia Ramos', 'Guintas', 2, 35, 'Ready', '11:05 AM', 'pickup'],
            ],
            'drivers' => [
                ['Juan Reyes', 'JR', '0917 555 0101', 'In progress', 'Trip 08', '15'],
                ['Carlo Diaz', 'CD', '0917 555 0102', 'Returning', 'Trip 07', '15'],
                ['Ben Cruz', 'BC', '0917 555 0103', 'Available', 'No active trip', '15'],
                ['Mark Flores', 'MF', '0917 555 0104', 'Available', 'No active trip', '12'],
                ['Rico Perez', 'RP', '0917 555 0105', 'Available', 'No active trip', '12'],
            ],
            'customers' => [
                ['Maria Santos', 'Guintas', 'Household', '0917 555 0201', 12],
                ['Luis Reyes', 'Guintas', 'Household', '0917 555 0202', 8],
                ['Ana Cruz', 'Poblacion', 'Household', '0917 555 0203', 6],
                ['Guintas Learning Center', 'Guintas', 'Institution', '0917 555 0204', 18],
            ],
            'areas' => ['Guintas', 'Poblacion', 'Bia-an', 'Buhang'],
            'days' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
            'sales' => [['Nov 1, 2026', 3450], ['Nov 2, 2026', 3450]],
        ];
    }
}
