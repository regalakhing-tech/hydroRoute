<?php use App\UI\UI as U; $flash = \App\Core\Session::takeFlash(); ?>
<?php if ($flash): ?><div hidden data-flash="<?= U::e((string)$flash['type']) ?>" data-flash-message="<?= U::e((string)$flash['message']) ?>"></div><?php endif ?>
<div id="toast" class="pointer-events-none fixed bottom-24 left-1/2 z-[100] hidden w-[calc(100%-2rem)] max-w-md -translate-x-1/2 rounded-xl border border-cyan-200 bg-white p-4 text-sm font-medium text-ink shadow-lg lg:bottom-6" role="status" aria-live="polite"></div>
<dialog id="confirm-action" aria-labelledby="confirm-title">
    <div class="mb-4 flex items-center justify-between gap-4"><h2 id="confirm-title" class="section-title">Update this preview?</h2><button type="button" data-dialog-close aria-label="Close confirmation"><?= U::icon('close') ?></button></div>
    <p id="confirm-message" class="text-muted">This changes sample data in this browser only.</p>
    <div class="mt-6 flex justify-end gap-3"><?= U::btn('Cancel','','ghost','',['data-dialog-close'=>true]) ?><?= U::btn('Confirm','','primary','check',['id'=>'confirm-button']) ?></div>
</dialog>

