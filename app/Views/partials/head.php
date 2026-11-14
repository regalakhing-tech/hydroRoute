<?php use App\UI\UI as U; ?>
<!doctype html>
<html lang="en"<?= isset($data['screen']) && in_array($data['role'] ?? '', ['station-admin', 'super-admin'], true) ? ' class="admin-compact"' : '' ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#e9f8fe">
    <meta name="description" content="Water ordering and delivery for Hamtic, Antique. Find your neighborhood station and manage your next refill.">
    <title><?= U::e($data['title'] ?? ucwords(str_replace('-', ' ', $data['screen'] ?? 'Welcome'))) ?> | HydroRoute</title>
    <link rel="icon" type="image/png" href="/assets/images/logo-icon.png">
    <link rel="preload" href="/assets/fonts/inter-latin-wght.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/assets/vendor/sweetalert2.min.css">
    <link rel="stylesheet" href="/assets/css/app.css">
    <?php if (empty($data['preview']) && ($data['role'] ?? '') === 'station-admin' && in_array($data['screen'] ?? '', ['settings-profile','settings-service-areas','settings-order-rules','products','schedules'], true)): ?>
    <?php if ($data['screen'] === 'settings-profile'): ?><link rel="stylesheet" href="/assets/vendor/leaflet/leaflet.css"><script src="/assets/vendor/leaflet/leaflet.js" defer></script><?php endif ?>
    <script src="/assets/js/station-setup.js" defer></script>
    <?php endif ?>
    <script src="/assets/vendor/sweetalert2.min.js" defer></script>
    <script src="/assets/js/app.js" defer></script>
    <script src="/assets/js/messages.js" defer></script>
    <script src="/assets/js/fulfillment.js" defer></script>
    <?php if (empty($data['preview']) && isset($data['screen'])): ?><script src="/assets/js/live.js" defer></script><?php endif ?>
</head>

