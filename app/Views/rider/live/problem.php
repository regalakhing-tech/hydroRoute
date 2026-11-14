<?php
// Original "Report a problem" design: records a failed attempt for the current stop.
use App\Services\Workspace;
use App\UI\LiveForm as F;
use App\UI\UI as U;

$live = Workspace::get()->load(static fn (Workspace $w): array => $w->trip(null));
$stops = $live['stops'] ?? [];
$stop = current(array_filter($stops, fn($s) => in_array($s['state'], ['pending','arrived'], true))) ?: null;
?>
<div class="mx-auto max-w-3xl space-y-4"><a href="/rider" class="inline-flex items-center gap-1.5 text-sm font-semibold text-water hover:underline"><?= U::icon('arrow-left','size-4') ?>Back to my trip</a><?= U::heading('Report a problem') ?>
<?php if ($stop === null): ?><section class="panel p-5 text-sm text-muted">You have no delivery in progress. Problems are reported for the stop you are at.</section></div><?php return; endif; ?>
<section class="flex items-center gap-3 rounded-xl bg-foam p-3.5"><span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-water text-white"><?= U::icon('user','size-5') ?></span><div class="min-w-0"><p class="text-xs font-semibold text-water">Trip <?= (int) $live['trip']['trip_no'] ?>, current delivery</p><h2 class="truncate font-bold"><?= U::e($stop['order']['customer_name'] ?? '') ?></h2><p class="truncate text-sm text-muted"><?= U::e($stop['order']['address_text'] ?? '') ?></p></div></section>
<?php if ($stop['state'] !== 'arrived'): ?><section class="panel p-5 text-sm"><p>Tap <strong>I have arrived</strong> on your trip first; then report the problem here.</p><div class="mt-3"><?= U::btn('My trip','/rider','primary','truck') ?></div></section></div><?php return; endif; ?>
<?= F::open('/rider/trips/result', ['stop_id' => $stop['id'], 'expected_version' => $stop['version'], 'result' => 'failed'], ['class' => 'panel space-y-4 p-4 md:p-6']) ?>
    <fieldset><legend class="label">What happened?</legend><div class="grid grid-cols-2 gap-2">
    <?php foreach ([['Customer unavailable','user'],['Cannot find address','pin'],['Container mismatch','bottle'],['Vehicle problem','truck'],['Other','help']] as $i => [$label,$icon]): ?><label class="flex cursor-pointer items-center gap-2.5 rounded-xl border border-line bg-white p-3 text-sm font-medium has-checked:border-water has-checked:bg-foam has-checked:text-water has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-water <?= $label==='Other'?'col-span-2':'' ?>"><input class="sr-only" type="radio" name="failure_reason" value="<?= U::e($label) ?>" <?= $i===0?'required':'' ?>><?= U::icon($icon,'size-5 shrink-0 text-water') ?><?= U::e($label) ?></label><?php endforeach ?>
    </div></fieldset>
    <?= U::textarea('Additional details','failure_detail','',['rows'=>'4','maxlength'=>'380','placeholder'=>'Describe the problem and any contact attempts.']) ?>
    <p class="flex items-start gap-2 rounded-lg bg-mist p-3 text-xs text-muted"><?= U::icon('shield','size-4 shrink-0') ?>The station sees this report and decides on another attempt. The customer is notified.</p>
    <?= U::btn('Send problem report','','primary','file',['type'=>'submit','class'=>'rider-action w-full','data-confirm'=>'Record this delivery as failed?']) ?>
</form></div>
