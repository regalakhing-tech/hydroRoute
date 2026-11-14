(() => {
    'use strict';
    const root = document.querySelector('[data-chat-root]');
    if (!root) return;
    let seed;
    try { seed = JSON.parse(root.querySelector('[data-chat-seed]').textContent); } catch { return; }
    const q = selector => root.querySelector(selector);
    const key = id => `hamtic-chat:v1:thread:${id}`;
    const readKey = id => `hamtic-chat:v1:read:${seed.viewer}:${id}`;
    const states = new Map();
    const unsaved = new Set();
    let active = null, saveFailed = false, chatVisible = false;
    const storageGet = k => { try { return localStorage.getItem(k); } catch { return null; } };
    const storageSet = (k, value) => { try { localStorage.setItem(k, value); return true; } catch { return false; } };
    const validMessage = (m, thread) => m && typeof m.id === 'string' && typeof m.body === 'string' && m.body.trim().length > 0 && m.body.length <= 2000 && ['customer:' + thread.customer, 'station:' + thread.station].includes(m.sender) && typeof m.at === 'string' && Number.isFinite(Date.parse(m.at));
    function read(thread) {
        let stored;
        try { stored = JSON.parse(storageGet(key(thread.id))); } catch { stored = null; }
        const extra = Array.isArray(stored) ? stored.filter(m => validMessage(m, thread) && !thread.messages.some(s => s.id === m.id)).slice(-300) : [];
        const seen = new Set();
        return [...thread.messages, ...extra].filter(m => !seen.has(m.id) && seen.add(m.id));
    }
    seed.threads.forEach(t => states.set(t.id, read(t)));
    function reconcile(thread) {
        const messages = read(thread), ids = new Set(messages.map(message => message.id));
        for (const message of states.get(thread.id) || []) {
            if (unsaved.has(message.id) && !ids.has(message.id)) { messages.push(message); ids.add(message.id); }
        }
        return messages;
    }
    const el = (tag, classes, text) => { const node = document.createElement(tag); node.className = classes; if (text !== undefined) node.textContent = text; return node; };
    const time = at => new Date(at).toLocaleTimeString('en-PH', {hour:'numeric',minute:'2-digit'});
    function markRead() { if (active && chatVisible && document.visibilityState !== 'hidden') storageSet(readKey(active.id), states.get(active.id).at(-1)?.id || ''); }
    function list() {
        const search = q('#chat-search').value.trim().toLocaleLowerCase();
        const container = q('[data-chat-threads]');
        const focusedId = container.contains(document.activeElement) ? document.activeElement.closest('[data-chat-thread]')?.dataset.chatThread : null;
        container.replaceChildren();
        const filtered = seed.threads.filter(t => `${t.name} ${t.order || ''}`.toLocaleLowerCase().includes(search));
        q('[data-chat-no-results]').hidden = filtered.length !== 0;
        filtered.forEach(thread => {
            const messages = states.get(thread.id), last = messages.at(-1);
            const unread = last.sender !== seed.viewer && storageGet(readKey(thread.id)) !== last.id;
            const button = el('button', 'flex w-full items-center gap-3 rounded-xl p-3 text-left transition-colors hover:bg-foam ' + (active?.id === thread.id ? 'bg-foam' : 'bg-white'));
            button.type = 'button'; button.dataset.chatThread = thread.id;
            button.setAttribute('aria-pressed', String(active?.id === thread.id));
            button.setAttribute('aria-label', thread.name + (unread ? ', unread messages' : ''));
            button.append(el('span', 'flex size-12 shrink-0 items-center justify-center rounded-full bg-water text-sm font-bold text-white', thread.initials));
            const copy = el('span','min-w-0 flex-1'); copy.append(el('span','block truncate text-[15px] font-semibold text-ink',thread.name),el('span','mt-0.5 block truncate text-sm ' + (unread ? 'font-semibold text-ink' : 'text-muted'),last.body)); button.append(copy);
            if (unread) { const dot = el('span','size-2.5 shrink-0 rounded-full bg-water'); dot.setAttribute('aria-hidden','true'); button.append(dot); }
            button.addEventListener('click', () => select(thread, true)); container.append(button);
        });
        if (focusedId) [...container.querySelectorAll('[data-chat-thread]')].find(button => button.dataset.chatThread === focusedId)?.focus({preventScroll:true});
    }
    function renderMessages() {
        const log = q('[data-chat-messages]'); log.replaceChildren();
        log.append(el('p','mx-auto mb-2 w-fit rounded-full bg-white px-3 py-1 text-center text-[11px] text-muted ring-1 ring-line','Local conversation preview'));
        let lastDate = '';
        const messages = states.get(active.id);
        const peer = seed.viewer.startsWith('customer:') ? 'station:' + active.station : 'customer:' + active.customer;
        const peerReadIndex = messages.findIndex(message => message.id === storageGet(`hamtic-chat:v1:read:${peer}:${active.id}`));
        for (const [index, message] of messages.entries()) {
            const date = new Date(message.at).toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'});
            if(date !== lastDate) { log.append(el('p','mx-auto my-1 w-fit rounded-full bg-foam px-3 py-0.5 text-center text-[11px] font-semibold text-water',date)); lastDate=date; }
            const own = message.sender === seed.viewer;
            const row = el('div','flex flex-col ' + (own ? 'items-end' : 'items-start'));
            row.dataset.messageId = message.id;
            const bubble = el('p', 'max-w-[88%] whitespace-pre-wrap break-words rounded-2xl px-4 py-3 text-sm leading-relaxed shadow-sm md:max-w-[78%] ' + (own ? 'rounded-br-md bg-water text-white' : 'rounded-bl-md border border-line bg-white text-ink'), message.body);
            const seen = peerReadIndex >= index;
            row.append(bubble, el('span','mt-1 px-1 text-[11px] text-muted',time(message.at) + (own ? (unsaved.has(message.id) ? ' · This session only' : seen ? ' · Seen in preview' : ' · Saved on this device') : ''))); log.append(row);
        }
        log.scrollTop = log.scrollHeight;
    }
    function mobile(showChat) {
        chatVisible = showChat || window.innerWidth >= 768;
        q('[data-chat-list-pane]').classList.toggle('hidden', showChat);
        q('[data-chat-list-pane]').classList.add('md:block');
        q('[data-chat-pane]').classList.toggle('hidden', !showChat);
        q('[data-chat-pane]').classList.add('md:flex');
    }
    function select(thread, updateURL = false, showChat = true) {
        if (active?.id !== thread.id) { q('#chat-body').value=''; composer(); }
        active = thread; mobile(showChat); markRead();
        q('[data-chat-name]').textContent = thread.name; q('[data-chat-avatar]').textContent = thread.initials;
        q('[data-chat-subtitle]').textContent = thread.subtitle + ' · Local preview';
        const context = q('[data-chat-context]'); context.replaceChildren();
        if (thread.order) {
            const link = el('a','inline-flex items-center gap-1.5 rounded-full bg-white px-3 py-1 text-xs font-semibold text-water ring-1 ring-line hover:bg-foam',`Order ${thread.order}, ${thread.method === 'pickup' ? 'pickup' : 'delivery'}`);
            link.href = seed.role === 'customer' ? '/customer/tracking?order=' + encodeURIComponent(thread.order) : '/station-admin/orders?order=' + encodeURIComponent(thread.order); context.append(link);
        } else context.append(el('span','text-xs text-muted','Ask about refills, opening hours or your next visit.'));
        renderMessages(); list();
        if (updateURL) { const url = new URL(location.href); url.search = ''; url.searchParams.set('thread',thread.id); history.replaceState(null,'',url); q('[data-chat-invalid]').hidden = true; q('#chat-body').focus({preventScroll:true}); }
    }
    function composer() { const body = q('#chat-body').value; q('[data-chat-count]').textContent = `${body.length} / 2000`; q('[data-chat-send]').disabled = !body.trim() || body.length > 2000; }
    q('#chat-search').addEventListener('input',list);
    q('#chat-body').addEventListener('input',composer);
    q('[data-chat-back]').addEventListener('click',() => { mobile(false); q('#chat-search').focus(); });
    q('[data-chat-emoji-toggle]').addEventListener('click',() => { const panel = q('#chat-emojis'); panel.hidden = !panel.hidden; q('[data-chat-emoji-toggle]').setAttribute('aria-expanded',String(!panel.hidden)); });
    root.querySelectorAll('[data-chat-emoji]').forEach(button => button.addEventListener('click',() => { const input=q('#chat-body'); if (input.value.length + button.dataset.chatEmoji.length <= 2000) { input.setRangeText(button.dataset.chatEmoji,input.selectionStart,input.selectionEnd,'end'); composer(); } q('#chat-emojis').hidden=true; q('[data-chat-emoji-toggle]').setAttribute('aria-expanded','false'); input.focus(); }));
    q('#chat-body').addEventListener('keydown',event => { if(event.key === 'Enter' && !event.shiftKey && !event.isComposing) { event.preventDefault(); q('[data-chat-form]').requestSubmit(); } });
    q('[data-chat-form]').addEventListener('submit',event => {
        event.preventDefault(); const input=q('#chat-body'), body=input.value.trim();
        if (!body || input.value.length > 2000 || !active) { q('[data-chat-feedback]').textContent='Write a message between 1 and 2,000 characters.'; return; }
        // Re-read before appending so another role tab's latest messages survive.
        const messages = reconcile(active), message = {id:'local-' + (globalThis.crypto?.randomUUID?.() || Date.now() + '-' + Math.random().toString(36).slice(2)),sender:seed.viewer,body,at:new Date().toISOString()};
        messages.push(message); states.set(active.id,messages); unsaved.add(message.id);
        saveFailed = !storageSet(key(active.id),JSON.stringify(messages.filter(m => !active.messages.some(s=>s.id===m.id)).slice(-300)));
        if (!saveFailed) messages.forEach(message => unsaved.delete(message.id));
        input.value=''; markRead(); renderMessages(); list(); composer(); input.focus();
        q('[data-chat-feedback]').textContent=saveFailed ? 'Browser storage is unavailable. This message lasts only until you leave.' : 'Saved on this device. No real message was sent.';
        if(saveFailed) q('[data-chat-messages]').lastElementChild.lastElementChild.textContent=time(message.at)+' · This session only';
    });
    window.addEventListener('storage',event => {
        if (event.key === null || seed.threads.some(t => event.key === key(t.id) || event.key.startsWith('hamtic-chat:v1:read:') && event.key.endsWith(':' + t.id))) {
            seed.threads.forEach(t=>states.set(t.id,reconcile(t))); markRead(); renderMessages(); list();
        }
    });
    const params = new URLSearchParams(location.search), threadId = params.get('thread'), other = params.get(seed.role === 'customer' ? 'station' : 'customer');
    const requested = threadId ? seed.threads.find(t=>t.id===threadId) : other ? seed.threads.find(t=>t[seed.role === 'customer' ? 'station' : 'customer']===other) : null;
    const invalid = (threadId !== null || other !== null || [...params.keys()].some(k=>k.endsWith('[]'))) && !requested;
    q('[data-chat-invalid]').hidden=!invalid;
    select(requested || seed.threads[0],false,Boolean(requested));
    window.addEventListener('resize',()=>{ chatVisible = !q('[data-chat-pane]').classList.contains('hidden') || window.innerWidth >= 768; markRead(); list(); });
    document.addEventListener('visibilitychange',()=>{markRead();list();});
    composer();
})();
