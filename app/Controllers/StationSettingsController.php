<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\Session;
use App\Services\CurrentUser;
use App\Services\StationSetup;
use App\Services\SupabaseException;
use Throwable;

/** Owner setup uses the current token and checked session station for every action. */
final class StationSettingsController extends Controller
{
    private const PATHS = [
        'profile'=>'/station-admin/settings', 'location'=>'/station-admin/settings',
        'serviceAreas'=>'/station-admin/settings/service-areas',
        'settings'=>'/station-admin/settings/order-rules', 'fees'=>'/station-admin/settings/order-rules',
        'product'=>'/station-admin/products', 'hours'=>'/station-admin/schedules',
        'closure'=>'/station-admin/schedules', 'cancelClosure'=>'/station-admin/schedules',
        'reopen'=>'/station-admin/schedules', 'switchStation'=>'/station-admin',
    ];

    public function __construct(private readonly StationSetup $setup, private readonly CurrentUser $current) {}

    public function profile(): Response { return $this->save('profile'); }
    public function location(): Response { return $this->save('location'); }
    public function serviceAreas(): Response { return $this->save('serviceAreas'); }
    public function settings(): Response { return $this->save('settings'); }
    public function fees(): Response { return $this->save('fees'); }
    public function product(): Response { return $this->save('product'); }
    public function hours(): Response { return $this->save('hours'); }
    public function closure(): Response { return $this->save('closure'); }
    public function cancelClosure(): Response { return $this->save('cancelClosure'); }
    public function reopen(): Response { return $this->save('reopen'); }

    public function switchStation(): Response
    {
        $path = self::PATHS['switchStation'];
        $user = $this->member('station-admin');
        if ($user instanceof Response) return $user;
        if ($failed = $this->csrfFailed($path)) return $failed;
        $token = $this->current->token();
        if ($token === null || $token === '') return $this->ended();
        $target = $_POST['station_id'] ?? null;
        if (!StationSetup::isUuid($target)) return $this->back($path, 'error', 'Choose an available station.');
        try {
            $context = $this->setup->load($token, $target);
            $station = $context['station'];
            if (!is_string($station['name'] ?? null) || !is_string($station['slug'] ?? null)) {
                throw new \RuntimeException('Station context unavailable.');
            }
        } catch (Throwable $error) {
            error_log('Station switch error: ' . $error::class);
            return $this->back($path, 'error', 'That station is unavailable for your account.');
        }
        // The token may have refreshed; update only the checked station on the current session user.
        $user = Session::user();
        if ($user === null) return $this->ended();
        $user['station'] = ['id'=>$target, 'name'=>$station['name'], 'slug'=>$station['slug']];
        $_SESSION['user'] = $user;
        Session::keepInput([]);
        unset($_SESSION['setup_errors']);
        return $this->back($path, 'success', 'Station switched.');
    }

    private function save(string $operation): Response
    {
        $path = self::PATHS[$operation];
        $user = $this->member('station-admin');
        if ($user instanceof Response) return $user;
        if ($failed = $this->csrfFailed($path)) return $failed;
        $token = $this->current->token();
        if ($token === null || $token === '') return $this->ended();
        $stationId = $user['station']['id'] ?? null;
        if (!StationSetup::isUuid($stationId)) return $this->back($path, 'error', 'Your station setup is unavailable. Please log in again.');
        Session::keepInput(StationSetup::oldInput($operation, $_POST));
        unset($_SESSION['setup_errors']);
        try {
            $result = $this->setup->mutate($token, $stationId, $operation, $_POST);
        } catch (Throwable $error) {
            error_log('Station setup error: ' . $error::class);
            $message = $error instanceof SupabaseException && ($error->status === 403 || $error->errorCode === '42501')
                ? 'You cannot change this station setup.'
                : 'We could not confirm this save. Please retry with the same form.';
            return $this->back($path, 'error', $message);
        }
        if ($result['ok'] !== true) {
            $errors = [];
            $allowed = array_merge(StationSetup::FIELDS[$operation], ['request_key','expected_version']);
            foreach ($result['field_errors'] as $field => $_message) {
                if (is_string($field) && in_array($field, $allowed, true)) $errors[$field] = 'Check this value.';
            }
            $_SESSION['setup_errors'] = $errors;
            $message = match ($result['error_code'] ?? null) {
                'forbidden', 'unauthorized', 'not_authorized', '42501' => 'You cannot change this station setup.',
                'stale_version', 'version_conflict', 'conflict' => 'This setup changed. Reload the page and review the latest values.',
                'idempotency_conflict', 'request_conflict', 'request_key_conflict' => 'This request key was already used for different values. Reload the page before saving again.',
                'invalid_input', 'validation_error', 'invalid_payload' => 'Check the highlighted values and try again.',
                default => 'We could not save these changes. Review the values and try again.',
            };
            return $this->back($path, 'error', $message);
        }
        Session::keepInput([]);
        unset($_SESSION['setup_errors']);
        return $this->back($path, 'success', match ($operation) {
            'profile'=>'Station profile saved.', 'location'=>'Station location saved.',
            'serviceAreas'=>'Service areas saved.', 'settings'=>'Order rules saved.',
            'fees'=>'Delivery fees saved.', 'product'=>'Product saved.', 'hours'=>'Opening hours saved.',
            'closure'=>'Closure saved.', 'cancelClosure'=>'Closure cancelled.',
            'reopen'=>'Manual closure cleared. Scheduled closures still apply.',
        });
    }

    private function ended(): Response
    {
        return $this->back('/login/station-admin', 'info', 'Your session ended. Please log in again.');
    }
}
