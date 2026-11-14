<?php use App\UI\UI as U; require dirname(__DIR__).'/partials/head.php'; ?>
<body class="bg-white">
<div class="min-h-screen lg:grid lg:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)] 2xl:grid-cols-[680px_minmax(0,1fr)]">
    <aside class="relative hidden overflow-clip bg-foam lg:block">
        <div class="sticky top-0 isolate flex h-screen min-h-[640px] flex-col px-10 py-9 xl:px-14 xl:py-12">
            <img src="/assets/images/hero-water.png" alt="" class="absolute inset-0 -z-10 h-full w-full object-cover object-[93%_center]" width="1536" height="1024" fetchpriority="high">
            <div class="absolute inset-0 -z-10 bg-linear-to-b from-mist via-mist/85 via-35% to-transparent to-70%"></div>
            <a href="/" class="flex items-center gap-2 font-heading text-[26px] font-bold tracking-tight"><?= U::logo('h-10') ?></a>
            <div class="mt-12 max-w-md xl:mt-16">
                <p class="font-heading text-[34px] font-bold leading-[1.1] tracking-tight xl:text-[40px]"><?= U::e($data['roleInfo']['banner'] ?? 'Water refills from stations near you.') ?></p>
                <p class="mt-4 max-w-sm text-lg leading-relaxed text-ink/75">Order refills, follow deliveries and run your station from one place.</p>
                <p class="mt-6 inline-flex items-center gap-2 rounded-full border border-white bg-white/85 py-2 pl-3 pr-4 text-sm font-medium shadow-sm backdrop-blur"><?= U::icon('pin','size-4 text-water') ?>Hamtic, Antique</p>
            </div>
        </div>
    </aside>
    <div class="flex min-h-screen flex-col px-5 py-6 sm:px-12 lg:px-16 xl:px-24">
        <header class="flex items-center justify-between gap-4"><a href="/" class="flex items-center gap-2 font-heading text-xl font-bold lg:invisible"><?= U::logo('h-9') ?></a><a href="/" class="flex items-center gap-1.5 whitespace-nowrap text-sm font-semibold text-water hover:underline"><?= U::icon('arrow-left','size-4') ?>Back to home</a></header>
        <main class="mx-auto flex w-full max-w-[420px] flex-1 flex-col justify-center py-6 md:py-8">
            <?php require dirname(__DIR__).'/'.$data['authView'].'.php'; ?>
        </main>
        <footer class="text-center text-xs text-muted">HydroRoute accounts are verified by email and protected by Supabase Auth.</footer>
    </div>
</div>
<?php require dirname(__DIR__).'/partials/feedback.php'; ?>
</body></html>
