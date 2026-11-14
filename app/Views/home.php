<?php use App\UI\UI as U; $data['title']='Thesis project'; require __DIR__.'/partials/head.php'; ?>
<body class="bg-mist">
<header class="border-b border-line bg-white px-6 py-5"><a href="/" class="flex items-center gap-1 font-heading text-xl font-extrabold"><?= U::logo('h-9') ?></a></header>
<main class="mx-auto max-w-4xl px-5 py-10">
    <a href="/" class="mb-5 inline-flex items-center gap-2 text-sm font-semibold text-water"><?= U::icon('arrow-left','size-4') ?>Back to HydroRoute</a>
    <article class="rounded-xl border border-line bg-white px-6 py-12 text-center leading-loose shadow-sm sm:px-14">
        <h1 class="mx-auto mb-10 max-w-2xl font-heading text-lg font-bold leading-relaxed"><?= $e($data['thesis']['title']) ?></h1>
        <p>A Thesis Presented to the<br><?= $e($data['thesis']['faculty']) ?><br><?= $e($data['thesis']['university']) ?><br><?= $e($data['thesis']['address']) ?></p>
        <p class="my-9">In Partial Fulfillment for the Degree in<br><?= $e($data['thesis']['degree']) ?></p>
        <p class="mb-3">by</p><ul><?php foreach($data['thesis']['authors'] as $author): ?><li><?= $e($author) ?></li><?php endforeach ?></ul>
        <p class="mt-10"><?= $e($data['thesis']['date']) ?></p>
    </article>
</main></body></html>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Water ordering and delivery management thesis project">
    <title><?= $e($data['thesis']['title']) ?></title>
    <link rel="stylesheet" href="/assets/css/app.css">
    <script src="/assets/js/app.js" defer></script>
</head>
<body>
    <header class="site-header">
        <a href="/" class="station-name"><?= $e($data['app']['name']) ?></a>
        <span class="project-label">Thesis project</span>
    </header>
    <main class="title-page">
        <h1><?= $e($data['thesis']['title']) ?></h1>
        <section aria-label="Institution">
            <p>A Thesis Presented to the<br>
                <?= $e($data['thesis']['faculty']) ?><br>
                <?= $e($data['thesis']['university']) ?><br>
                <?= $e($data['thesis']['address']) ?></p>
        </section>
        <section aria-label="Degree">
            <p>In Partial Fulfillment for the Degree in<br>
                <?= $e($data['thesis']['degree']) ?></p>
        </section>
        <section aria-label="Authors">
            <p class="byline">by</p>
            <ul class="authors">
                <?php foreach ($data['thesis']['authors'] as $author): ?>
                    <li><?= $e($author) ?></li>
                <?php endforeach; ?>
            </ul>
        </section>
        <p class="thesis-date"><?= $e($data['thesis']['date']) ?></p>
    </main>
    <footer class="site-footer">Ordering and delivery management for water refilling stations</footer>
</body>
</html>
