// Live pages only (APP_PREVIEW=false). Every form works without this file; it adds live totals,
// polling, show/hide by choice, confirmation prompts, one-click submits and rider location.
(() => {
    const $ = (s, root = document) => root.querySelector(s);
    const $$ = (s, root = document) => [...root.querySelectorAll(s)];
    const getJson = async url => {
        const response = await fetch(url, {headers: {Accept: 'application/json'}, credentials: 'same-origin'});
        if (response.status === 401) { location.reload(); throw new Error('session'); }
        return response.json();
    };

    // Show/hide blocks such as data-show-when="fulfillment=pickup" from the checked radio of that name.
    const syncShown = form => $$('[data-show-when]', form).forEach(block => {
        const [name, value] = block.dataset.showWhen.split('=');
        const checked = $(`input[name="${name}"]:checked`, form);
        const show = (checked?.value ?? '') === value;
        block.hidden = !show;
        $$('input,select,textarea', block).forEach(input => { if (!input.closest('[data-show-when]') || input.closest('[data-show-when]') === block) input.disabled = !show; });
    });
    $$('form').filter(f => $('[data-show-when]', f)).forEach(form => { syncShown(form); form.addEventListener('change', () => syncShown(form)); });
    $$('[data-no-js-only]').forEach(el => { el.hidden = true; });

    // Live checkout totals from the database quote.
    $$('form[data-live-quote]').forEach(form => {
        let timer = 0, seq = 0;
        const error = $('[data-quote-error]', form);
        const refresh = async () => {
            const mine = ++seq;
            const params = new URLSearchParams();
            for (const [k, v] of new FormData(form)) if (k !== '_token' && k !== 'request_key') params.append(k, v);
            try {
                const body = await getJson(form.dataset.liveQuote + '?' + params);
                if (mine !== seq) return;
                if (body.ok) {
                    for (const key of ['subtotal', 'fee', 'total', 'distance']) { const el = $(`[data-quote="${key}"]`, form); if (el) el.textContent = body.data[key] || '—'; }
                    error.classList.add('hidden'); error.textContent = '';
                    const lines = $('[data-quote-lines]', form);
                    lines?.replaceChildren(...(body.data.lines || []).map(([label, amount]) => {
                        const row = document.createElement('div'); row.className = 'flex justify-between gap-3';
                        const a = document.createElement('span'); a.textContent = label; const b = document.createElement('span'); b.textContent = amount;
                        row.append(a, b); return row;
                    }));
                } else {
                    error.textContent = body.message || 'Check your order details.'; error.classList.remove('hidden');
                }
            } catch { /* keep the last server-rendered totals */ }
        };
        form.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(refresh, 400); });
        form.addEventListener('change', () => { clearTimeout(timer); timer = setTimeout(refresh, 150); });
    });

    // Ask before destructive submits; then lock the form so a double click sends one request.
    document.addEventListener('click', async event => {
        const button = event.target.closest('button[data-confirm]');
        if (!button || button.dataset.confirmed === 'yes') return;
        event.preventDefault();
        const form = button.form;
        if (form && !form.reportValidity()) return;
        const ok = window.Swal
            ? (await window.Swal.fire({icon: 'warning', text: button.dataset.confirm, showCancelButton: true, confirmButtonText: 'Yes, continue', cancelButtonText: 'Go back'})).isConfirmed
            : window.confirm(button.dataset.confirm);
        if (ok) { button.dataset.confirmed = 'yes'; button.click(); }
    });
    document.addEventListener('submit', event => {
        const form = event.target;
        if (!form.matches('[data-submit-once]')) return;
        if (form.dataset.sent === 'yes') { event.preventDefault(); return; }
        form.dataset.sent = 'yes';
        setTimeout(() => $$('button[type="submit"]', form).forEach(b => { b.disabled = true; }), 0);
    });

    // Poll a small JSON endpoint; reload when the record's version changes. Pauses while hidden.
    $$('[data-poll-url]').forEach(el => {
        const seconds = Number(el.dataset.pollSeconds || 30);
        let failures = 0;
        const tick = async () => {
            if (document.hidden || !navigator.onLine) return schedule();
            try {
                const body = await getJson(el.dataset.pollUrl);
                failures = 0;
                if (body.ok && String(body.data.version) !== el.dataset.pollVersion) {
                    const typing = document.activeElement?.matches('input[type="text"],input:not([type]),textarea') && document.activeElement.value;
                    if (!typing) { location.reload(); return; }
                    el.hidden = false; el.textContent = 'This page has updates. Refresh to see them.';
                }
            } catch { failures++; }
            schedule();
        };
        const schedule = () => setTimeout(tick, seconds * 1000 * Math.min(4, 1 + failures));
        schedule();
    });

    // Unread badges in the navigation, refreshed every 60 seconds.
    const counts = $('[data-nav-counts]');
    if (counts) {
        const paint = data => $$('[data-nav-count]').forEach(badge => {
            const n = Number(data[badge.dataset.navCount] || 0);
            badge.textContent = n > 99 ? '99+' : String(n); badge.hidden = n === 0;
        });
        const tick = async () => {
            if (!document.hidden) { try { const body = await getJson(counts.dataset.navCounts); if (body.ok) paint(body.data); } catch { /* try again later */ } }
            setTimeout(tick, 60000);
        };
        tick();
    }

    // Rider: share position during an active trip only (about every 30 s or 50 m), and fill arrival forms.
    const tracker = $('[data-rider-track]');
    if (tracker && 'geolocation' in navigator) {
        let last = null, lastSent = 0;
        const status = $('[data-location-status]');
        const meters = (a, b) => {
            const r = 6371000, rad = Math.PI / 180, dLat = (b.lat - a.lat) * rad, dLon = (b.lon - a.lon) * rad;
            const h = Math.sin(dLat / 2) ** 2 + Math.cos(a.lat * rad) * Math.cos(b.lat * rad) * Math.sin(dLon / 2) ** 2;
            return 2 * r * Math.asin(Math.sqrt(h));
        };
        navigator.geolocation.watchPosition(async pos => {
            const here = {lat: pos.coords.latitude, lon: pos.coords.longitude, acc: Math.round(pos.coords.accuracy)};
            $$('[data-geo="latitude"]').forEach(i => { i.value = here.lat.toFixed(6); });
            $$('[data-geo="longitude"]').forEach(i => { i.value = here.lon.toFixed(6); });
            $$('[data-geo="accuracy"]').forEach(i => { i.value = String(here.acc); });
            $$('[data-geo-fallback]').forEach(i => { i.required = false; });
            $$('[data-distance-to]').forEach(el => {
                const [lat, lon] = el.dataset.distanceTo.split(',').map(Number);
                el.textContent = (meters(here, {lat, lon}) / 1000).toFixed(1) + ' km from you';
            });
            if (status) status.textContent = 'Location on (±' + here.acc + ' m).';
            const now = Date.now();
            if (last && now - lastSent < 30000 && meters(last, here) < 50) return;
            last = here; lastSent = now;
            const data = new FormData();
            data.set('_token', tracker.dataset.token); data.set('trip_id', tracker.dataset.riderTrack);
            data.set('latitude', here.lat.toFixed(6)); data.set('longitude', here.lon.toFixed(6)); data.set('accuracy', String(here.acc));
            try { await fetch('/rider/trips/location', {method: 'POST', body: data, credentials: 'same-origin', headers: {Accept: 'application/json'}}); } catch { /* next fix retries */ }
        }, () => { if (status) status.textContent = 'Location is off. You can still record arrivals with a reason.'; }, {enableHighAccuracy: true, maximumAge: 15000, timeout: 20000});
    }
})();
