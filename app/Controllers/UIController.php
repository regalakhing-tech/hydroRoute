<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Response;
use App\Core\Session;
use App\Services\CurrentUser;
use App\Services\Registry;
use App\Services\StationDirectory;
use App\Services\StationSetup;
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
        private readonly ?StationSetup $setup = null,
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
        if (!$this->preview && $role === 'station-admin') $info['caption'] = 'Station workspace';
        if ($user !== null) {
            $info['name'] = $user['name'];
            $info['initials'] = self::initials($user['name']);
            if (!empty($user['station']['name'])) $info['caption'] = $user['station']['name'];
        }
        $sample = match($role) {
            'customer'=>CustomerData::all(), 'rider'=>RiderData::all(),
            'station-admin'=>StationData::all(), 'super-admin'=>RegistryData::all(),
        };
        $setupData = null;
        $setupError = null;
        $setupOld = [];
        $setupErrors = [];
        $stationChoices = [];
        $needsStationSelection = false;
        if (!$this->preview) {
            $token = $role === 'customer' ? '' : $this->current?->token();
            if ($token === null && $this->current?->unavailable) {
                // Still logged in, the sign-in service is just unreachable: a login redirect would bounce straight back here.
                return new Response('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta http-equiv="refresh" content="15"><title>Reconnecting | HydroRoute</title>'
                    .'<body style="font-family:system-ui,sans-serif;max-width:32rem;margin:15vh auto;padding:0 1rem;color:#083c53"><h1>Reconnecting…</h1><p>The sign-in service did not answer. You are still signed in; this page tries again by itself in 15 seconds.</p><p><a href="">Try again now</a></p></body>', 503, ['Retry-After' => '15']);
            }
            if ($token === null) {
                Session::flash('info', 'Your session ended. Please log in again.');
                return Response::redirect('/login/'.$role);
            }
            if ($role === 'station-admin') {
                try {
                    if ($this->setup === null) {
                        throw new RuntimeException('Station context unavailable.');
                    }
                    if (empty($user['station']['id'])) {
                        $stationChoices = $this->setup->choices($token);
                        if (count($stationChoices) === 1) {
                            $choice = $stationChoices[0];
                            $user['station'] = ['id'=>$choice['station_id'], 'name'=>$choice['station_name'], 'slug'=>$choice['slug']];
                            $_SESSION['user'] = $user;
                        } elseif ($stationChoices === []) {
                            throw new RuntimeException('Station membership unavailable.');
                        } else {
                            $needsStationSelection = true;
                            $info['caption'] = 'Choose your station';
                        }
                    }
                    if (!$needsStationSelection) {
                        $setupData = $this->setup->load($token, $user['station']['id']);
                        $info['caption'] = $setupData['station']['name'];
                    }
                } catch (RuntimeException $exception) {
                    error_log('Station context unavailable: '.$exception::class);
                    $setupError = 'Your station workspace could not be loaded. Refresh the page or log in again.';
                }
                if (in_array($screen, ['settings-profile', 'settings-service-areas', 'settings-order-rules', 'products', 'schedules'], true)) {
                    $setupOld = Session::takeInput();
                    $setupErrors = is_array($_SESSION['setup_errors'] ?? null) ? $_SESSION['setup_errors'] : [];
                    unset($_SESSION['setup_errors']);
                }
            }
            $sample = match($role) {
                'customer' => ['stations'=>$this->stations()] + $sample,
                'super-admin' => $this->registrySample($token, $user) + $sample,
                'station-admin' => ['drivers'=>$setupData !== null && $screen === 'drivers' ? $this->stationDrivers($token, $user['station']['id']) : []] + $sample,
                'rider' => $sample,
            };
        }
        return Response::view('layouts/app',['role'=>$role,'screen'=>$screen,'roleInfo'=>$info,'roles'=>Pages::roles(),'sample'=>$sample,'user'=>$user,'preview'=>$this->preview,'path'=>parse_url($_SERVER['REQUEST_URI']??'/'.$role,PHP_URL_PATH), 'setup'=>$setupData, 'setup_error'=>$setupError, 'setup_old'=>$setupOld, 'setup_errors'=>$setupErrors, 'station_choices'=>$stationChoices, 'needs_station_selection'=>$needsStationSelection]);
    }
    private function stations(): array
    {
        return $this->preview || $this->directory === null ? CustomerData::all()['stations'] : $this->directory->approved();
    }
    /** Super Admin pages read only registry tables: no orders, payments or revenue. */
    private function registrySample(string $token, array $user): array
    {
        try {
            $projection = $this->registry->platformRegistry($token);
            $stations = $projection['rows'];
            $registrations = $this->registry->registrations($token);
        } catch (RuntimeException $exception) {
            error_log('Registry pages unavailable: '.$exception::class);
            Session::flash('error', 'The registry could not be loaded. Please refresh the page.');
            [$stations, $registrations, $projection] = [[], [], ['unique_driver_count'=>0]];
        }
        try {
            $workspace = $this->registry->workspace($token);
        } catch (RuntimeException $exception) {
            error_log('Registry workspace unavailable: '.$exception::class);
            $workspace = [];
        }
        // Public availability label per station, shortened to the badge words the registry pages use.
        $availability = [];
        foreach ($workspace['stations'] ?? [] as $row) $availability[$row['id']] = match ($row['availability'] ?? null) {
            null => 'Not yet active', 'Open now' => 'Open', 'Under Maintenance' => 'Maintenance', default => (string) $row['availability'],
        };
        $me = $workspace['me'] ?? ['display_name' => '', 'phone' => null, 'version' => 1];
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
        foreach ($workspace['activity'] ?? [] as $a) $events[] = [$a['at'], $a['event'], $a['station'], 'Station administrator', $a['type']];
        usort($events, static fn (array $a, array $b): int => strcmp($b[0], $a[0]));
        return [
            'counts'=>['Registered stations'=>count($stations), 'Pending applications'=>count(array_filter($registrations, static fn (array $r): bool => $r['status'] === 'pending')), 'Registered drivers'=>(int)$projection['unique_driver_count']],
            'stations'=>array_map(static function (array $s) use ($availability): array {
                return ['id'=>$s['id'], 'name'=>$s['name'], 'area'=>$s['barangay'] ?: 'Hamtic', 'status'=>$s['registration_status'],
                    'registration'=>$s['registration_status'] === 'archived' ? 'Removed' : ucfirst($s['registration_status']),
                    'availability'=>$availability[$s['id']] ?? ($s['availability_label'] ?? ($s['active'] ? 'Active' : 'Inactive')),
                    'drivers'=>(int)$s['registered_count'], 'date'=>self::date($s['created_at'] ?? null), 'contact'=>self::phone($s['phone'])];
            }, $stations),
            'applications'=>$applications,
            'application'=>$selected ? [
                'id'=>$selected['id'], 'ref'=>'REG-'.strtoupper(substr($selected['id'], 0, 6)), 'name'=>$selected['station_name'], 'area'=>$selected['barangay'],
                'municipality'=>'Hamtic, Antique', 'address'=>$selected['address'], 'phone'=>self::phone($selected['station_phone']), 'email'=>$selected['station_email'] ?: 'Not provided',
                'applicant'=>$selected['owner_name'], 'applicant_role'=>$selected['owner_role'], 'applicant_phone'=>self::phone($selected['owner_phone']), 'applicant_email'=>$selected['owner_email'],
                'date'=>self::date($selected['created_at']), 'status'=>ucfirst($selected['status']), 'reason'=>$selected['decision_reason'], 'station_id'=>$selected['station_id'],
            ] : null,
            'drivers'=>array_map(static fn (array $d): array => ['name'=>$d['name'], 'station'=>$d['station'], 'phone'=>self::phone($d['phone']), 'vehicle'=>$d['vehicle'],
                'status'=>$d['active'] ? 'Active' : 'Inactive', 'date'=>self::date($d['created_at'])], $workspace['drivers'] ?? []),
            'driver_counts'=>array_map(static fn (array $s): array => ['station'=>$s['name'], 'registered'=>(int)$s['registered_count'], 'active'=>(int)$s['active_count']], $stations),
            'activity'=>array_map(static fn (array $e): array => ['time'=>self::date($e[0], true), 'event'=>$e[1], 'station'=>$e[2], 'actor'=>$e[3], 'type'=>$e[4]], array_slice($events, 0, 50)),
            'account'=>['name'=>$me['display_name'] !== '' ? $me['display_name'] : $user['name'], 'email'=>$user['email'], 'phone'=>self::phone($me['phone']), 'version'=>(int) $me['version']],
        ];
    }
    private function stationDrivers(string $token, string $stationId): array
    {
        try {
            $riders = $this->registry->stationRiders($token, $stationId);
        } catch (RuntimeException $exception) {
            // Before 20261122000200 is applied (or if it fails) list the drivers table as before, without the edit dialog.
            error_log('Station riders unavailable, using the driver table: '.$exception->getMessage());
            try {
                $riders = array_map(static fn (array $d): array => ['user_id'=>$d['user_id'], 'rider_id'=>null, 'version'=>null, 'full_name'=>$d['full_name'],
                    'phone'=>$d['phone'], 'email'=>$d['email'], 'capacity'=>$d['capacity'], 'enabled'=>(bool) $d['active'], 'trip_id'=>null, 'trip_no'=>null],
                    $this->registry->drivers($token, $stationId));
            } catch (RuntimeException $fallback) {
                error_log('Station drivers unavailable: '.$fallback->getMessage());
                Session::flash('error', 'The driver list could not be loaded. Please refresh the page.');
                return [];
            }
        }
        // Same positional shape as the sample (name, initials, phone, status, trip, capacity, account id, email), plus the editable rider record.
        return array_map(static fn (array $d): array => [$d['full_name'], self::initials($d['full_name']), self::phone($d['phone']),
            !$d['enabled'] ? 'Inactive' : ($d['trip_id'] !== null ? 'In progress' : 'Available'),
            $d['trip_id'] !== null ? 'Trip '.(int) $d['trip_no'] : 'No active trip', (string) $d['capacity'], $d['user_id'], $d['email'], $d], $riders);
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
