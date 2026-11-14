<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Response;
use App\Core\Session;
use App\Services\CurrentUser;
use App\Services\Registry;
use App\Services\StationDirectory;
use App\UI\Pages;
use App\UI\CustomerData;
use App\UI\StationData;
use App\UI\RiderData;
use App\UI\RegistryData;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
final class UIController
{
    /**
     * Preview mode (APP_PREVIEW=true) shows sample data without logins, for demos and automated page tests.
     * Otherwise every role page needs that role's login, and stations, registrations and drivers come from Supabase.
     */
    public function __construct(
        private readonly bool $preview = false,
        private readonly ?StationDirectory $directory = null,
        private readonly ?Registry $registry = null,
        private readonly ?CurrentUser $current = null,
    ) {}
    public function home(): Response
    {
        return Response::view('layouts/public',['view'=>'shared/home','title'=>'Water refills from your neighborhood','stations'=>$this->stations(),'preview'=>$this->preview]);
    }
    public function login(string $role = ''): Response
    {
        if ($role !== '' && (Session::user()['role'] ?? null) === $role) return Response::redirect('/'.$role);
        return Response::view('layouts/auth',['authView'=>$role===''?'auth/choose':'auth/login','title'=>$role===''?'Choose your login':'Log in','role'=>$role,'roles'=>Pages::roles(),'roleInfo'=>Pages::roles()[$role]??null]);
    }
    public function signup(): Response
    {
        if ((Session::user()['role'] ?? null) === 'customer') return Response::redirect('/customer');
        return Response::view('layouts/auth',['authView'=>'auth/register','title'=>'Create account','role'=>'customer','roles'=>Pages::roles(),'roleInfo'=>Pages::roles()['customer']]);
    }
    public function recovery(): Response { return Response::view('layouts/auth',['authView'=>'auth/recovery','title'=>'Reset password','role'=>'customer','roles'=>Pages::roles(),'roleInfo'=>Pages::roles()['customer']]); }
    public function registration(): Response
    {
        $submitted = $_SESSION['registration_submitted'] ?? null;
        unset($_SESSION['registration_submitted']);
        return Response::view('layouts/public',['view'=>'shared/register-station','title'=>'Register your station','submitted'=>is_array($submitted)?$submitted:null]);
    }
    public function page(string $role, string $screen): Response
    {
        $user = Session::user();
        $user = ($user['role'] ?? null) === $role ? $user : null;
        if (!$this->preview && $user === null) {
            Session::flash('info', 'Log in with your '.Pages::roles()[$role]['label'].' account to continue.');
            return Response::redirect('/login/'.$role);
        }
        $info = Pages::roles()[$role];
        if ($user !== null) {
            $info['name'] = $user['name'];
            $info['initials'] = self::initials($user['name']);
            if (!empty($user['station']['name'])) $info['caption'] = $user['station']['name'];
        }
        $sample = match($role) {
            'customer'=>CustomerData::all(), 'rider'=>RiderData::all(),
            'station-admin'=>StationData::all(), 'super-admin'=>RegistryData::all(),
        };
        if (!$this->preview) {
            $token = $role === 'customer' ? '' : $this->current?->token();
            if ($token === null) {
                Session::flash('info', 'Your session ended. Please log in again.');
                return Response::redirect('/login/'.$role);
            }
            $sample = match($role) {
                'customer' => ['stations'=>$this->stations()] + $sample,
                'super-admin' => $this->registrySample($token, $user) + $sample,
                'station-admin' => ['drivers'=>$this->stationDrivers($token, $user['station']['id'])] + $sample,
                'rider' => $sample,
            };
        }
        return Response::view('layouts/app',['role'=>$role,'screen'=>$screen,'roleInfo'=>$info,'roles'=>Pages::roles(),'sample'=>$sample,'user'=>$user,'preview'=>$this->preview,'path'=>parse_url($_SERVER['REQUEST_URI']??'/'.$role,PHP_URL_PATH)]);
    }
    private function stations(): array
    {
        return $this->preview || $this->directory === null ? CustomerData::all()['stations'] : $this->directory->approved();
    }
    /** Super Admin pages read only registry tables: no orders, payments or revenue. */
    private function registrySample(string $token, array $user): array
    {
        try {
            $stations = $this->registry->stations($token);
            $registrations = $this->registry->registrations($token);
            $drivers = $this->registry->drivers($token);
        } catch (RuntimeException $exception) {
            error_log('Registry pages unavailable: '.$exception->getMessage());
            Session::flash('error', 'The registry could not be loaded. Please refresh the page.');
            [$stations, $registrations, $drivers] = [[], [], []];
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
        $names = array_column($stations, 'name', 'id');
        $perStation = array_count_values(array_column($drivers, 'station_id'));
        $applications = array_map(static fn (array $r): array => [
            'id'=>$r['id'], 'ref'=>'REG-'.strtoupper(substr($r['id'], 0, 6)), 'name'=>$r['station_name'], 'area'=>$r['barangay'],
            'applicant'=>$r['owner_name'], 'date'=>self::date($r['created_at']), 'status'=>ucfirst($r['status']),
        ], $registrations);
        $selected = current(array_filter($registrations, static fn (array $r): bool => $r['id'] === ($_GET['id'] ?? null)));
        $events = [];
        foreach ($registrations as $r) {
            $events[] = [$r['created_at'], 'Application submitted', $r['station_name'], $r['owner_name'], 'Application'];
            if ($r['reviewed_at']) $events[] = [$r['reviewed_at'], 'Application '.$r['status'], $r['station_name'], 'Registry administrator', 'Application'];
        }
        foreach ($drivers as $d) $events[] = [$d['created_at'], 'Driver registration added', $names[$d['station_id']] ?? 'Station', 'Station administrator', 'Driver'];
        usort($events, static fn (array $a, array $b): int => strcmp($b[0], $a[0]));
        return [
            'counts'=>['Registered stations'=>count($stations), 'Pending applications'=>count(array_filter($registrations, static fn (array $r): bool => $r['status'] === 'pending')), 'Registered drivers'=>count($drivers)],
            'stations'=>array_map(static function (array $s) use ($now, $perStation): array {
                $presented = StationDirectory::present($s, $now);
                return ['id'=>$s['id'], 'name'=>$s['name'], 'area'=>$s['barangay'] ?: 'Hamtic', 'registration'=>ucfirst($s['registration_status']),
                    'availability'=>$presented['available'] ? 'Open' : ($s['closed_notice'] ? 'Maintenance' : 'Closed now'),
                    'drivers'=>$perStation[$s['id']] ?? 0, 'date'=>self::date($s['created_at']), 'contact'=>self::phone($s['phone'])];
            }, $stations),
            'applications'=>$applications,
            'application'=>$selected ? [
                'id'=>$selected['id'], 'ref'=>'REG-'.strtoupper(substr($selected['id'], 0, 6)), 'name'=>$selected['station_name'], 'area'=>$selected['barangay'],
                'municipality'=>'Hamtic, Antique', 'address'=>$selected['address'], 'phone'=>self::phone($selected['station_phone']), 'email'=>$selected['station_email'] ?: 'Not provided',
                'applicant'=>$selected['owner_name'], 'applicant_role'=>$selected['owner_role'], 'applicant_phone'=>self::phone($selected['owner_phone']), 'applicant_email'=>$selected['owner_email'],
                'date'=>self::date($selected['created_at']), 'status'=>ucfirst($selected['status']), 'reason'=>$selected['decision_reason'], 'station_id'=>$selected['station_id'],
            ] : null,
            'drivers'=>array_map(static fn (array $d): array => ['name'=>$d['full_name'], 'station'=>$names[$d['station_id']] ?? 'Station', 'phone'=>self::phone($d['phone']), 'vehicle'=>$d['vehicle'], 'status'=>$d['active'] ? 'Active' : 'Inactive', 'date'=>self::date($d['created_at'])], $drivers),
            'activity'=>array_map(static fn (array $e): array => ['time'=>self::date($e[0], true), 'event'=>$e[1], 'station'=>$e[2], 'actor'=>$e[3], 'type'=>$e[4]], array_slice($events, 0, 50)),
            'account'=>['name'=>$user['name'], 'email'=>$user['email'], 'phone'=>''],
        ];
    }
    private function stationDrivers(string $token, string $stationId): array
    {
        try {
            $drivers = $this->registry->drivers($token, $stationId);
        } catch (RuntimeException $exception) {
            error_log('Station drivers unavailable: '.$exception->getMessage());
            Session::flash('error', 'The driver list could not be loaded. Please refresh the page.');
            return [];
        }
        // Same positional shape as the sample: name, initials, phone, status, trip, capacity, account id, email.
        return array_map(static fn (array $d): array => [$d['full_name'], self::initials($d['full_name']), self::phone($d['phone']), $d['active'] ? 'Available' : 'Inactive', 'No active trip', (string) $d['capacity'], $d['user_id'], $d['email']], $drivers);
    }
    private static function initials(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: ['?'];
        return mb_strtoupper(mb_substr($words[0], 0, 1).(isset($words[1]) ? mb_substr($words[1], 0, 1) : ''));
    }
    private static function date(?string $iso, bool $withTime = false): string
    {
        if (!$iso) return '';
        $date = (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('Asia/Manila'));
        return $date->format($withTime ? 'M j, Y · g:i A' : 'M j, Y');
    }
    /** +639171234567 → 0917 123 4567 for display. */
    private static function phone(?string $phone): string
    {
        return $phone && preg_match('/^\+63(9\d{2})(\d{3})(\d{4})$/', $phone, $m) ? '0'.$m[1].' '.$m[2].' '.$m[3] : (string) $phone;
    }
}
