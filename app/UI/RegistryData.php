<?php
namespace App\UI;
final class RegistryData {
 public static function all(): array { return [
 'counts'=>['Registered stations'=>12,'Pending applications'=>3,'Registered drivers'=>28],
 'stations'=>[
 ['name'=>'Guintas Water Station','area'=>'Guintas','registration'=>'Approved','availability'=>'Open','drivers'=>4,'date'=>'Oct 26, 2026','contact'=>'0917 000 0110'],
 ['name'=>'Poblacion Water Station','area'=>'Poblacion','registration'=>'Approved','availability'=>'Maintenance','drivers'=>3,'date'=>'Oct 28, 2026','contact'=>'0917 000 0111'],
 ['name'=>'Riverside Water Station','area'=>'Guintas','registration'=>'Pending','availability'=>'Not yet active','drivers'=>0,'date'=>'Nov 2, 2026','contact'=>'0912 345 6789']
 ],
 'applications'=>[
 ['id'=>'REG-026','name'=>'Riverside Water Station','area'=>'Guintas','applicant'=>'Maria Villanueva','date'=>'Nov 2, 2026','status'=>'Pending'],
 ['id'=>'REG-025','name'=>'Bongbongan Fresh Water','area'=>'Bongbongan','applicant'=>'Elena Ramos','date'=>'Nov 1, 2026','status'=>'Pending'],
 ['id'=>'REG-024','name'=>'Malandog Clear Water','area'=>'Malandog','applicant'=>'Roberto Perez','date'=>'Nov 1, 2026','status'=>'Changes requested']
 ],
 'drivers'=>[
 ['name'=>'Juan Reyes','station'=>'Guintas Water Station','phone'=>'0917 000 0108','vehicle'=>'Motorcycle with sidecar','status'=>'Active','date'=>'Oct 27, 2026'],
 ['name'=>'Marco Flores','station'=>'Poblacion Water Station','phone'=>'0917 000 0109','vehicle'=>'Motorcycle with sidecar','status'=>'Active','date'=>'Oct 29, 2026'],
 ['name'=>'Lina Garcia','station'=>'Guintas Water Station','phone'=>'0917 000 0112','vehicle'=>'Multicab','status'=>'Inactive','date'=>'Oct 30, 2026']
 ],
 'activity'=>[
 ['time'=>'Nov 2, 2026 · 10:15 AM','event'=>'Application submitted','station'=>'Riverside Water Station','actor'=>'Maria Villanueva','type'=>'Application'],
 ['time'=>'Nov 2, 2026 · 9:30 AM','event'=>'Business contact updated','station'=>'Guintas Water Station','actor'=>'Station administrator','type'=>'Profile'],
 ['time'=>'Nov 1, 2026 · 3:20 PM','event'=>'Application changes requested','station'=>'Malandog Clear Water','actor'=>'Registry administrator','type'=>'Application'],
 ['time'=>'Oct 30, 2026 · 11:40 AM','event'=>'Driver registration added','station'=>'Guintas Water Station','actor'=>'Station administrator','type'=>'Driver']
 ],
 'application'=>['id'=>'REG-026','name'=>'Riverside Water Station','area'=>'Guintas','municipality'=>'Hamtic, Antique','address'=>'Purok 2','phone'=>'0912 345 6789','email'=>'riversidewater@example.com','applicant'=>'Maria Villanueva','applicant_phone'=>'0917 000 0126','applicant_email'=>'maria.villanueva@example.com','date'=>'Nov 2, 2026'],
 'account'=>['name'=>'Andrea Torres','email'=>'registry@example.com','phone'=>'0917 000 0120']
 ]; }
}
