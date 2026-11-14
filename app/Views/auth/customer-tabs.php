<?php use App\UI\UI as U; /** @var string $tab */ ?>
<nav class="mb-6 grid grid-cols-2 rounded-xl bg-mist p-1 text-[15px] font-semibold ring-1 ring-line" aria-label="Customer account">
<?php foreach (['/login/customer'=>'Log in','/signup'=>'Create account'] as $href => $label): ?>
    <a href="<?= $href ?>" class="rounded-lg px-4 py-2.5 text-center <?= $href===$tab ? 'bg-white text-ink shadow-sm' : 'text-muted hover:text-ink' ?>"<?= $href===$tab ? ' aria-current="page"' : '' ?>><?= U::e($label) ?></a>
<?php endforeach ?>
</nav>
