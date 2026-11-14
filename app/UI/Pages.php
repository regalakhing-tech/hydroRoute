<?php
declare(strict_types=1);
namespace App\UI;
final class Pages
{
    public static function roles(): array
    {
        return [
            'customer'=>['label'=>'Customer','name'=>'Maria Santos','initials'=>'MS','caption'=>'Your daily water, delivered.','description'=>'Find a station, order refills and follow your delivery.','icon'=>'user','banner'=>'Your next refill, made simple.','nav'=>[
                ['', 'Overview','home'],['stations','Find stations','search','Stations'],['orders','My orders','orders','Orders'],['messages','Messages','message'],['addresses','Addresses','pin'],['notifications','Notifications','bell'],['account','Account','user']]],
            'rider'=>['label'=>'Rider','name'=>'Juan Reyes','initials'=>'JR','caption'=>'Every delivery, in one place.','description'=>'View your assigned trip and complete each delivery.','icon'=>'truck','banner'=>'A clear path to your next delivery.','nav'=>[['','My trip','truck'],['history','History','clock'],['account','Account','user']]],
            'station-admin'=>['label'=>'Station Admin','name'=>'Ana Dela Cruz','initials'=>'AC','caption'=>'Guintas Water Station','description'=>'Manage your station’s orders, riders and daily operations.','icon'=>'store','banner'=>'Your station. Ready for the day.','nav'=>[
                ['','Overview','home'],['orders','Order queue','orders','Orders'],['deliveries','Deliveries','truck'],['pickups','Pickup orders','store'],['drivers','Drivers','users'],['customers','Customers','user'],['messages','Messages','message'],['products','Products','bottle'],['schedules','Schedules','calendar'],['reports','Reports','chart'],['settings','Settings','settings']]],
            'super-admin'=>['label'=>'Super Admin','name'=>'Platform administrator','initials'=>'SA','caption'=>'Hamtic station registry','description'=>'Manage station registrations and directory information.','icon'=>'shield','banner'=>'Local stations. One clear view.','nav'=>[
                ['','Registry overview','home','Overview'],['applications','Station applications','file','Applications'],['stations','Registered stations','store','Stations'],['drivers','Driver registrations','users','Drivers'],['activity','Registration activity','activity'],['account','Account','settings']]],
        ];
    }
    public static function routes(): array
    {
        $definitions = [
            'customer'=>[''=>'overview','stations'=>'stations','orders'=>'orders','messages'=>'messages','addresses'=>'addresses','notifications'=>'notifications','account'=>'account','station'=>'station','checkout'=>'checkout','tracking'=>'tracking'],
            'rider'=>[''=>'trip','history'=>'history','account'=>'account','problem'=>'problem'],
            'station-admin'=>[''=>'overview','orders'=>'orders','deliveries'=>'deliveries','pickups'=>'pickups','drivers'=>'drivers','customers'=>'customers','messages'=>'messages','products'=>'products','schedules'=>'schedules','reports'=>'reports-sales','settings'=>'settings-profile','trip'=>'trip','drivers/add'=>'add-driver','settings/service-areas'=>'settings-service-areas','settings/order-rules'=>'settings-order-rules','reports/orders'=>'reports-orders','reports/deliveries'=>'reports-deliveries'],
            'super-admin'=>[''=>'overview','applications'=>'applications','stations'=>'stations','drivers'=>'drivers','activity'=>'activity','account'=>'account','application'=>'application'],
        ];
        $routes = [];
        foreach ($definitions as $role=>$pages) foreach ($pages as $path=>$screen) $routes['/'.$role.($path!==''?'/'.$path:'')] = ['role'=>$role,'screen'=>$screen];
        return $routes;
    }
}

