<?php
use App\UI\UI as U;
$role = $data['role'];
$info = $data['roleInfo'];
// A real customer session logs out through the server; previews just return to their login page.
$logoutHref = !empty($data['user']) ? '/logout?token='.App\Core\Session::csrfToken() : '/login/'.$role;
$path = (string)$data['path'];
$isRider = $role === 'rider';
$nav = [];
foreach ($info['nav'] as $item) {
    [$suffix,$label,$icon] = $item;
    $href='/'.$role.($suffix!==''?'/'.$suffix:'');
    $active = $suffix==='' ? $path===$href : ($path===$href || str_starts_with($path,$href.'/'));
    if($role==='customer' && $suffix==='stations' && in_array($data['screen'],['station','checkout'],true)) $active=true;
    if($role==='customer' && $suffix==='orders' && $data['screen']==='tracking') $active=true;
    if($role==='super-admin' && $suffix==='applications' && $data['screen']==='application') $active=true;
    if($role==='rider' && $suffix==='' && $data['screen']==='problem') $active=true;
    $nav[$suffix] = ['href'=>$href,'label'=>$label,'short'=>$item[3] ?? $label,'icon'=>$icon,'active'=>$active];
}
// Mobile: notifications take the last tab when the role has them; the avatar opens the account page.
$profile = $nav['account'] ?? $nav['settings'] ?? null;
$alerts = $nav['notifications'] ?? null;
U::$pageIcon = current(array_filter($nav, static fn($n) => $n['active']))['icon'] ?? '';
require dirname(__DIR__).'/partials/head.php';
?>
<body>
<a class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[110] focus:rounded focus:bg-white focus:p-3" href="#main-content">Skip to content</a>
<?php if (!$isRider): ?>
    <div class="mobile-header sticky top-0 z-30 flex h-14 items-center justify-between border-b border-line bg-white px-4 lg:hidden">
        <a href="/" class="flex items-center gap-1.5 font-heading text-xl font-extrabold tracking-tight"><?= U::logo('h-9') ?></a>
        <?php if ($profile): ?><a href="<?= U::e($profile['href']) ?>" class="rounded-full" aria-label="<?= U::e($profile['label']) ?>"<?= $profile['active']?' aria-current="page"':'' ?>><?= U::avatar($info['initials'], $profile['active'] ? 'ring-2 ring-water ring-offset-2' : '') ?></a><?php else: ?><button type="button" data-dialog-open="role-switch" aria-label="Switch role"><?= U::avatar($info['initials']) ?></button><?php endif ?>
    </div>
    <div data-sidebar-overlay class="fixed inset-0 z-40 hidden bg-ink/30 lg:hidden"></div>
    <aside data-sidebar class="fixed inset-y-0 left-0 z-50 flex w-64 -translate-x-full flex-col bg-water px-3 pb-3 pt-5 text-white transition-transform lg:translate-x-0">
        <a href="/" class="mb-5 flex items-center px-3 py-2"><?= U::logo('h-10 [filter:drop-shadow(0_0_1px_#fff)_drop-shadow(0_0_1px_#fff)_drop-shadow(0_3px_8px_rgba(4,40,60,.45))]') ?></a>
        <?php if ($role==='station-admin'): ?><div class="mb-4 rounded-lg bg-white/10 px-3.5 py-2.5"><p class="text-sm font-semibold"><?= U::e($info['caption']) ?></p><p class="mt-1 flex items-center gap-2 text-xs text-white/75"><?= U::icon('user','size-3.5') ?>Station admin</p></div><?php endif ?>
        <nav class="no-scrollbar flex-1 space-y-1 overflow-y-auto" aria-label="<?= U::e($info['label']) ?> navigation">
        <?php foreach($nav as $suffix => $n): ?><a href="<?= U::e($n['href']) ?>" class="nav-link <?= $n['active']?'nav-active':'' ?>" <?= $n['active']?'aria-current="page"':'' ?>><?= U::icon($n['icon'],'size-5 shrink-0') ?><span class="flex-1"><?= U::e($n['label']) ?></span><?php if (empty($data['preview']) && in_array($suffix, ['messages', 'notifications'], true)): ?><span data-nav-count="<?= U::e($suffix) ?>" hidden class="rounded-full bg-white px-2 text-xs font-bold text-water"></span><?php endif ?></a><?php endforeach ?>
        <?php if (empty($data['preview']) && in_array($role, ['customer', 'station-admin'], true)): ?><span data-nav-counts="/<?= U::e($role) ?>/navigation-counts" hidden></span><?php endif ?>
        </nav>
        <div class="mt-3 border-t border-white/20 px-3 pt-3">
            <div class="flex items-center gap-3"><?= U::avatar($info['initials']) ?><div class="min-w-0"><p class="truncate text-sm font-semibold"><?= U::e($info['name']) ?></p><p class="text-xs text-white/75"><?= U::e($info['label']) ?></p></div></div>
            <div class="mt-3 flex justify-between"><?php if ($data['preview']): ?><button class="text-xs font-semibold text-white hover:underline" data-dialog-open="role-switch">Switch role</button><?php endif ?><a class="flex items-center gap-1.5 text-xs text-white/80 hover:text-white" href="<?= U::e($logoutHref) ?>" data-logout="<?= U::e(strtolower($info['label'])) ?>"><?= U::icon('logout','size-3.5') ?>Log out</a></div>
        </div>
    </aside>
