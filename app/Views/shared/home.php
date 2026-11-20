<?php use App\UI\UI as U; use App\UI\Products; $stations = $data['stations']; $openCount = count(array_filter($stations, static fn($s) => $s['available'])); ?>
<section class="relative isolate overflow-hidden bg-foam">
    <img src="/assets/images/hero-water.webp" alt="Refill water container in a bright, sunlit room" class="anim-settle absolute inset-0 -z-10 h-full w-full object-cover object-[72%_15%]" fetchpriority="high" width="1536" height="1024">
    <div class="absolute inset-0 -z-10 bg-linear-to-b from-mist via-mist/90 to-mist/10 md:bg-linear-to-r md:from-mist md:via-mist/75 md:to-transparent"></div>
    <div class="bubbles pointer-events-none absolute inset-0 -z-10 overflow-hidden" aria-hidden="true"><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span></div>
    <svg class="wave-drift absolute bottom-0 left-0 -z-10 h-10 w-[200%] text-white/60 md:h-16" viewBox="0 0 2880 80" preserveAspectRatio="none" aria-hidden="true"><path fill="currentColor" d="M0 44C240 14 480 14 720 44S1200 74 1440 44 1920 14 2160 44 2640 74 2880 44V80H0Z"/></svg>
    <svg class="absolute inset-x-0 bottom-0 -z-10 h-10 w-full text-white md:h-16" viewBox="0 0 1440 80" preserveAspectRatio="none" aria-hidden="true"><path fill="currentColor" d="M0 52c160-30 320-36 480-14s320 40 480 18 320-50 480-26v50H0Z"/></svg>
    <div class="mx-auto flex max-w-[1440px] 3xl:max-w-[1720px] items-start px-5 pb-20 pt-8 md:min-h-[560px] md:items-center md:px-10 md:pb-24 md:pt-14 xl:min-h-[620px] 3xl:min-h-[min(44vw,900px)]">
        <div class="w-full max-w-[600px] 3xl:max-w-[760px]">
            <a href="#stations" class="anim-rise anim-d1 group mb-4 inline-flex max-w-full items-center gap-2 rounded-full border border-white bg-white/90 p-1 pr-3 text-xs text-ink shadow-sm backdrop-blur hover:bg-white sm:text-sm"><span class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full bg-emerald-600 px-2.5 py-1 text-[11px] font-bold text-white sm:text-xs"><span class="size-1.5 rounded-full bg-white"></span>Open now</span><span class="truncate"><span class="font-semibold"><?= $openCount ?> of <?= count($stations) ?> stations</span> taking orders in Hamtic</span><?= U::icon('chevron-right','size-4 shrink-0 text-water transition-transform group-hover:translate-x-0.5') ?></a>
            <h1 class="anim-rise anim-d2 max-w-[15ch] font-heading text-[32px] font-bold leading-[1.08] tracking-tight sm:text-5xl xl:text-[58px] 3xl:text-[76px]">Water refills from stations in your barangay.</h1>
            <p class="anim-rise anim-d3 mt-3 max-w-[30rem] text-[15px] leading-relaxed text-ink/75 md:mt-4 md:text-lg 3xl:mt-6 3xl:max-w-[36rem] 3xl:text-xl">See which Hamtic stations are open today, order your containers, and follow the rider to your door.</p>
            <div class="anim-rise anim-d4 mt-6 grid gap-2.5 sm:flex sm:flex-wrap sm:items-center sm:gap-3 3xl:mt-8"><?= U::btn('Find a station','#stations','primary','search',['class'=>'min-h-12 px-6 text-[15px] 3xl:min-h-14 3xl:px-8 3xl:text-base']) ?><?= U::btn('Register your station','/register-station','ghost','store',['class'=>'min-h-12 bg-white/90 px-6 text-[15px] 3xl:min-h-14 3xl:px-8 3xl:text-base']) ?></div>
        </div>
    </div>
</section>

<div class="relative z-10 mx-auto -mt-12 max-w-[1440px] 3xl:max-w-[1720px] px-5 md:-mt-14 md:px-10">
    <ul class="anim-rise anim-d4 grid grid-cols-4 gap-px overflow-hidden rounded-2xl border border-line bg-line shadow-lg shadow-ink/5">
    <?php foreach ([['store','Local stations'],['truck','Delivery or pickup'],['credit','Cash on delivery'],['clock','Live tracking']] as [$icon, $label]): ?>
        <li class="flex flex-col items-center gap-1.5 bg-white px-1.5 py-3 text-center lg:flex-row lg:gap-3 lg:p-5 lg:text-left"><span class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-water text-white lg:size-11"><?= U::icon($icon,'size-4.5 lg:size-5') ?></span><span class="text-[11px] font-semibold leading-tight sm:text-sm lg:text-base"><?= U::e($label) ?></span></li>
    <?php endforeach ?>
    </ul>
</div>

