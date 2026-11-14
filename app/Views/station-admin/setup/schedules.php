<?php
use App\UI\UI as U;
require __DIR__ . '/common.php';
if (!is_array($setup) || !is_array($setup['station'] ?? null)) return;
$availability = $setup['availability'];
$version = $availability['version'];
$windows = [];
foreach ($setup['opening_hours'] as $window) $windows[(int) $window['weekday'] . '-' . (int) $window['window_no']] = $window;
$oldWindows = $value('hours', 'windows', null);
if (is_array($oldWindows)) {
    $windows = [];
    foreach ($oldWindows as $window) {
        if (is_array($window)) $windows[(int) ($window['weekday'] ?? -1) . '-' . (int) ($window['window_no'] ?? -1)] = $window;
    }
}
$localTime = static function (mixed $instant): string {
    if (!is_string($instant) || $instant === '') return '';
    try {
        return (new DateTimeImmutable($instant))->setTimezone(new DateTimeZone('Asia/Manila'))->format('Y-m-d\TH:i');
    } catch (Exception) {
        return '';
    }
};
$editing = null;
$editingReplay = false;
$requestedClosure = is_string($_GET['closure'] ?? null) ? $_GET['closure'] : '';
if (($old['_form'] ?? '') === 'closure') $requestedClosure = (string) ($old['closure_id'] ?? '');
foreach ($setup['closures'] as $closure) {
    $retrying = ($old['_form'] ?? '') === 'closure' && (string) ($old['closure_id'] ?? '') === (string) $closure['id'];
    if ($closure['id'] === $requestedClosure && ($retrying || (!$closure['is_manual'] && $closure['cancelled_at'] === null))) {
        $editing = $closure;
        $editingReplay = $retrying && ($bool($closure['is_manual']) || $closure['cancelled_at'] !== null);
    }
}
?>
<?= U::heading('Hours and availability', 'Times are shown in Asia/Manila.', isset($availability['label']) ? U::badge((string) $availability['label']) : '') ?>
<div class="space-y-5">
<?php $start('schedules/hours', 'hours', $version, [], ['data-hours-form' => true]); ?>
<?= U::title('Weekly hours', 'clock') ?>
<p class="text-sm text-muted">Enable up to two windows each day. Leave both disabled for a rest day. Optional cutoffs must fall within the window. Blank cutoffs use closing time.</p>
<div class="space-y-4">
<?php $index = 0; foreach (['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $weekday => $day): ?>
<fieldset class="rounded-lg border border-line p-4"><legend class="px-1 font-bold"><?= U::e($day) ?></legend>
<div class="space-y-4">
<?php for ($windowNo = 1; $windowNo <= 2; $windowNo++):
    $window = $windows[$weekday . '-' . $windowNo] ?? [];
    $prefix = 'windows[' . $index . ']';
?>
<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5" data-hours-window>
<input type="hidden" name="<?= U::e($prefix) ?>[weekday]" value="<?= $weekday ?>">
<input type="hidden" name="<?= U::e($prefix) ?>[window_no]" value="<?= $windowNo ?>">
<?= $check('Window ' . $windowNo . ' enabled', $prefix . '[enabled]', $bool($window['enabled'] ?? false)) ?>
<?= $field('Opens', $prefix . '[opens_at]', $window['opens_at'] ?? '', 'time', ['step' => 1, 'aria-label' => $day . ' window ' . $windowNo . ' opens']) ?>
<?= $field('Closes', $prefix . '[closes_at]', $window['closes_at'] ?? '', 'time', ['step' => 1, 'aria-label' => $day . ' window ' . $windowNo . ' closes']) ?>
<?= $field('Order cutoff', $prefix . '[order_cutoff]', $window['order_cutoff'] ?? '', 'time', ['step' => 1, 'aria-label' => $day . ' window ' . $windowNo . ' order cutoff']) ?>
<?= $field('Delivery cutoff', $prefix . '[delivery_cutoff]', $window['delivery_cutoff'] ?? '', 'time', ['step' => 1, 'aria-label' => $day . ' window ' . $windowNo . ' delivery cutoff']) ?>
</div>
<?php $index++; endfor ?></div></fieldset>
<?php endforeach ?></div>
<?= $check('I confirm a closed-all-week schedule if every window is disabled', 'confirm_closed', $bool($value('hours', 'confirm_closed', false))) ?>
<?= $save('Save weekly hours') ?></form>
<div class="grid gap-5 xl:grid-cols-2">
<?php
$extra = $editing ? ['closure_id' => $editing['id'], 'closure_version' => $editing['version']] : [];
$start('schedules/closure', 'closure', $version, $extra, ['id' => 'closure-editor']);
?>
<?= U::title($editingReplay ? 'Confirm closure save' : ($editing ? 'Edit dated closure' : 'Temporary closure'), 'calendar') ?>
<?php if ($editingReplay): ?><p class="text-sm text-muted">The previous save outcome is unconfirmed. Retry the original request to confirm its result.</p><?php endif ?>
<?= U::select('Closure type', 'mode', ['closed' => 'Temporary closure', 'maintenance' => 'Maintenance', 'no_operations' => 'No operations'], (string) $value('closure', 'mode', $editing['mode'] ?? 'closed')) ?>
<?= $check('Manual closure (starts now or earlier)', 'is_manual', $bool($value('closure', 'is_manual', false)), ['data-manual-closure' => true]) ?>
<p class="text-sm text-muted">Enter a start time for every closure. A manual closure can have an expiry. Leaving its end blank closes new bookings until you reopen. Dated closures require both times.</p>
<?= $field('Starts (Manila time)', 'starts_at', $value('closure', 'starts_at', $localTime($editing['starts_at'] ?? null)), 'datetime-local', ['required' => true]) ?>
<?= $field('Ends (Manila time)', 'ends_at', $value('closure', 'ends_at', $localTime($editing['ends_at'] ?? null)), 'datetime-local') ?>
<?= U::textarea('Public notice', 'public_notice', (string) $value('closure', 'public_notice', $editing['public_notice'] ?? ''), ['maxlength' => 200]) ?>
<?= $save($editingReplay ? 'Retry closure save' : ($editing ? 'Update closure' : 'Save closure')) ?>
<?php if ($editing): ?><?= U::btn('New closure', '/station-admin/schedules', 'secondary', 'plus') ?><?php endif ?></form>
<section class="panel h-fit space-y-4 p-4 md:p-5">
<?= U::title('Manual availability', 'store') ?>
<?php if (!empty($availability['public_notice'])): ?><p><?= U::e((string) $availability['public_notice']) ?></p><?php endif ?>
<p class="text-sm text-muted">Reopening clears the manual override. Weekly hours and dated closures continue to apply.</p>
<?php $start('schedules/reopen', 'reopen', $version, [], ['class' => 'space-y-3']); ?>
<?= $save('Reopen station') ?></form></section>
</div>
<section class="panel space-y-4 p-4 md:p-5">
<?= U::title('Closures', 'calendar') ?>
<?php if ($setup['closures'] === []): ?><p role="status" class="text-muted">No closures have been configured.</p><?php endif ?>
<?php foreach ($setup['closures'] as $closure): ?>
<article class="space-y-3 border-t border-line pt-4">
<div class="flex flex-wrap items-center justify-between gap-3"><h3 class="font-bold"><?= $bool($closure['is_manual']) ? 'Manual closure' : 'Dated closure' ?></h3><?= U::badge($closure['cancelled_at'] === null ? 'Scheduled' : 'Cancelled', $closure['cancelled_at'] === null ? 'amber' : 'slate') ?></div>
<p class="text-sm"><?= U::e(str_replace('T', ' ', $localTime($closure['starts_at']))) ?> until <?= $closure['ends_at'] === null ? 'reopened' : U::e(str_replace('T', ' ', $localTime($closure['ends_at']))) ?></p>
<p><?= U::e((string) $closure['public_notice']) ?></p>
<?php
$cancelReplay = ($old['_form'] ?? '') === 'cancelClosure' && (string) ($old['closure_id'] ?? '') === (string) $closure['id'];
if ($closure['cancelled_at'] === null || $cancelReplay):
?>
<?php if ($closure['cancelled_at'] === null && !$bool($closure['is_manual'])): ?><?= U::btn('Edit closure', '/station-admin/schedules?closure=' . rawurlencode((string) $closure['id']) . '#closure-editor', 'secondary', 'edit') ?><?php endif ?>
<?php $start('schedules/closure/cancel', 'cancelClosure', $closure['version'], ['closure_id' => $closure['id']], ['class' => 'space-y-3']); ?>
<?php if ($cancelReplay && $closure['cancelled_at'] !== null): ?><p class="text-sm text-muted">This closure is cancelled. Retry the original request to confirm its result.</p><?php endif ?>
<?= $field('Cancellation reason', 'reason', (($old['closure_id'] ?? '') === $closure['id']) ? $value('cancelClosure', 'reason') : '', 'text', ['maxlength' => 200, 'id' => 'cancel-reason-' . $closure['id']]) ?>
<?= $save($cancelReplay && $closure['cancelled_at'] !== null ? 'Retry cancellation' : 'Cancel closure') ?></form>
<?php endif ?></article>
<?php endforeach ?></section>
<section class="panel space-y-4 p-4 md:p-5">
<?= U::title('Next seven days', 'calendar') ?>
<p class="text-sm text-muted">Bookable windows calculated from the saved station schedule and closures.</p>
<?php if (($availability['preview'] ?? []) === []): ?><p role="status">No preview is available.</p><?php endif ?>
<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
<?php foreach ($availability['preview'] ?? [] as $day): ?>
<article class="space-y-2 rounded-lg border border-line p-4"><h3 class="font-bold"><?= U::e((string) $day['date']) ?></h3>
<?php if ($day['windows'] === []): ?><p class="text-sm text-muted">No bookable windows.</p><?php endif ?>
<?php foreach ($day['windows'] as $window): ?><p class="text-sm"><?= U::e((string) $window['opens_at']) ?> – <?= U::e((string) $window['closes_at']) ?></p>
<?php if (!empty($window['order_cutoff'])): ?><p class="text-sm text-muted">Order cutoff <?= U::e((string) $window['order_cutoff']) ?></p><?php endif ?>
<?php if (!empty($window['delivery_cutoff'])): ?><p class="text-sm text-muted">Delivery cutoff <?= U::e((string) $window['delivery_cutoff']) ?></p><?php endif ?>
<?php endforeach ?>
<?php if (!empty($day['notice'])): ?><p class="text-sm"><?= U::e((string) $day['notice']) ?></p><?php endif ?>
</article><?php endforeach ?></div></section>
</div>
