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
        const street = pinForm.elements.namedItem('street'), barangay = pinForm.elements.namedItem('barangay_id'), landmark = pinForm.elements.namedItem('landmark');
        const status = $('[data-pin-status]', pinForm), device = $('[data-pin-device]', pinForm), quality = $('[data-pin-quality]', pinForm);
        const overlay = $('[data-pin-overlay]', pinForm), overlayLabel = $('[data-pin-overlay-label]', pinForm), deviceLabel = $('[data-pin-device-label]', pinForm);
        const b = {south: 10.584854, north: 10.746484, west: 121.961241, east: 122.07373};
        const inside = (y, x) => Number.isFinite(y) && Number.isFinite(x) && y >= b.south && y <= b.north && x >= b.west && x <= b.east;
        // note: an accuracy warning that must survive the barangay/street messages that arrive afterwards.
        let note = '';
        const say = text => { status.textContent = text + (note ? ' ' + note : ''); };
        let map = null, marker = null, ring = null, boundaries = null, lookup = 0;
        const H = window.HydroLocate;
        const official = () => [...barangay.options].filter(o => o.value).map(o => ({name: o.textContent.trim(), value: o.value, psgc: o.dataset.psgc || ''}));
        const findBarangay = async (y, x) => {
            boundaries ??= fetch(pinForm.dataset.boundaries, {credentials: 'same-origin'}).then(r => r.json()).catch(() => ({features: []}));
            const data = await boundaries;
            return H ? H.outline(y, x, data.features || []) : null;
        };
        // Barangay, as on the ALAB resident map: the bundled outline gives a first answer at once (inside, or the nearest
        // edge within 1.5 km), then an exact OpenStreetMap name for the pin replaces it.
        const fillFromPin = async (y, x) => {
            const mine = ++lookup;
            const found = await findBarangay(y, x);
            if (mine !== lookup) return;
            const option = found ? [...barangay.options].find(o => o.dataset.psgc === found.feature.psgc) : null;
            if (option) {
                barangay.value = option.value;
                say(found.exact ? 'Pin placed in Barangay ' + found.feature.name + '. Check the street details, then save.'
                    : 'Pin placed near Barangay ' + found.feature.name + ' (about ' + found.metres + ' m from its mapped edge). Check the barangay and street, then save.');
            } else say('Pin placed, but it is not inside a Hamtic barangay. Move the pin or choose the barangay yourself.');
            try {
                const body = await getJson(pinForm.dataset.reverse + '?' + new URLSearchParams({lat: y.toFixed(7), lng: x.toFixed(7)}));
                if (mine !== lookup || !body.ok) return;
                const norm = H ? H.normalize : t => String(t).toLowerCase().replace(/[^a-z0-9]/g, '');
                const hit = H ? H.match(body.data.areas || [], official()) : null;
                const osm = hit && (hit.exact || !option) ? [...barangay.options].find(o => o.value === hit.value) : null;
                if (osm) { barangay.value = osm.value; say('Pin placed in Barangay ' + osm.textContent.trim() + '. Check the street details, then save.'); }
                // Nearest named place (store, school, chapel) fills an empty landmark, as on the ALAB resident map.
                if (landmark && body.data.landmark && (landmark.value.trim() === '' || landmark.dataset.auto === 'yes')) { landmark.value = 'Near ' + body.data.landmark; landmark.dataset.auto = 'yes'; }
                if (street.value.trim() !== '' && street.dataset.auto !== 'yes') return;
                const text = [body.data.street, body.data.place].filter((v, i, all) => v && all.indexOf(v) === i && (!osm || norm(v) !== norm(osm.textContent))).join(', ');
                if (text) { street.value = text; street.dataset.auto = 'yes'; }
            } catch { /* the bundled outline result and typed street stay */ }
        };
        street.addEventListener('input', () => { street.dataset.auto = 'no'; });
        landmark?.addEventListener('input', () => { landmark.dataset.auto = 'no'; });
        // Zoom follows GPS accuracy: a tight fix shows the street, a rough one shows the whole area.
        const zoomFor = accuracy => accuracy === null ? 17 : accuracy <= 50 ? 18 : accuracy <= 150 ? 16 : accuracy <= 1000 ? 14 : 12;
        const show = (y, x, accuracy = null) => {
            if (!map) return;
            if (marker) marker.setLatLng([y, x]);
            else { marker = window.L.marker([y, x], {draggable: true, title: 'Address pin'}).addTo(map); marker.on('dragend', () => { const p = marker.getLatLng(); choose(p.lat, p.lng, 'map'); }); }
            if (ring) { ring.remove(); ring = null; }
            if (accuracy) ring = window.L.circle([y, x], {radius: Math.max(15, accuracy), color: '#0ea5e9', weight: 1.5, fillOpacity: 0.12, interactive: false}).addTo(map);
            map.setView([y, x], accuracy === null ? Math.max(map.getZoom(), 17) : zoomFor(accuracy), {animate: true});
        };
        const tones = {precise: ['Precise', 'bg-emerald-50 text-emerald-800 ring-emerald-100'], approximate: ['Approximate', 'bg-amber-50 text-amber-800 ring-amber-100'],
            low: ['Low accuracy', 'bg-rose-50 text-rose-800 ring-rose-100'], outside: ['Outside Hamtic', 'bg-rose-50 text-rose-800 ring-rose-100'], searching: ['Locating…', 'bg-sky-50 text-sky-800 ring-sky-100']};
        const badge = kind => {
            if (!quality) return;
            quality.hidden = !kind;
            if (!kind) return;
            quality.className = 'inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-md px-2.5 py-1 text-xs font-semibold ring-1 ring-inset ' + tones[kind][1];
            quality.textContent = tones[kind][0];
        };
        const grade = accuracy => accuracy <= 50 ? 'precise' : accuracy <= 150 ? 'approximate' : 'low';
        const choose = (y, x, how, accuracy = null) => {
            if (how !== 'device') { note = ''; badge(null); }
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
        // Live GPS detection, as on the ALAB resident map: watch fresh high-accuracy fixes for up to 15 s, keep the best one
        // (inside Hamtic first, then the smallest accuracy circle), move the pin and circle with every better fix, and stop
        // early at 20 m. Laptops often guess from Wi-Fi, so a rough fix says so and asks for a drag.
        const detect = () => {
            if (!navigator.geolocation || device.disabled) return;
            device.disabled = true;
            if (deviceLabel) deviceLabel.textContent = 'Detecting location…';
            if (overlay) { overlay.hidden = false; overlayLabel.textContent = 'Locating you…'; }
            badge('searching');
            say('Finding your location… stay still, outdoors or near a window if you can.');
            const finish = (best, failed) => {
                device.disabled = false;
                if (deviceLabel) deviceLabel.textContent = 'Detect my location again';
                if (overlay) overlay.hidden = true;
                if (!best) {
                    badge(null);
                    say(failed && failed.code === 1 ? 'Location permission is off. Allow location for this site, or tap the map.' : 'No GPS reading arrived. Turn on precise location, move near a window, and try again, or tap the map.');
                    return;
                }
                const {latitude: y, longitude: x, accuracy} = best.coords, metres = Math.round(accuracy);
                note = metres > 50 ? 'Location is approximate (±' + metres + ' m): drag the pin to your exact gate.' : '';
                if (!choose(y, x, 'device', accuracy)) {
                    note = '';
                    badge('outside');
                    say('Your device reports a point outside Hamtic (±' + metres + ' m). Computers often guess location from Wi-Fi; use your phone or tap the map.');
                    return;
                }
                badge(grade(accuracy));
            };
            H.watchBest({inside, onDone: finish, onFix: p => {
                const metres = Math.round(p.coords.accuracy);
                show(p.coords.latitude, p.coords.longitude, p.coords.accuracy);
                if (overlay) overlayLabel.textContent = 'Improving accuracy… ±' + metres + ' m';
                say('Finding your location… ±' + metres + ' m');
            }});
        };
        if (!navigator.geolocation || !H) device.hidden = true;
        else {
            device.addEventListener('click', detect);
            // A first address with no pin starts detecting at once (the browser asks for permission once).
            if (lat.value === '' && (pinForm.dataset.autoLocate === '1' || location.hash === '#address-form')) {
                const ask = navigator.permissions?.query ? navigator.permissions.query({name: 'geolocation'}).then(s => s.state !== 'denied').catch(() => true) : Promise.resolve(true);
                ask.then(ok => { if (ok) detect(); });
            }
        }
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
        if (event.defaultPrevented) return; // a validation listener (for example, passwords must match) stopped this send
        form.dataset.sent = 'yes';
        // A listener after this one may still cancel; lock the buttons only if the browser really submits.
        setTimeout(() => {
            if (event.defaultPrevented) { form.dataset.sent = ''; return; }
            $$('button[type="submit"]', form).forEach(b => { b.disabled = true; });
        }, 0);
    });

    // Poll a small JSON endpoint; reload when the record's version changes. Pauses while hidden.
    // Background refresh: fetch this page and swap the main content in place (no reload, no flash, scroll kept).
    // [data-keep][id] regions (the rider's map) keep their live element; filter and search choices are carried over.
    // Waits while someone is typing, has a dialog open or has unsaved changes in a form.
    const main = () => $('#main-content');
    document.addEventListener('input', event => { const form = event.target.closest?.('form'); if (form && main()?.contains(form)) form.dataset.dirty = 'yes'; });
    const busy = () => !!$('dialog[open]') || !!window.Swal?.isVisible?.() || !!main()?.querySelector('form[data-dirty="yes"]')
        || (document.activeElement?.matches('input,textarea,select') && main()?.contains(document.activeElement));
    const softRefresh = async () => {
        const current = main();
        if (!current) { location.reload(); return; }
        const response = await fetch(location.href, {credentials: 'same-origin', headers: {Accept: 'text/html'}});
        if (!response.ok || response.redirected) { location.reload(); return; } // session ended or page moved: show it properly
        const fresh = new DOMParser().parseFromString(await response.text(), 'text/html').querySelector('#main-content');
        if (!fresh || busy()) return; // the user started typing meanwhile: try again on the next check
        // Widgets wired once at page load (GPS tracking, maps, checkout) appearing or disappearing need a real load.
        const wired = root => ['[data-rider-track]', '[data-route-map]', '[data-pin-picker]', '[data-live-quote]'].map(s => !!root.querySelector(s)).join();
        if (wired(fresh) !== wired(current)) { location.reload(); return; }
        $$('[data-search],[data-filter-target]', current).forEach(input => {
            const twin = input.id ? fresh.querySelector('#' + CSS.escape(input.id)) : null;
            if (twin) twin.value = input.value;
        });
        $$('[data-keep][id]', current).forEach(old => { const twin = fresh.querySelector('#' + CSS.escape(old.id)); if (twin) twin.replaceWith(old); });
        const y = window.scrollY;
        current.replaceChildren(...fresh.childNodes);
        window.scrollTo(0, y);
        document.dispatchEvent(new CustomEvent('hydro:refreshed'));
    };
    if ($('[data-poll-url]')) {
        const seconds = Number($('[data-poll-url]').dataset.pollSeconds || 30);
        let failures = 0, timer = 0, running = false;
        const tick = async () => {
            clearTimeout(timer);
            if (running) return; // one check at a time, so an older answer can never overwrite a newer page
            const el = $('[data-poll-url]'); // re-read: a background refresh replaces it with the new version
            if (!el) return;
            if (document.hidden || !navigator.onLine) return schedule();
            running = true;
            try {
                const body = await getJson(el.dataset.pollUrl);
                failures = 0;
                if (body.ok && String(body.data.version) !== el.dataset.pollVersion && !busy()) await softRefresh();
            } catch { failures++; } finally { running = false; }
            schedule();
        };
        const schedule = () => { clearTimeout(timer); timer = setTimeout(tick, seconds * 1000 * Math.min(4, 1 + failures)); };
        schedule();
        // Coming back to the tab checks at once instead of waiting for the next tick.
        document.addEventListener('visibilitychange', () => { if (!document.hidden) tick(); });
    }

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

    const meters = (a, b) => {
        const r = 6371000, rad = Math.PI / 180, dLat = (b.lat - a.lat) * rad, dLon = (b.lon - a.lon) * rad;
        const h = Math.sin(dLat / 2) ** 2 + Math.cos(a.lat * rad) * Math.cos(b.lat * rad) * Math.sin(dLon / 2) ** 2;
        return 2 * r * Math.asin(Math.sqrt(h));
    };

    // Rider road map: the server picks the destination (next open stop, else the station); redraw after 150 m or 60 s.
    const routeBox = $('[data-route-map]');
    const route = (() => {
        if (!routeBox) return {update() {}, position() {}};
        // Summary and steps are looked up each time (a background refresh replaces them); the map canvas is kept (data-keep).
        const say = text => { const el = $('[data-route-summary]'); if (el) el.textContent = text; };
        const canvas = $('[data-route-canvas]', routeBox);
        const why = {no_route: 'No road route found. Use the address and landmark.', no_pin: 'This stop has no map pin. Use the address and landmark.',
            no_origin: 'Turn on location to see the road route.', not_found: 'No active trip.'};
        let map = null, line = null, dest = null, me = null, meRing = null, from = null, at = 0, seq = 0;
        const draw = d => {
            if (!window.L) { canvas.hidden = true; return; }
            if (!map) {
                map = window.L.map(canvas);
                window.L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {maxZoom: 19, referrerPolicy: 'strict-origin-when-cross-origin',
                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'}).addTo(map);
            }
            if (line) line.remove();
            if (dest) dest.remove();
            line = window.L.polyline(d.path, {color: '#00779a', weight: 5, opacity: 0.85}).addTo(map);
            dest = window.L.marker(d.to, {title: d.label}).addTo(map);
            if (!me) me = window.L.circleMarker(d.from, {radius: 7, color: '#ffffff', weight: 2, fillColor: '#0284c7', fillOpacity: 1}).addTo(map);
            map.fitBounds(line.getBounds(), {padding: [24, 24]});
        };
        // A background refresh can change the next stop: redraw the route from the last known position.
        document.addEventListener('hydro:refreshed', () => { at = 0; route.update(from); });
        return {
            async update(here) {
                const now = Date.now();
                if (at && now - at < 60000 && (!here || (from && meters(from, here) < 150))) return;
                at = now; from = here; const mine = ++seq;
                try {
                    const body = await getJson(routeBox.dataset.routeUrl + (here ? `?lat=${here.lat.toFixed(6)}&lng=${here.lon.toFixed(6)}` : ''));
                    if (mine !== seq) return; // an older answer arrived late
                    if (!body.ok) { say(why[body.error_code] || 'Road route unavailable right now. Use the address and landmark.'); return; }
                    const d = body.data;
                    say(`${(d.distance_m / 1000).toFixed(1)} km · about ${Math.max(1, Math.round(d.duration_s / 60))} min by road${d.from_station ? ' from the station' : ''}${d.warning ? '. ' + d.warning : ''}`);
                    $('[data-route-steps]')?.replaceChildren(...d.steps.map(s => { const li = document.createElement('li'); li.textContent = s.text + (s.distance_m ? ` · ${s.distance_m >= 1000 ? (s.distance_m / 1000).toFixed(1) + ' km' : s.distance_m + ' m'}` : ''); return li; }));
                    draw(d);
                } catch { if (mine === seq) say('Road route unavailable right now. Use the address and landmark.'); }
            },
            // Rider dot plus its GPS accuracy circle, as on the ALAB resident map.
            position(here) {
                if (!me || !window.L) return;
                me.setLatLng([here.lat, here.lon]);
                if (meRing) meRing.setLatLng([here.lat, here.lon]).setRadius(Math.max(15, here.acc));
                else meRing = window.L.circle([here.lat, here.lon], {radius: Math.max(15, here.acc), color: '#0284c7', weight: 1, fillOpacity: 0.1, interactive: false}).addTo(map);
            },
        };
    })();
    if (routeBox && !('geolocation' in navigator)) route.update(null);

    // Rider: share position during an active trip only (about every 30 s or 50 m), and fill arrival forms.
    const tracker = $('[data-rider-track]');
    if (tracker && 'geolocation' in navigator) {
        let last = null, lastSent = 0;
        // Looked up each time: a background refresh replaces these elements.
        const status = {set textContent(text) { const el = $('[data-location-status]'); if (el) el.textContent = text; }};
        // Distance travelled on this trip (plan M11), summed from fixes of 100 m accuracy or better; kept on this phone per trip.
        const store = 'hamtic-trip-km:' + tracker.dataset.riderTrack;
        let trail = {km: 0, at: null};
        try { trail = {...trail, ...JSON.parse(localStorage.getItem(store) || '{}')}; } catch { /* storage blocked: count from now */ }
        const showTravelled = () => { const el = $('[data-travelled]'); if (el) el.textContent = trail.km.toFixed(1) + ' km travelled'; };
        document.addEventListener('hydro:refreshed', showTravelled);
        const addTravelled = here => {
            if (here.acc > 100) return;
            const step = trail.at ? meters(trail.at, here) : 0;
            if (trail.at && step < 25) return; // GPS jitter while standing still
            if (step < 5000) trail.km += step / 1000; // a jump of 5 km+ is a bad fix, not driving
            trail.at = {lat: here.lat, lon: here.lon};
            try { localStorage.setItem(store, JSON.stringify(trail)); } catch { /* still shown for this page */ }
            showTravelled();
        };
        showTravelled();
        // The last fix also fills forms that arrive with a background refresh (arrival, re-plan), so they work at once.
        let lastFix = null;
        const fillGeo = here => {
            $$('[data-geo="latitude"]').forEach(i => { i.value = here.lat.toFixed(6); });
            $$('[data-geo="longitude"]').forEach(i => { i.value = here.lon.toFixed(6); });
            $$('[data-geo="accuracy"]').forEach(i => { i.value = String(here.acc); });
            $$('[data-geo-fallback]').forEach(i => { i.required = false; });
        };
        document.addEventListener('hydro:refreshed', () => { if (lastFix) fillGeo(lastFix); });
        navigator.geolocation.watchPosition(async pos => {
            const here = {lat: pos.coords.latitude, lon: pos.coords.longitude, acc: Math.round(pos.coords.accuracy)};
            lastFix = here;
            fillGeo(here);
            $$('[data-distance-to]').forEach(el => {
                const [lat, lon] = el.dataset.distanceTo.split(',').map(Number);
                el.textContent = (meters(here, {lat, lon}) / 1000).toFixed(1) + ' km from you';
            });
            if (status) status.textContent = 'Location on · ±' + here.acc + ' m';
            route.position(here); route.update(here); addTravelled(here);
            const now = Date.now();
            if (last && now - lastSent < 30000 && meters(last, here) < 50) return;
            last = here; lastSent = now;
            const data = new FormData();
            data.set('_token', tracker.dataset.token); data.set('trip_id', tracker.dataset.riderTrack);
            data.set('latitude', here.lat.toFixed(6)); data.set('longitude', here.lon.toFixed(6)); data.set('accuracy', String(here.acc));
            try { await fetch('/rider/trips/location', {method: 'POST', body: data, credentials: 'same-origin', headers: {Accept: 'application/json'}}); } catch { /* next fix retries */ }
        }, () => { if (status) status.textContent = 'Location off · arrivals need a reason'; route.update(null); }, {enableHighAccuracy: true, maximumAge: 15000, timeout: 20000});
    }
})();