<?php endif ?>
<div class="<?= !$isRider?'lg:pl-64':'' ?>">
    <?php if($isRider): ?><header class="mobile-header sticky top-0 z-30 border-b border-line bg-white"><div class="mx-auto flex h-14 max-w-4xl items-center justify-between gap-4 px-4 md:px-8"><a class="flex items-center gap-1.5 font-heading text-xl font-bold tracking-tight" href="/"><?= U::logo('h-9') ?></a><div class="flex items-center gap-2.5"><span class="role-badge"><?= U::icon('truck','size-3.5') ?>Rider</span><a href="<?= U::e($profile['href']) ?>" class="rounded-full" aria-label="<?= U::e($profile['label']) ?>"<?= $profile['active']?' aria-current="page"':'' ?>><?= U::avatar($info['initials'], $profile['active'] ? 'ring-2 ring-water ring-offset-2' : '') ?></a></div></div></header><?php endif ?>
    <?php if (!empty($data['preview'])): ?><div class="preview-bar flex min-h-8 items-center justify-center gap-2 border-b border-line bg-white/75 px-4 text-[11px] text-muted">UI preview <span class="size-1 rounded-full bg-cyan-400"></span> Sample data <a href="/login/<?= U::e($role) ?>" class="ml-2 font-semibold text-water hover:underline">View login</a></div><?php endif ?>
    <main id="main-content" class="app-main mx-auto <?= $isRider?'max-w-4xl px-4 py-5 pb-28 md:px-8':($data['screen']==='messages'?'pb-24 md:pb-0':'max-w-[1600px] px-4 py-5 pb-28 sm:px-6 lg:px-8 lg:py-6') ?>">
        <?php $ownerMemberships = $data['setup']['memberships'] ?? $data['station_choices'] ?? []; ?>
        <?php if (!$data['preview'] && $role === 'station-admin' && count($ownerMemberships) > 1): ?>
        <form method="post" action="/station-admin/switch-station" class="mb-5 flex flex-wrap items-end gap-3">
            <input type="hidden" name="_token" value="<?= U::e(App\Core\Session::csrfToken()) ?>">
            <input type="hidden" name="request_key" value="<?= U::e(App\Services\StationSetup::requestKey()) ?>">
            <input type="hidden" name="expected_version" value="0">
            <input type="hidden" name="_form" value="switchStation">
            <label class="text-sm font-semibold">Station workspace<select name="station_id" class="field mt-1 block" required><?php if (!empty($data['needs_station_selection'])): ?><option value="" selected>Choose a station</option><?php endif ?><?php foreach ($ownerMemberships as $membership): ?><option value="<?= U::e($membership['station_id']) ?>"<?= $membership['station_id'] === ($data['setup']['station']['id'] ?? null) ? ' selected' : '' ?>><?= U::e($membership['station_name']) ?></option><?php endforeach ?></select></label>
            <button type="submit" class="btn-secondary">Open station</button>
        </form>
        <?php endif ?>
        <?php if (!empty($data['needs_station_selection'])): ?>
        <section class="panel p-5" role="status"><h1 class="section-title">Choose your station workspace</h1><p class="mt-2 text-sm text-muted">Select a station to open its settings and operations.</p></section>
        <?php elseif (!$data['preview'] && $role === 'station-admin' && !empty($data['setup_error'])): ?>
        <section class="panel p-5" role="status"><h1 class="section-title">Station workspace unavailable</h1><p class="mt-2 text-sm text-muted"><?= U::e($data['setup_error']) ?></p><a class="btn-secondary mt-4" href="/login/station-admin">Open login</a></section>
        <?php else: require dirname(__DIR__).'/'.$role.'/'.$data['screen'].'.php'; endif ?>
        <?php if ($data['screen'] !== 'messages'): ?><footer class="mt-8 flex flex-wrap items-center justify-between gap-2 border-t border-line py-4 text-[11px] text-muted"><span>HydroRoute · Hamtic, Antique</span><?= !empty($data['preview']) ? '<span>Sample data for UI preview</span>' : '' ?></footer><?php endif ?>
    </main>