<section id="stations" class="mx-auto max-w-[1440px] 3xl:max-w-[1720px] scroll-mt-24 px-5 py-10 md:px-10 md:py-14">
    <div data-reveal class="mb-5 flex flex-col justify-between gap-4 md:flex-row md:items-center">
        <h2 class="flex items-center gap-2.5 font-heading text-2xl font-bold tracking-tight md:text-3xl"><span class="title-icon size-10"><?= U::icon('store','size-5') ?></span>Stations in Hamtic</h2>
        <div class="relative w-full md:w-80"><?= U::icon('search','absolute left-3.5 top-3 size-4 text-muted') ?><input aria-label="Search stations" class="field min-h-10 py-2 pl-10" type="search" placeholder="Station or barangay" data-search="public-stations"></div>
    </div>
    <div id="public-stations" data-reveal class="scroll-row -mx-5 scroll-px-5 px-5 md:mx-0 md:scroll-px-0 md:px-0 lg:grid lg:grid-cols-3 lg:overflow-visible lg:pb-0">
        <?php $cardClass = 'lg:w-auto'; foreach ($stations as $station) require dirname(__DIR__).'/partials/station-card.php'; ?>
    </div>
    <p data-empty class="hidden py-10 text-center text-muted">No stations match. Try another station or barangay.</p>
    <?php if ($stations === []): ?><p class="rounded-2xl border border-dashed border-line bg-mist p-8 text-center text-muted">No stations are listed yet. <a class="auth-link" href="/register-station">Register your station</a> to be the first.</p><?php endif ?>
    <?php if (!empty($data['preview'])): ?><p class="mt-3 text-xs text-muted">Sample stations for the UI preview. Hours and availability are illustrative.</p><?php endif ?>
</section>

<section class="bg-mist">
    <div class="mx-auto max-w-[1440px] 3xl:max-w-[1720px] px-5 py-10 md:px-10 md:py-14">
        <h2 data-reveal class="mb-5 flex items-center gap-2.5 font-heading text-2xl font-bold tracking-tight md:text-3xl"><span class="title-icon size-10 bg-white"><?= U::icon('bottle','size-5') ?></span>What you can order</h2>
        <div data-reveal class="scroll-row -mx-5 scroll-px-5 px-5 md:mx-0 md:scroll-px-0 md:px-0 lg:grid lg:grid-cols-3 lg:overflow-visible lg:pb-0">
        <?php foreach (Products::all() as $product): ?>
            <article class="lift flex w-[70%] shrink-0 items-center gap-4 rounded-2xl border border-line bg-white p-3 sm:w-80 lg:w-auto">
                <span class="flex size-24 shrink-0 items-center justify-center rounded-xl bg-foam md:size-28"><img src="<?= U::e($product['image']) ?>" alt="" class="h-20 w-20 object-contain md:h-24 md:w-24" width="256" height="384" loading="lazy"></span>
                <div class="min-w-0"><h3 class="font-semibold leading-snug"><?= U::e($product['name']) ?></h3><p class="text-sm text-muted"><?= U::e($product['pack']) ?></p><p class="mt-1 text-lg font-bold text-water"><?= U::money($product['price']) ?></p><p class="flex items-center gap-1.5 text-xs text-muted"><?= U::icon($product['returnable'] ? 'refresh' : 'bottle','size-3.5') ?><?= $product['returnable'] ? 'Bring your empty container' : 'No empty container needed' ?></p></div>
            </article>
        <?php endforeach ?>
        </div>
    </div>
</section>

<section id="how-it-works" class="scroll-mt-24 border-b border-line bg-white">
    <div class="mx-auto max-w-[1440px] 3xl:max-w-[1720px] px-5 py-10 md:px-10 md:py-14">
        <h2 data-reveal class="flex items-center gap-2.5 font-heading text-2xl font-bold tracking-tight md:text-3xl"><span class="title-icon size-10"><?= U::icon('help','size-5') ?></span>How ordering works</h2>
        <ol class="mt-6 grid gap-3 sm:grid-cols-3 md:gap-4">
        <?php foreach ([['pin','Choose a nearby station'],['bottle','Tell the station what you need'],['truck','Follow your delivery']] as $i => [$icon, $title]): ?>
            <li data-reveal class="rd-<?= $i ?> lift flex items-center gap-3 rounded-xl border border-line bg-mist p-3 md:p-4"><span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-water text-white"><?= U::icon($icon,'size-5.5') ?></span><h3 class="font-heading text-[15px] font-semibold leading-snug md:text-base"><span class="text-water"><?= $i + 1 ?>.</span> <?= U::e($title) ?></h3></li>
        <?php endforeach ?>
        </ol>
    </div>
</section>

<section class="mx-auto max-w-[1440px] 3xl:max-w-[1720px] px-5 py-10 md:px-10 md:py-14">
    <div data-reveal class="grid overflow-hidden rounded-2xl bg-ink text-white md:grid-cols-[minmax(0,1fr)_340px] lg:grid-cols-[minmax(0,1fr)_440px]">
        <div class="flex flex-col justify-center p-6 md:p-9">
            <h2 class="font-heading text-2xl font-bold tracking-tight md:text-3xl">Run a water refilling station?</h2>
            <ul class="mt-4 flex flex-wrap gap-x-6 gap-y-2.5 text-sm"><?php foreach ([['orders','Receive refill orders'],['truck','Assign riders'],['chart','See daily reports']] as [$icon, $text]): ?><li class="flex items-center gap-2"><span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-white/10"><?= U::icon($icon,'size-4.5') ?></span><?= U::e($text) ?></li><?php endforeach ?></ul>
            <div class="mt-6 flex flex-wrap items-center gap-x-5 gap-y-3"><a class="btn min-h-11 border-white bg-white px-5 text-ink hover:bg-foam" href="/register-station"><?= U::icon('store','size-4.5') ?>Register your station</a><a href="/login/station-admin" class="inline-flex items-center gap-2 text-sm font-semibold text-white underline decoration-white/40 underline-offset-4 hover:decoration-white"><?= U::icon('login','size-4.5') ?>Station admin log in</a></div>
        </div>
        <div class="order-first flex items-end justify-center bg-[#0d4a66] px-6 pt-6 md:order-none md:items-center md:p-6"><img src="/assets/images/station-illustration.svg" alt="" class="anim-float h-44 w-auto md:h-auto md:w-full" width="400" height="300" loading="lazy"></div>
    </div>
</section>
