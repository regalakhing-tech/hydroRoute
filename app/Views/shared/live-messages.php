<?php
// The original chat design (shared/messages.php), driven by real conversations through live-chat.js.
use App\Core\Session;
use App\Services\Workspace;
use App\UI\UI as U;

$role = $data['role'];
$isStation = $role === 'station-admin';
$inbox = Workspace::get()->load(static fn (Workspace $w): array => $w->inbox($isStation, []));
$initials = static function (string $name): string {
    $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: ['?'];
    return mb_strtoupper(mb_substr($words[0], 0, 1) . (isset($words[1]) ? mb_substr($words[1], 0, 1) : ''));
};
$threads = array_map(static fn (array $c): array => ['id' => $c['id'], 'station' => $c['station_id'], 'customer' => $c['customer_id'], 'name' => $c['name'],
    'initials' => $initials($c['name']), 'subtitle' => $isStation ? 'Customer' : 'Water station', 'last' => (string) ($c['last_body'] ?? ''),
    'last_mine' => (bool) $c['last_mine'], 'unread' => (int) $c['unread']], $inbox['conversations'] ?? []);
$pending = null;
$wanted = is_string($_GET['station'] ?? null) ? $_GET['station'] : '';
if (!$isStation && $wanted !== '' && !array_filter($threads, static fn (array $t): bool => $t['station'] === $wanted)) {
    foreach (Workspace::get()->load(static fn (Workspace $w): array => $w->directory())['stations'] ?? [] as $s) {
        if ($s['uuid'] === $wanted || $s['id'] === $wanted) $pending = ['station' => $s['uuid'], 'name' => $s['name'], 'initials' => $initials($s['name'])];
    }
}
$base = '/' . $role . '/messages';
$chat = ['live' => true, 'role' => $role, 'threads' => $threads, 'pending' => $pending, 'token' => Session::csrfToken(), 'error' => $inbox['error'] ?? null,
    'urls' => ['send' => $base . '/send', 'thread' => $base . '/thread', 'read' => $base . '/read', 'start' => '/customer/messages/start', 'reserve' => '/customer/reservations', 'respond' => '/station-admin/reservations/respond']];
