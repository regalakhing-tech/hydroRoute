<?php
declare(strict_types=1);
namespace App\UI;

use App\Controllers\LiveController;
use App\Core\Session;

/** Small POST forms for live pages: every form carries the CSRF token and a fresh request key. */
final class LiveForm
{
    public static function open(string $action, array $fields = [], array $attrs = []): string
    {
        $html = '<form method="post" action="' . UI::e($action) . '"' . UI::attrs($attrs + ['data-submit-once' => true]) . '>'
            . '<input type="hidden" name="_token" value="' . UI::e(Session::csrfToken()) . '">'
            . '<input type="hidden" name="request_key" value="' . UI::e(LiveController::key()) . '">';
        foreach ($fields as $name => $value) $html .= '<input type="hidden" name="' . UI::e($name) . '" value="' . UI::e((string) $value) . '">';
        return $html;
    }

    /** A one-button form, optionally asking for confirmation first. */
    public static function button(string $action, array $fields, string $label, string $variant = 'secondary', string $icon = '', ?string $confirm = null, string $class = 'btn-sm'): string
    {
        $attrs = ['type' => 'submit', 'class' => $class];
        if ($confirm !== null) $attrs['data-confirm'] = $confirm;
        return self::open($action, $fields, ['class' => 'inline']) . UI::btn($label, '', $variant, $icon, $attrs) . '</form>';
    }

    /** A form with one required reason field and a submit button. */
    public static function reason(string $action, array $fields, string $label, string $placeholder, string $button, string $variant = 'secondary', ?string $confirm = null): string
    {
        $id = 'r-' . substr(md5($action . json_encode($fields)), 0, 10);
        $attrs = ['type' => 'submit', 'class' => 'btn-sm shrink-0'];
        if ($confirm !== null) $attrs['data-confirm'] = $confirm;
        return self::open($action, $fields, ['class' => 'flex flex-wrap items-end gap-2'])
            . '<div class="min-w-48 flex-1"><label class="label" for="' . $id . '">' . UI::e($label) . '</label><input class="field" id="' . $id . '" name="reason" required minlength="3" maxlength="500" placeholder="' . UI::e($placeholder) . '"></div>'
            . UI::btn($button, '', $variant, '', $attrs) . '</form>';
    }

    public static function chips(array $score): string
    {
        $chip = static fn (string $k, int|string $v, string $title): string => '<span class="inline-flex items-center rounded bg-mist px-1.5 py-0.5 text-[11px] font-semibold text-muted" title="' . UI::e($title) . '">' . $k . ' ' . UI::e((string) $v) . '</span>';
        return '<span class="inline-flex flex-wrap items-center gap-1"><span class="inline-flex min-w-9 justify-center rounded-md bg-ink px-2 py-0.5 text-xs font-bold text-white">' . (int) ($score['p'] ?? 0) . '</span>'
            . $chip('U', $score['u'] ?? 0, 'Urgency') . $chip('A', $score['a'] ?? 0, 'Waiting time') . $chip('C', $score['c'] ?? 0, 'Customer type') . '</span>';
    }
}