</div>
<?php $tabs = $isRider ? array_values($nav) : array_slice(array_values($nav), 0, 4); $moreActive = !array_filter($tabs, static fn($n) => $n['active']); ?>
<nav class="mobile-tabbar fixed inset-x-0 bottom-0 z-30 border-t border-line bg-white/95 pb-[env(safe-area-inset-bottom)] shadow-[0_-4px_16px_rgb(8_60_83/0.06)] backdrop-blur <?= $isRider?'rider-nav':'lg:hidden' ?>" aria-label="<?= U::e($info['label']) ?> <?= $isRider?'navigation':'quick navigation' ?>">
    <div class="mx-auto grid <?= $isRider?'max-w-4xl grid-cols-3':'grid-cols-5' ?>">
        <?php foreach($tabs as $n): ?><a href="<?= U::e($n['href']) ?>" class="flex min-h-16 min-w-0 flex-col items-center justify-center gap-1 text-[11px] font-semibold <?= $n['active']?'text-water':'text-muted' ?>" <?= $n['active']?'aria-current="page"':'' ?>><span class="flex h-7 w-12 items-center justify-center rounded-full <?= $n['active']?'bg-water text-white':'' ?>"><?= U::icon($n['icon'],'size-5') ?></span><span class="max-w-full truncate px-1"><?= U::e($n['short']) ?></span></a><?php endforeach ?>
        <?php if ($isRider): ?>
        <?php elseif ($alerts): ?><a href="<?= U::e($alerts['href']) ?>" class="flex min-h-16 min-w-0 flex-col items-center justify-center gap-1 text-[11px] font-semibold <?= $alerts['active']?'text-water':'text-muted' ?>" <?= $alerts['active']?'aria-current="page"':'' ?>><span class="flex h-7 w-12 items-center justify-center rounded-full <?= $alerts['active']?'bg-water text-white':'' ?>"><?= U::icon('bell','size-5') ?></span><span class="max-w-full truncate px-1">Notifications</span></a>
        <?php else: ?><button type="button" data-sidebar-toggle aria-expanded="false" class="flex min-h-16 flex-col items-center justify-center gap-1 text-[11px] font-semibold <?= $moreActive?'text-water':'text-muted' ?>"><span class="flex h-7 w-12 items-center justify-center rounded-full <?= $moreActive?'bg-water text-white':'' ?>"><?= U::icon('menu','size-5') ?></span><span>More</span></button><?php endif ?>
    </div>
</nav>
<?php if ($data['preview']): ?><dialog id="role-switch" aria-labelledby="roles-title">
    <div class="mb-2 flex justify-between"><h2 id="roles-title" class="section-title">Explore another workspace</h2><button data-dialog-close aria-label="Close role chooser"><?= U::icon('close') ?></button></div>
    <p class="mb-5 text-sm text-muted">Choose a role to view its sample screens.</p>
    <div class="space-y-2"><?php foreach($data['roles'] as $key=>$r): ?><a href="/<?= U::e($key) ?>" class="flex items-center gap-3 rounded-lg border border-line p-4 hover:bg-foam"><?= U::icon($r['icon'],'size-5 text-water') ?><span class="flex-1 font-semibold"><?= U::e($r['label']) ?></span><?= U::icon('arrow-right','size-4') ?></a><?php endforeach ?></div>
</dialog><?php endif ?>
<?php require dirname(__DIR__).'/partials/feedback.php'; ?>
</body></html>

