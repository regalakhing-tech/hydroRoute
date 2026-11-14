// Live chat for the original messages design: real conversations, sending, 10-second updates,
// read receipts and reservation cards. Message text is always inserted with textContent.
(() => {
    'use strict';
    const root = document.querySelector('[data-chat-root][data-chat-live]');
    if (!root) return;
    let seed;
    try { seed = JSON.parse(root.querySelector('[data-chat-live-seed]').textContent); } catch { return; }
    const q = s => root.querySelector(s);
    const el = (tag, classes, text) => { const n = document.createElement(tag); n.className = classes; if (text !== undefined) n.textContent = text; return n; };
    const uuid = () => globalThis.crypto?.randomUUID?.() || 'xxxxxxxx-xxxx-4xxx-8xxx-xxxxxxxxxxxx'.replace(/x/g, () => (Math.random() * 16 | 0).toString(16));
    const post = async (url, fields) => {
        const data = new FormData(); data.set('_token', seed.token); data.set('request_key', uuid());
        for (const [k, v] of Object.entries(fields)) if (v !== null && v !== undefined) data.set(k, v);
        const r = await fetch(url, {method: 'POST', body: data, credentials: 'same-origin', headers: {Accept: 'application/json'}});
        if (r.status === 401) { location.reload(); throw new Error('session'); }
        return r.json();
    };
    const getJson = async url => { const r = await fetch(url, {credentials: 'same-origin', headers: {Accept: 'application/json'}}); if (r.status === 401) location.reload(); return r.json(); };
    const threads = seed.threads.map(t => ({...t, messages: null, status: ''}));
    if (seed.pending) threads.unshift({id: null, station: seed.pending.station, name: seed.pending.name, initials: seed.pending.initials, subtitle: 'Water station', last: 'Start a conversation', last_mine: false, unread: 0, messages: [], status: ''});
    let active = null, timer = 0, sending = false;

    function list() {
        const search = q('#chat-search').value.trim().toLocaleLowerCase();
        const box = q('[data-chat-threads]'); box.replaceChildren();
        const shown = threads.filter(t => t.name.toLocaleLowerCase().includes(search) || (t.last || '').toLocaleLowerCase().includes(search));
        q('[data-chat-no-results]').hidden = shown.length !== 0;
        if (seed.error) q('[data-chat-no-results]').textContent = seed.error;
        shown.forEach(t => {
            const b = el('button', 'flex w-full items-center gap-3 rounded-xl p-3 text-left transition-colors hover:bg-foam ' + (active === t ? 'bg-foam' : 'bg-white'));
            b.type = 'button'; b.setAttribute('aria-pressed', String(active === t)); b.setAttribute('aria-label', t.name + (t.unread ? ', unread messages' : ''));
            b.append(el('span', 'flex size-12 shrink-0 items-center justify-center rounded-full bg-water text-sm font-bold text-white', t.initials));
            const copy = el('span', 'min-w-0 flex-1');
            copy.append(el('span', 'block truncate text-[15px] font-semibold text-ink', t.name), el('span', 'mt-0.5 block truncate text-sm ' + (t.unread ? 'font-semibold text-ink' : 'text-muted'), (t.last_mine ? 'You: ' : '') + (t.last || 'No messages yet')));
            b.append(copy);
            if (t.unread) { const dot = el('span', 'size-2.5 shrink-0 rounded-full bg-water'); dot.setAttribute('aria-hidden', 'true'); b.append(dot); }
            b.addEventListener('click', () => select(t, true));
            box.append(b);
        });
    }

    function reservationCard(m) {
        const r = m.reservation, card = el('div', 'mx-auto w-full max-w-sm rounded-2xl border border-line bg-white p-4 text-sm shadow-sm');
        card.append(el('p', 'text-[11px] font-semibold uppercase tracking-wide text-water', 'Reservation · ' + r.state));
        card.append(el('p', 'mt-1 font-semibold', r.when + ' · ' + (r.fulfillment === 'pickup' ? 'Pickup' : 'Delivery')));
        if (r.items) card.append(el('p', 'mt-1 text-muted', r.items));
        if (r.note) card.append(el('p', 'mt-1 text-muted', 'Note: ' + r.note));
        if (r.reason) card.append(el('p', 'mt-1 text-muted', 'Reason: ' + r.reason));
        if (r.order_no) card.append(el('p', 'mt-1 font-semibold text-water', 'Order ' + r.order_no));
        if (r.state === 'requested') {
            const actions = el('div', 'mt-3 flex flex-wrap gap-2');
            const act = (label, cls, fn) => { const b = el('button', 'btn btn-sm ' + cls, label); b.type = 'button'; b.addEventListener('click', fn); actions.append(b); };
            const done = async (url, fields) => { const res = await post(url, fields).catch(() => null); feedback(res?.ok ? res.message : (res?.message || 'That did not work. Try again.')); if (res?.ok) load(active, true); };
            if (seed.role === 'station-admin') {
                act('Accept', 'btn-primary', () => done(seed.urls.respond, {reservation_id: r.id, expected_version: r.version, decision: 'accept', conversation_id: active.id}));
                act('Decline', 'btn-secondary', () => { const why = prompt('Reason for declining (the customer sees this):'); if (why && why.trim().length >= 3) done(seed.urls.respond, {reservation_id: r.id, expected_version: r.version, decision: 'decline', reason: why.trim(), conversation_id: active.id}); });
            } else act('Cancel request', 'btn-secondary', () => { if (confirm('Cancel this reservation request?')) done('/customer/reservations/cancel', {reservation_id: r.id, expected_version: r.version, conversation_id: active.id}); });
            card.append(actions);
        }
        return card;
    }

    function render() {
        const log = q('[data-chat-messages]'); log.replaceChildren();
        if (!active) return;
        if (!active.messages) { log.append(el('p', 'm-auto text-sm text-muted', 'Loading messages…')); return; }
        if (active.messages.length === 0) log.append(el('p', 'm-auto text-sm text-muted', 'No messages yet. Say hello.'));
        let lastDate = '';
        for (const m of active.messages) {
            const date = new Date(m.created_at).toLocaleDateString('en-PH', {month: 'short', day: 'numeric', year: 'numeric'});
            if (date !== lastDate) { log.append(el('p', 'mx-auto my-1 w-fit rounded-full bg-foam px-3 py-0.5 text-center text-[11px] font-semibold text-water', date)); lastDate = date; }
            if (m.reservation) { log.append(reservationCard(m)); continue; }
            if (m.kind === 'system') { log.append(el('p', 'mx-auto max-w-sm rounded-xl bg-white px-3 py-2 text-center text-xs text-muted ring-1 ring-line', m.body)); continue; }
            const row = el('div', 'flex flex-col ' + (m.mine ? 'items-end' : 'items-start'));
            row.append(el('p', 'max-w-[88%] whitespace-pre-wrap break-words rounded-2xl px-4 py-3 text-sm leading-relaxed shadow-sm md:max-w-[78%] ' + (m.mine ? 'rounded-br-md bg-water text-white' : 'rounded-bl-md border border-line bg-white text-ink'), m.body));
            row.append(el('span', 'mt-1 px-1 text-[11px] text-muted', new Date(m.created_at).toLocaleTimeString('en-PH', {hour: 'numeric', minute: '2-digit'}) + (m.order_no ? ' · ' + m.order_no : '')));
            log.append(row);
        }
        log.scrollTop = log.scrollHeight;
    }

    const feedback = text => { q('[data-chat-feedback]').textContent = text; };
    function mobile(showChat) {
        q('[data-chat-list-pane]').classList.toggle('hidden', showChat); q('[data-chat-list-pane]').classList.add('md:block');
        q('[data-chat-pane]').classList.toggle('hidden', !showChat); q('[data-chat-pane]').classList.add('md:flex');
    }
    function acknowledge(t) {
        const last = [...(t.messages || [])].reverse().find(m => !m.mine);
        if (!t.id || !last || document.hidden) return;
        post(seed.urls.read, {conversation_id: t.id, message_id: last.id}).catch(() => {});
        t.unread = 0; list();
    }
    async function load(t, full = false) {
        if (!t.id) { t.messages = []; render(); return; }
        const after = !full && t.messages?.length ? t.messages.at(-1) : null;
        const params = new URLSearchParams({conversation: t.id, after_id: after?.id || '', after_created_at: after?.created_at || ''});
        let body;
        try { body = await getJson(seed.urls.thread + '?' + params); } catch { return; }
        if (!body?.ok) { if (!t.messages) { t.messages = []; render(); } return; }
        const fresh = body.data.messages;
        t.messages = full || !t.messages ? fresh : [...t.messages, ...fresh.filter(m => !t.messages.some(x => x.id === m.id))];
        if (body.data.status) t.status = body.data.status;
        if (body.data.products) t.products = body.data.products;
        if (body.data.addresses) t.addresses = body.data.addresses;
        const last = t.messages.at(-1);
        if (last) { t.last = last.body; t.last_mine = last.mine; }
        if (t === active) { header(); if (full || fresh.length) render(); acknowledge(t); }
        list();
    }
    function header() {
        q('[data-chat-name]').textContent = active.name; q('[data-chat-avatar]').textContent = active.initials;
        q('[data-chat-subtitle]').textContent = active.subtitle + (active.status ? ' · ' + active.status : '');
        const context = q('[data-chat-context]'); context.replaceChildren();
        context.append(el('span', 'text-xs text-muted', seed.role === 'customer' ? 'Ask about refills, opening hours, your order or a reservation.' : 'Reply to the customer. Reservation requests appear as cards with Accept and Decline.'));
    }
    function select(t, updateURL = false, showChat = true) {
        active = t; mobile(showChat); header(); render(); list();
        if (!t.messages || t.messages.length === 0) load(t, true); else acknowledge(t);
        if (updateURL && t.id) { const url = new URL(location.href); url.search = ''; url.searchParams.set('conversation', t.id); history.replaceState(null, '', url); }
        clearTimeout(timer); timer = setTimeout(poll, 10000);
    }
    async function poll() {
        if (active?.id && !document.hidden && navigator.onLine) await load(active).catch(() => {});
        timer = setTimeout(poll, 10000);
    }
    function composer() { const body = q('#chat-body').value; q('[data-chat-count]').textContent = `${body.length} / 2000`; q('[data-chat-send]').disabled = sending || !body.trim() || body.length > 2000 || !active; }

    q('#chat-search').addEventListener('input', list);
    q('#chat-body').addEventListener('input', composer);
    q('[data-chat-back]').addEventListener('click', () => { mobile(false); q('#chat-search').focus(); });
    q('[data-chat-emoji-toggle]').addEventListener('click', () => { const p = q('#chat-emojis'); p.hidden = !p.hidden; q('[data-chat-emoji-toggle]').setAttribute('aria-expanded', String(!p.hidden)); });
    root.querySelectorAll('[data-chat-emoji]').forEach(b => b.addEventListener('click', () => { const i = q('#chat-body'); if (i.value.length + b.dataset.chatEmoji.length <= 2000) { i.setRangeText(b.dataset.chatEmoji, i.selectionStart, i.selectionEnd, 'end'); composer(); } q('#chat-emojis').hidden = true; i.focus(); }));
    q('#chat-body').addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); q('[data-chat-form]').requestSubmit(); } });
    q('[data-chat-form]').addEventListener('submit', async e => {
        e.preventDefault();
        const input = q('#chat-body'), body = input.value.trim();
        if (!body || input.value.length > 2000 || !active || sending) return;
        sending = true; composer(); feedback('Sending…');
        try {
            if (!active.id) {
                const started = await post(seed.urls.start, {station_id: active.station});
                if (!started.ok) throw new Error(started.message || 'start');
                active.id = started.data.conversation_id;
            }
            const res = await post(seed.urls.send, {conversation_id: active.id, body, topic: 'general'});
            if (!res.ok) { feedback(res.message || 'The message was not sent. Try again.'); return; }
            input.value = ''; feedback('Sent.');
            await load(active);
        } catch { feedback('The message was not sent. Check your connection and try again.'); }
        finally { sending = false; composer(); input.focus(); }
    });

    // Reservation request (customer): the dialog posts a normal form, then the page reopens this conversation.
    const dialog = document.getElementById('reservation-dialog');
    q('[data-chat-reserve]')?.addEventListener('click', async () => {
        if (!active || !dialog) return;
        if (!active.id) { const started = await post(seed.urls.start, {station_id: active.station}).catch(() => null); if (!started?.ok) { feedback('Could not open this conversation.'); return; } active.id = started.data.conversation_id; }
        if (!active.products) await load(active, true);
        const form = dialog.querySelector('[data-reservation-form]');
        form.elements.namedItem('request_key').value = uuid(); form.elements.namedItem('station_id').value = active.station; form.elements.namedItem('conversation_id').value = active.id;
        const products = form.querySelector('[data-reservation-products]'); products.replaceChildren();
        for (const p of active.products || []) {
            const label = el('label', 'flex items-center justify-between gap-2 rounded-lg border border-line p-2 text-sm'); label.append(el('span', '', p.name));
            const input = el('input', 'field w-20 py-1 text-center'); input.type = 'number'; input.min = '0'; input.max = '9999'; input.value = '0'; input.name = 'qty[' + p.id + ']'; input.setAttribute('aria-label', p.name + ' quantity');
            label.append(input); products.append(label);
        }
        const select = form.querySelector('[data-reservation-addresses]'); select.replaceChildren();
        for (const a of (active.addresses || []).filter(a => a.pinned)) { const o = el('option', '', a.label + ' — ' + a.address); o.value = a.id; select.append(o); }
        if (!select.options.length) { const o = el('option', '', 'Add a pinned address first (or choose pickup)'); o.value = ''; select.append(o); }
        dialog.showModal();
    });

    const params = new URLSearchParams(location.search);
    const wanted = params.get('conversation'), station = params.get('station'), customer = params.get('customer');
    const requested = threads.find(t => (wanted && t.id === wanted) || (station && t.station === station) || (customer && t.customer === customer)) || null;
    q('[data-chat-invalid]').hidden = !((wanted || station || customer) && !requested);
    list();
    if (requested || threads[0]) select(requested || threads[0], false, Boolean(requested));
    else { q('[data-chat-name]').textContent = 'No conversation yet'; q('[data-chat-subtitle]').textContent = ''; composer(); }
    document.addEventListener('visibilitychange', () => { if (!document.hidden && active) acknowledge(active); });
    composer();
})();
