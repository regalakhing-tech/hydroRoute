<?php use App\UI\UI as U; ?>
<?= U::heading('Report a problem', 'Delivery problems are recorded on the stop itself.') ?>
<section class="panel p-5 text-sm"><p>Open <strong>My trip</strong>, tap <strong>I have arrived</strong> at the stop, then use <strong>Could not deliver</strong> and choose the reason. The station reviews it before another try.</p><p class="mt-2 text-muted">For anything else, call your station.</p><div class="mt-4"><?= U::btn('My trip', '/rider', 'primary', 'truck') ?></div></section>
