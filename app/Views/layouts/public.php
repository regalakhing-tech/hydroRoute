<?php use App\UI\UI as U; require dirname(__DIR__).'/partials/head.php'; ?>
<body class="bg-white">
<a href="#main-content" class="sr-only focus:not-sr-only">Skip to content</a>
<header class="sticky top-0 z-30 border-b border-line bg-white/95 backdrop-blur">
    <nav class="mx-auto flex min-h-18 max-w-[1440px] 3xl:max-w-[1720px] items-center justify-between gap-5 px-5 md:min-h-20 md:px-10" aria-label="Main navigation">
        <a href="/" class="flex shrink-0 items-center gap-2 font-heading text-[23px] font-bold tracking-tight md:text-[26px]"><?= U::logo('h-10') ?></a>
        <div class="hidden items-center gap-8 text-[15px] font-medium text-ink/80 lg:flex"><a class="hover:text-water" href="/#stations">Find a station</a><a class="hover:text-water" href="/#how-it-works">How it works</a><a class="hover:text-water" href="/register-station">Register a station</a></div>
        <div class="flex items-center gap-4"><a href="/login" class="text-[15px] font-semibold text-ink hover:text-water">Log in</a><?= U::btn('Create account','/signup','primary','',['class'=>'hidden sm:inline-flex']) ?></div>
    </nav>
</header>
<main id="main-content"><?php require dirname(__DIR__).'/'.$data['view'].'.php'; ?></main>
<footer class="border-t border-line bg-mist">
    <div class="mx-auto grid max-w-[1440px] 3xl:max-w-[1720px] gap-10 px-5 py-12 sm:grid-cols-2 md:px-10 lg:grid-cols-[1.4fr_1fr_1fr]">
        <div><a href="/" class="flex items-center gap-2 font-heading text-xl font-bold"><?= U::logo('h-9') ?></a><p class="mt-3 max-w-xs text-sm leading-relaxed text-muted">Water refill ordering and delivery for neighborhoods in Hamtic, Antique.</p><?php if (!empty($data['preview'])): ?><p class="mt-5 text-xs text-muted">UI preview with sample data.</p><?php endif ?></div>
        <div><h2 class="mb-3 font-heading text-base font-semibold">For customers</h2><ul class="space-y-2 text-sm text-muted"><li><a href="/#stations" class="hover:text-water">Find a station</a></li><li><a href="/signup" class="hover:text-water">Create an account</a></li><li><a href="/login/customer" class="hover:text-water">Customer log in</a></li></ul></div>
        <div><h2 class="mb-3 font-heading text-base font-semibold">For stations and staff</h2><ul class="space-y-2 text-sm text-muted"><li><a href="/register-station" class="hover:text-water">Register a station</a></li><li><a href="/login/station-admin" class="hover:text-water">Station admin log in</a></li><li><a href="/login/rider" class="hover:text-water">Rider log in</a></li><li><a href="/login/super-admin" class="hover:text-water">Super admin log in</a></li><li><a href="/thesis" class="hover:text-water">About the thesis project</a></li></ul></div>
    </div>
</footer>
<?php require dirname(__DIR__).'/partials/feedback.php'; ?>
</body></html>