?>
<section data-chat-root data-chat-live class="overflow-hidden md:bg-white">
    <script type="application/json" data-chat-live-seed><?= json_encode($chat, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
    <div class="grid grid-cols-[minmax(0,1fr)] md:h-[calc(100dvh-120px)] md:min-h-[480px] md:grid-cols-[320px_minmax(0,1fr)] lg:h-dvh xl:grid-cols-[360px_minmax(0,1fr)]">
        <aside data-chat-list-pane class="scroll-thin min-w-0 px-4 pb-6 pt-4 md:overflow-y-auto md:border-r md:border-line md:p-0">
            <div class="pb-2 md:sticky md:top-0 md:z-10 md:border-b md:border-line md:bg-white md:p-4"><h1 class="mb-3 flex items-center gap-2.5 font-heading text-xl font-bold tracking-tight md:text-2xl"><span class="title-icon"><?= U::icon('message','size-5') ?></span>Messages</h1><label for="chat-search" class="sr-only">Search conversations</label><div class="relative"><?= U::icon('search','pointer-events-none absolute left-4 top-1/2 size-4.5 -translate-y-1/2 text-muted') ?><input id="chat-search" type="search" placeholder="Search conversations" class="min-h-11 w-full rounded-full border border-line bg-white py-2.5 pl-11 pr-4 text-sm outline-none focus:border-water focus:ring-2 focus:ring-water/20 md:bg-mist" autocomplete="off"></div></div>
            <div data-chat-threads class="-mx-2 md:mx-0 md:p-2" aria-label="Conversations"></div><p data-chat-no-results hidden class="p-6 text-center text-sm text-muted"><?= $isStation ? 'No conversations yet. Customers start them from your station page.' : 'No conversations yet. Open a station and choose Message station.' ?></p>
        </aside>
        <div data-chat-pane class="flex min-h-0 min-w-0 flex-col bg-mist/40 max-md:fixed max-md:inset-0 max-md:z-40 max-md:bg-mist">
            <header class="flex items-center gap-3 border-b border-line bg-white px-3 pb-3 pt-[max(0.75rem,env(safe-area-inset-top))] md:px-6 md:py-4"><button data-chat-back type="button" class="rounded-lg p-2 text-water hover:bg-foam md:hidden" aria-label="Back to conversations"><?= U::icon('back','size-5') ?></button><span data-chat-avatar class="flex size-11 shrink-0 items-center justify-center rounded-full bg-water text-sm font-bold text-white"></span><div class="min-w-0 flex-1"><h2 data-chat-name class="truncate font-heading text-lg font-bold"></h2><p data-chat-subtitle class="flex items-center gap-1.5 text-xs text-muted"></p></div><?php if (!$isStation): ?><button type="button" data-chat-reserve class="rounded-full p-2.5 text-water hover:bg-foam" aria-label="Request a reservation" title="Request a reservation"><?= U::icon('calendar','size-5') ?></button><?php endif ?></header>
            <div data-chat-context class="border-b border-line bg-mist px-4 py-2 md:px-6"></div>
            <div data-chat-invalid hidden class="border-b border-line bg-amber-50 px-5 py-3 text-sm" role="status">That conversation is unavailable. Choose a conversation from your inbox.</div>
            <div data-chat-messages class="scroll-thin flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto overscroll-contain px-4 py-5 md:px-7 md:py-6" role="log" aria-label="Conversation messages" aria-live="polite" aria-relevant="additions"></div>
            <div class="border-t border-line bg-white px-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] pt-3 md:p-4 md:px-6"><form data-chat-form><label for="chat-body" class="sr-only">Message</label><div class="flex items-end gap-2"><div class="relative flex-1"><textarea id="chat-body" rows="1" maxlength="2000" placeholder="Write a message…" class="block max-h-32 min-h-11 w-full resize-none rounded-3xl border border-line bg-mist px-4 py-3 text-sm outline-none focus:border-water focus:ring-2 focus:ring-water/20"></textarea></div><button data-chat-emoji-toggle type="button" aria-label="Choose an emoji" aria-expanded="false" aria-controls="chat-emojis" class="rounded-full p-3 text-water hover:bg-foam"><?= U::icon('emoji','size-5') ?></button><button data-chat-send type="submit" aria-label="Send message" class="rounded-full bg-water p-3 text-white shadow-md shadow-water/30 transition hover:brightness-95 disabled:opacity-40 disabled:shadow-none"><?= U::icon('send','size-5') ?></button></div><div id="chat-emojis" hidden class="mt-2 flex gap-1" aria-label="Emoji choices"><?php foreach (['😊','👍','💧','🙏','✅'] as $emoji): ?><button type="button" data-chat-emoji="<?= U::e($emoji) ?>" aria-label="Insert <?= U::e($emoji) ?>" class="rounded-lg p-2 text-xl hover:bg-foam"><?= U::e($emoji) ?></button><?php endforeach ?></div><div class="mt-2 flex justify-between gap-3 text-xs text-muted"><span data-chat-feedback role="status">Enter to send · Shift + Enter for a new line</span><span data-chat-count>0 / 2000</span></div></form></div>
        </div>
    </div>
</section>
<?php if (!$isStation): ?>
<dialog id="reservation-dialog" class="w-full max-w-lg rounded-xl border border-line p-6 text-ink" aria-labelledby="reservation-title">
    <div class="mb-3 flex justify-between"><h2 id="reservation-title" class="section-title">Request a reservation</h2><button type="button" data-dialog-close aria-label="Close"><?= U::icon('close') ?></button></div>
    <p class="mb-4 text-sm text-muted">For a later date, a bigger order or an event. The station accepts, declines or replies in this chat; accepting creates a scheduled order at its current prices.</p>
    <form method="post" action="/customer/reservations" class="space-y-3" data-reservation-form>
        <input type="hidden" name="_token" value="<?= U::e(Session::csrfToken()) ?>"><input type="hidden" name="request_key" value=""><input type="hidden" name="station_id" value=""><input type="hidden" name="conversation_id" value="">
        <?= U::field('Date and time', 'requested_at', '', 'datetime-local', ['required' => true, 'id' => 'reservation-time']) ?>
        <fieldset><legend class="label">Delivery or pickup</legend><div class="flex gap-4 pt-1"><label class="flex items-center gap-2 text-sm"><input type="radio" name="fulfillment" value="pickup" checked class="accent-cyan-600">Pickup</label><label class="flex items-center gap-2 text-sm"><input type="radio" name="fulfillment" value="delivery" class="accent-cyan-600">Delivery</label></div></fieldset>
        <div><label class="label" for="reservation-address">Delivery address</label><select class="field" id="reservation-address" name="address_id" data-reservation-addresses></select></div>
        <div class="grid gap-2 sm:grid-cols-2" data-reservation-products></div>
        <?= U::textarea('Note', 'note', '', ['maxlength' => '500', 'id' => 'reservation-note', 'placeholder' => 'Event details or questions']) ?>
        <?= U::btn('Send reservation request', '', 'primary', 'calendar', ['type' => 'submit', 'class' => 'w-full']) ?>
    </form>
</dialog>
<?php endif ?>
