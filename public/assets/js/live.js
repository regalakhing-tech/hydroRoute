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

    // Address pin picker. Tiles need a Referer for OpenStreetMap's tile policy (the site default sends none).
    // The barangay is detected locally from Hamtic boundaries; a street suggestion comes from the server.
    const pinForm = $('form[data-pin-picker]');
    if (pinForm) {
        const lat = pinForm.elements.namedItem('latitude'), lng = pinForm.elements.namedItem('longitude'), source = pinForm.elements.namedItem('pin_source');
        const street = pinForm.elements.namedItem('street'), barangay = pinForm.elements.namedItem('barangay_id');
        const status = $('[data-pin-status]', pinForm), device = $('[data-pin-device]', pinForm);
        const b = {south: 10.584854, north: 10.746484, west: 121.961241, east: 122.07373};
        const inside = (y, x) => Number.isFinite(y) && Number.isFinite(x) && y >= b.south && y <= b.north && x >= b.west && x <= b.east;
        const say = text => { status.textContent = text; };
        let map = null, marker = null, ring = null, boundaries = null, lookup = 0;
        const inRing = (x, y, pts) => {
            let hit = false;
            for (let i = 0, j = pts.length - 1; i < pts.length; j = i++) {
                const [xi, yi] = pts[i], [xj, yj] = pts[j];
                if ((yi > y) !== (yj > y) && x < (xj - xi) * (y - yi) / (yj - yi) + xi) hit = !hit;
            }
            return hit;
        };
        const findBarangay = async (y, x) => {
            boundaries ??= fetch(pinForm.dataset.boundaries, {credentials: 'same-origin'}).then(r => r.json()).catch(() => ({features: []}));
            const data = await boundaries;
            return data.features.find(f => inRing(x, y, f.rings[0]) && !f.rings.slice(1).some(h => inRing(x, y, h))) || null;
        };
        const fillFromPin = async (y, x) => {
            const mine = ++lookup;
            const found = await findBarangay(y, x);
            if (mine !== lookup) return;
            const option = found ? [...barangay.options].find(o => o.dataset.psgc === found.psgc) : null;
            if (option) { barangay.value = option.value; say('Pin placed in Barangay ' + found.name + '. Check the street details, then save.'); }
            else say('Pin placed, but it is not inside a Hamtic barangay. Move the pin or choose the barangay yourself.');
            try {
                const body = await getJson(pinForm.dataset.reverse + '?' + new URLSearchParams({lat: y.toFixed(7), lng: x.toFixed(7)}));
                if (mine !== lookup || !body.ok) return;
                // OpenStreetMap barangay boundaries are more detailed than the bundled outline, so a matching name wins.
                const norm = t => t.normalize('NFKC').toLowerCase().replace(/[^a-z0-9]/g, '');
                const osm = [...barangay.options].find(o => o.value && (body.data.areas || []).some(a => norm(a) === norm(o.textContent)));
                if (osm) { barangay.value = osm.value; say('Pin placed in Barangay ' + osm.textContent + '. Check the street details, then save.'); }
                if (street.value.trim() !== '' && street.dataset.auto !== 'yes') return;
                const text = [body.data.street, body.data.place].filter((v, i, all) => v && all.indexOf(v) === i && (!osm || norm(v) !== norm(osm.textContent))).join(', ');
                if (text) { street.value = text; street.dataset.auto = 'yes'; }
            } catch { /* the bundled outline result and typed street stay */ }
        };
        street.addEventListener('input', () => { street.dataset.auto = 'no'; });
        const show = (y, x, accuracy = null) => {
            if (!map) return;
            if (marker) marker.setLatLng([y, x]);
            else { marker = window.L.marker([y, x], {draggable: true, title: 'Address pin'}).addTo(map); marker.on('dragend', () => { const p = marker.getLatLng(); choose(p.lat, p.lng, 'map'); }); }
            if (ring) { ring.remove(); ring = null; }
            if (accuracy) ring = window.L.circle([y, x], {radius: accuracy, color: '#0ea5e9', weight: 1, fillOpacity: 0.12}).addTo(map);
            map.setView([y, x], Math.max(map.getZoom(), 17));
        };
        const choose = (y, x, how, accuracy = null) => {
            if (!inside(y, x)) { say('That point is outside Hamtic. Tap the map inside Hamtic to place your pin.'); return false; }
            lat.value = y.toFixed(7); lng.value = x.toFixed(7); source.value = how; show(y, x, accuracy);
            fillFromPin(y, x);
            return true;
        };
        if (window.L) {
            const y0 = Number(lat.value), x0 = Number(lng.value), has = lat.value !== '' && inside(y0, x0);
            map = window.L.map($('[data-pin-map]', pinForm)).setView(has ? [y0, x0] : [10.6975, 122.0326], has ? 17 : 13);
            window.L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {maxZoom: 19, referrerPolicy: 'strict-origin-when-cross-origin',
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'}).addTo(map);
            map.on('click', e => choose(e.latlng.lat, e.latlng.lng, 'map'));
            if (has) show(y0, x0);
        } else {
            $('[data-pin-map]', pinForm).hidden = true;
            say('The map is unavailable. Use your current location or type the coordinates.');
        }
        [lat, lng].forEach(input => input.addEventListener('change', () => {
            const y = Number(lat.value), x = Number(lng.value);
            if (inside(y, x)) choose(y, x, 'manual'); else if (lat.value && lng.value) say('Those coordinates are outside Hamtic.');
        }));
        // Watch the position for up to 20 seconds and keep the most accurate fix; stop early once it is within 20 m.
        if (!navigator.geolocation) device.hidden = true;
        else device.addEventListener('click', () => {
            device.disabled = true;
            say('Finding your location… stay still, outdoors if you can.');
            let best = null, watch = 0;
            let done = false;
            const finish = () => {
                if (done) return; done = true;
                navigator.geolocation.clearWatch(watch); clearTimeout(timer); device.disabled = false;
                if (!best) { say('Location is unavailable. Allow location access, or tap the map.'); return; }
                const {latitude: y, longitude: x, accuracy} = best.coords, metres = Math.round(accuracy);
                if (!choose(y, x, 'device', accuracy)) {
                    say('Your device reports a point outside Hamtic (±' + metres + ' m). Computers often guess location from Wi-Fi; use your phone or tap the map.');
                } else if (metres > 50) say(status.textContent + ' Location is approximate (±' + metres + ' m): drag the pin to your exact gate.');
            };
            const timer = setTimeout(finish, 20000);
            watch = navigator.geolocation.watchPosition(p => {
                if (!best || p.coords.accuracy < best.coords.accuracy) best = p;
                say('Finding your location… ±' + Math.round(best.coords.accuracy) + ' m');
                if (best.coords.accuracy <= 20) finish();
            }, () => finish(), {enableHighAccuracy: true, maximumAge: 0, timeout: 20000});
        });
    }

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

    $$('[data-print]').forEach(b => b.addEventListener('click', () => window.print()));

    // Navigation badges (orders, messages, notices), refreshed every 20 seconds. A notice newer than the last one
    // this browser showed pops up once as a toast that opens the order.
    const counts = $('[data-nav-counts]');
    if (counts) {
        const role = counts.dataset.navCounts.split('/')[1];
        const seenKey = 'hr-last-notice:' + role;
        const paint = data => $$('[data-nav-count]').forEach(badge => {
            const n = Number(data[badge.dataset.navCount] || 0);
            badge.textContent = n > 99 ? '99+' : String(n); badge.hidden = n === 0;
        });
        const target = note => note.order_id
            ? (role === 'station-admin' ? '/station-admin/orders?order=' : '/customer/tracking?order=') + encodeURIComponent(note.order_id)
            : (/message/.test(note.kind) ? `/${role}/messages` : (role === 'station-admin' ? '/station-admin' : '/customer/notifications'));
        const popup = note => {
            const href = target(note);
            if (window.Swal) {
                window.Swal.fire({toast: true, position: 'top-end', icon: 'info', title: note.title, text: note.body || '', showConfirmButton: true,
                    confirmButtonText: note.order_id ? 'View order' : 'Open', showCloseButton: true, timer: 15000, timerProgressBar: true, buttonsStyling: false,
                    customClass: {popup: 'cursor-pointer', confirmButton: 'btn btn-primary btn-sm'},
                    didOpen: el => el.addEventListener('click', e => { if (!e.target.closest('.swal2-close')) location.href = href; })});
                return;
            }
            const link = document.createElement('a');
            link.href = href; link.setAttribute('role', 'status');
            link.className = 'fixed right-4 top-4 z-[100] block max-w-sm rounded-xl border border-cyan-200 bg-white p-4 text-sm shadow-lg';
            const title = document.createElement('strong'); title.className = 'block'; title.textContent = note.title;
            const body = document.createElement('span'); body.className = 'mt-1 block text-muted'; body.textContent = note.body || '';
            link.append(title, body); document.body.append(link);
            setTimeout(() => link.remove(), 15000);
        };
        const announce = note => {
            if (!note || !note.created_at) return;
            let seen = null;
            try { seen = localStorage.getItem(seenKey); localStorage.setItem(seenKey, note.created_at); } catch { return; }
            // First visit in this browser: remember the newest notice without replaying old ones.
            if (seen !== null && Date.parse(note.created_at) > Date.parse(seen)) popup(note);
        };
        const tick = async () => {
            if (!document.hidden) {
                try { const body = await getJson(counts.dataset.navCounts); if (body.ok) { paint(body.data); announce(body.data.latest); } } catch { /* try again later */ }
            }
            setTimeout(tick, 20000);
        };
        tick();
    }

    // Open conversation: fetch new messages every 10 s, append text safely, and acknowledge what was seen.
    const log = $('[data-chat-log]');
    if (log) {
        $$('[data-no-js-read]').forEach(f => { f.hidden = true; });
        log.scrollTop = log.scrollHeight;
        const notice = $('[data-chat-notice]');
        const acknowledge = async id => {
            if (!id) return;
            const data = new FormData(); data.set('_token', log.dataset.token); data.set('conversation_id', log.dataset.conversation); data.set('message_id', id);
            try { await fetch(log.dataset.readUrl, {method: 'POST', body: data, credentials: 'same-origin', headers: {Accept: 'application/json'}}); } catch { /* next poll retries */ }
        };
        if (log.dataset.unread === '1' && !document.hidden) acknowledge(log.dataset.afterId);
        let failures = 0;
        const poll = async () => {
            if (!document.hidden && navigator.onLine) {
                try {
                    const params = new URLSearchParams({after_id: log.dataset.afterId || '', after_created_at: log.dataset.afterCreated || ''});
                    const body = await getJson(log.dataset.threadUrl + '&' + params);
                    failures = 0;
                    let others = null;
                    for (const m of body.ok ? body.data.messages : []) {
                        log.dataset.afterId = m.id; log.dataset.afterCreated = m.created_at;
                        const empty = $('[data-chat-empty]', log); if (empty) empty.hidden = true;
                        if (m.kind !== 'text') { notice.textContent = 'There is a reservation update. Refresh to see it.'; notice.classList.remove('hidden'); continue; }
                        const row = document.createElement('div'); row.className = 'flex' + (m.mine ? ' justify-end' : '');
                        const bubble = document.createElement('div'); bubble.className = 'max-w-[80%] rounded-2xl px-4 py-2.5 ' + (m.mine ? 'bg-water text-white' : 'bg-mist');
                        const text = document.createElement('p'); text.className = 'whitespace-pre-wrap break-words text-sm'; text.textContent = m.body;
                        const meta = document.createElement('p'); meta.className = 'mt-1 text-[11px] ' + (m.mine ? 'text-white/80' : 'text-muted'); meta.textContent = m.time + (m.order_no ? ' · ' + m.order_no : '');
                        bubble.append(text, meta); row.append(bubble); log.append(row);
                        if (!m.mine) others = m.id;
                    }
                    if (others) { log.scrollTop = log.scrollHeight; acknowledge(others); }
                } catch { failures++; }
            }
            setTimeout(poll, 10000 * Math.min(6, 1 + failures));
        };
        setTimeout(poll, 10000);
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
            if (status) status.textContent = 'Location on · ±' + here.acc + ' m';
            const now = Date.now();
            if (last && now - lastSent < 30000 && meters(last, here) < 50) return;
            last = here; lastSent = now;
            const data = new FormData();
            data.set('_token', tracker.dataset.token); data.set('trip_id', tracker.dataset.riderTrack);
            data.set('latitude', here.lat.toFixed(6)); data.set('longitude', here.lon.toFixed(6)); data.set('accuracy', String(here.acc));
            try { await fetch('/rider/trips/location', {method: 'POST', body: data, credentials: 'same-origin', headers: {Accept: 'application/json'}}); } catch { /* next fix retries */ }
        }, () => { if (status) status.textContent = 'Location off · arrivals need a reason'; }, {enableHighAccuracy: true, maximumAge: 15000, timeout: 20000});
    }
})();
