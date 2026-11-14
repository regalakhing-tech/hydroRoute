<?php
declare(strict_types=1);

use App\Core\Session;
use App\Services\StationSetup;
use App\UI\UI as U;

$setup = $data['setup'] ?? null;
if (!is_array($setup) || !is_array($setup['station'] ?? null)) {
    echo U::heading('Station setup unavailable', 'Your station settings could not be loaded.');
    echo '<p role="alert" class="panel mt-5 p-5">' . U::e((string) ($data['setup_error'] ?? 'Please try again.')) . '</p>';
    return;
}
$station = $setup['station'];
$old = is_array($data['setup_old'] ?? null) ? $data['setup_old'] : [];
$errors = is_array($data['setup_errors'] ?? null) ? $data['setup_errors'] : [];
$value = static function (string $form, string $key, mixed $fallback = '') use ($old): mixed {
    return ($old['_form'] ?? '') === $form ? ($old[$key] ?? '') : $fallback;
};
$bool = static fn (mixed $value): bool => in_array($value, [true, 1, '1', 'on', 'true'], true);
$money = static function (mixed $centavos): string {
    $amount = (int) $centavos;
    return intdiv($amount, 100) . '.' . str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
};
$field = static fn (string $label, string $name, mixed $value = '', string $type = 'text', array $attrs = []): string => U::field($label, $name, (string) ($value ?? ''), $type, $attrs);
$check = static function (string $label, string $name, bool $checked, array $attrs = []): string {
    return '<label class="flex items-center gap-3 text-sm"><input' . U::attrs(array_merge(['type' => 'checkbox', 'name' => $name, 'value' => '1', 'checked' => $checked, 'class' => 'size-5 accent-water'], $attrs)) . '><span>' . U::e($label) . '</span></label>';
};
$start = static function (string $action, string $form, mixed $version, array $extra = [], array $attrs = []) use ($old, $errors): void {
    $matches = ($old['_form'] ?? '') === $form;
    foreach (['product_id', 'closure_id'] as $identity) {
        if (array_key_exists($identity, $extra) && (string) ($old[$identity] ?? '') !== (string) $extra[$identity]) {
            $matches = false;
        }
    }
    echo '<form' . U::attrs(array_merge(['method' => 'post', 'action' => '/station-admin/' . $action, 'class' => 'panel space-y-4 p-4 md:p-5'], $attrs)) . '>';
    $security = ['_token' => Session::csrfToken(), 'request_key' => $matches ? ($old['request_key'] ?? StationSetup::requestKey()) : StationSetup::requestKey(), 'expected_version' => $matches ? ($old['expected_version'] ?? $version) : $version, '_form' => $form];
    $hidden = $security + $extra;
    if ($matches && array_key_exists('closure_version', $extra)) {
        $hidden['closure_version'] = $old['closure_version'] ?? $extra['closure_version'];
    }
    foreach ($hidden as $key => $content) {
        echo '<input type="hidden" name="' . U::e($key) . '" value="' . U::e((string) ($content ?? '')) . '">';
    }
    if ($matches && $errors !== []) {
        echo '<ul class="rounded-lg bg-rose-50 p-4 text-sm text-rose-800" role="alert">';
        foreach ($errors as $fieldName => $message) {
            if (is_string($message)) echo '<li>' . U::e(ucfirst(str_replace('_', ' ', (string) $fieldName))) . ' — ' . U::e($message) . '</li>';
        }
        echo '</ul>';
    }
};
$save = static fn (string $label = 'Save changes'): string => U::btn($label, '', 'primary', 'check', ['type' => 'submit']);
$settingsTabs = static function (string $active): void {
    echo U::heading('Station settings', 'Manage your station profile, coverage and order rules.');
    echo U::tabs(['/station-admin/settings' => 'Station profile', '/station-admin/settings/service-areas' => 'Service areas', '/station-admin/settings/order-rules' => 'Order rules'], $active);
};
