<?php
declare(strict_types=1);
namespace App\UI;

final class MessagingData
{
    public static function forRole(string $role): array
    {
        if (!in_array($role, ['customer', 'station-admin'], true)) {
            throw new \InvalidArgumentException('Messaging is available only to customer and station previews.');
        }
        $threads = [];
        foreach ([['guintas','maria','Guintas Water Station','Maria Santos','MS','HMT-104','delivery'], ['guintas','luis','Guintas Water Station','Luis Reyes','LR','HMT-105','pickup'], ['guintas','ana','Guintas Water Station','Ana Cruz','AC','HMT-106','delivery'], ['guintas','lito','Guintas Water Station','Lito Garcia','LG','HMT-107','delivery'], ['guintas','emilia','Guintas Water Station','Emilia Ramos','ER','HMT-109','pickup'], ['guintas','learning-center','Guintas Water Station','Guintas Learning Center','GL',null,null], ['poblacion','maria','Poblacion Water Station','Maria Santos','MS',null,null], ['riverside','maria','Riverside Water Station','Maria Santos','MS',null,null]] as [$station,$customer,$stationName,$customerName,$initials,$order,$method]) {
            if (($role === 'customer' && $customer !== 'maria') || ($role === 'station-admin' && $station !== 'guintas')) continue;
            $id = $station.'-'.$customer;
            $threads[] = ['id'=>$id,'station'=>$station,'customer'=>$customer,'name'=>$role === 'customer' ? $stationName : $customerName,'initials'=>$role === 'customer' ? strtoupper(substr($station,0,1)).'W' : $initials,'subtitle'=>$role === 'customer' ? 'Water station' : 'Customer','order'=>$order,'method'=>$method,'messages'=>[
                ['id'=>$id.'-1','sender'=>'customer:'.$customer,'body'=>$method === 'pickup' ? 'Hello! May I collect my water this afternoon?' : 'Hello! Can I ask about my water order?','at'=>'2026-11-02T09:30:00+08:00'],
                ['id'=>$id.'-2','sender'=>'station:'.$station,'body'=>$method === 'pickup' ? 'Yes, please bring your containers when you visit. We are open until 6 PM.' : 'Of course. Send your order number and we can help with the details.','at'=>'2026-11-02T09:32:00+08:00'],
            ]];
        }
        return ['version'=>1,'role'=>$role,'viewer'=>$role === 'customer' ? 'customer:maria' : 'station:guintas','threads'=>$threads];
    }
}
