(() => {
    'use strict';
    // 5-minute slots for the form's chosen date and mode; other customers' slots stay visible but disabled.
    const slots = (form, mode) => {
        const slotSelect = form.querySelector('[data-slot-select]');
        if (!slotSelect) return;
        const day = form.querySelector('[name="date"]')?.value || '';
        let days = {}, booked = [], left = {};
        try { days = JSON.parse(slotSelect.dataset.slotDays || '{}'); booked = JSON.parse(slotSelect.dataset.booked || '[]'); left = JSON.parse(slotSelect.dataset.refills || '{}'); } catch {}
        const taken = new Set(booked), keep = slotSelect.value;
        const now = new Date(Date.now() + 8 * 3600e3).toISOString().slice(0, 16).replace('T', ' ');
        const mins = s => Number(s.slice(0, 2)) * 60 + Number(s.slice(3, 5));
        const hhmm = m => String(Math.floor(m / 60)).padStart(2, '0') + ':' + String(m % 60).padStart(2, '0');
        const label = m => ((Math.floor(m / 60) + 11) % 12 + 1) + ':' + String(m % 60).padStart(2, '0') + (m < 720 ? ' AM' : ' PM');
        const times = new Set();
        for (const [open, close, orderCut, deliveryCut] of days[day] || []) {
            const cut = mins(mode === 'pickup' ? orderCut : deliveryCut);
            for (let m = Math.ceil(mins(open) / 5) * 5; m < mins(close) && m <= cut; m += 5) if (day + ' ' + hhmm(m) > now) times.add(m);
        }
        slotSelect.replaceChildren(new Option('Choose a time', ''));
        let group = null;
        for (const m of [...times].sort((a, b) => a - b)) {
            if (!group || group.dataset.hour !== String(Math.floor(m / 60))) {
                group = document.createElement('optgroup');
                group.dataset.hour = String(Math.floor(m / 60));
                group.label = ((Math.floor(m / 60) + 11) % 12 + 1) + (m < 720 ? ' AM' : ' PM');
                slotSelect.append(group);
            }
            const isTaken = taken.has(day + 'T' + hhmm(m));
            const option = new Option(label(m) + (isTaken ? ' — taken' : ''), hhmm(m), false, hhmm(m) === keep && !isTaken);
            option.disabled = isTaken;
            group.append(option);
        }
        const refills = form.querySelector('[data-refills-left]');
        if (refills) refills.textContent = day in left ? ` ${left[day]} refill${left[day] === 1 ? '' : 's'} left on this date.` : '';
    };
    // Reschedule form on tracking: same slot list for the order's own mode, rebuilt when the date changes.
    const slotForms = () => document.querySelectorAll('form[data-slot-form]:not([data-slot-ready])').forEach(form => {
        const mode = form.dataset.slotForm === 'pickup' ? 'pickup' : 'delivery';
        form.dataset.slotReady = '';
        slots(form, mode);
        form.addEventListener('change', event => { if (event.target.name === 'date') slots(form, mode); });
    });
    slotForms();
    document.addEventListener('hydro:refreshed', slotForms); // forms that arrive with a background refresh
    document.querySelectorAll('[data-fulfillment-root]').forEach(form => {
        const base = form.dataset.persist.replace(/-pickup$/, '');
        let mode = form.dataset.initialMode === 'pickup' ? 'pickup' : 'delivery';
        const controls = [...form.elements].filter(e => e.name && e.type !== 'hidden' && e.name !== 'fulfillment');
        const defaults = controls.map(e => ({value:e.tagName==='SELECT' ? ([...e.options].find(o=>o.defaultSelected)||e.options[0]).value : e.defaultValue,checked:e.defaultChecked}));
        const key = value => base + (value === 'pickup' ? '-pickup' : '');
        const restore = value => {
            let saved = null;
            try { saved = JSON.parse(localStorage.getItem('hamtic-ui:form:' + key(value))); } catch {}
            controls.forEach((e,i) => {
                e.value = defaults[i].value; e.checked = defaults[i].checked;
                if (!saved || typeof saved !== 'object' || !(e.name in saved)) return;
                if (e.type === 'radio' || e.type === 'checkbox') e.checked = Array.isArray(saved[e.name]) ? saved[e.name].includes(e.value) : saved[e.name] === e.value;
                else if (typeof saved[e.name] === 'string') e.value = saved[e.name];
            });
        };
        const copy = (selector,text) => { const e=form.querySelector(selector); if(e)e.textContent=text; };
        const buildSlots = () => slots(form, mode);
        const update = () => {
            const pickup = mode === 'pickup';
            form.dataset.persist=key(mode);
            form.querySelectorAll('[name="fulfillment"]').forEach(e => {e.checked=e.value===mode;});
            const address=form.querySelector('[data-delivery-address]'); address.hidden=pickup;
            address.querySelectorAll('input,select,textarea').forEach(e=>{e.disabled=pickup;e.required=!pickup;});
            form.querySelector('[data-pickup-location]').hidden=!pickup;
            copy('[data-option-title]',pickup?'2. Pickup option':'2. Delivery option');
            copy('[data-timing-question]',pickup?'When would you like to collect your order?':'When do you want your order delivered?');
            copy('[data-asap-title]','Wait for your turn');
            copy('[data-schedule-title]',pickup?'Schedule pickup for later':'Schedule delivery for later');
            copy('[data-asap-copy]',pickup?'No need to pick a time. Collect when the station says your order is ready.':'No need to pick a time. The station delivers when your turn in line comes.');
            copy('[data-notes-title]',pickup?'4. Pickup notes (optional)':'4. Delivery notes (optional)');
            const notes=form.querySelector('[name="notes"]');
            form.querySelector('label[for="notes"]').textContent=pickup?'Instructions for station collection':'Instructions for your delivery team';
            notes.placeholder=pickup?'Let the station know about your collection.':'Look for the blue gate. Please call upon arrival.';
            copy('[data-payment-title]',pickup?'Cash on pickup':'Cash on delivery');
            copy('[data-payment-copy]',pickup?'Pay at the station when you collect your water.':'Pay when your order is delivered.');
            copy('[data-review-copy]',pickup?'Review the station location and opening hours before saving.':'Check your address before saving.');
            const scheduled=form.querySelector('[name="delivery"]:checked')?.value==='scheduled';
            form.querySelectorAll('[name="date"],[name="time"]').forEach(e=>{e.disabled=!scheduled;e.required=scheduled;});
            const select=form.querySelector('[name="address"]'),total=form.querySelector('[data-checkout-total]');
            const fee=pickup?0:(JSON.parse(select.dataset.fees||'{}')[select.value]||0);
            const php=v=>'PHP '+v.toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});
            copy('[data-delivery-fee]',pickup?'Free (pickup)':php(fee));
            if(total)total.textContent=php(Number(total.dataset.subtotal)+fee);
            buildSlots();
        };
        // app.js restores delivery drafts before this module; a mode is always explicit.
        if(mode==='pickup')restore(mode);
        update();
        form.addEventListener('change',event=>{
            if(event.target.name==='fulfillment') {mode=event.target.value==='pickup'?'pickup':'delivery';restore(mode);update();}
            if(event.target.name==='delivery'||event.target.name==='address')update();
            if(event.target.name==='date')buildSlots();
        });
    });
    document.querySelectorAll('#pickup-orders [data-search-row]').forEach(row => {
        const status=row.querySelector('[id^="pickup-status-"]');
        const update=()=>{
            const value=status.textContent.trim(); row.dataset.filterValue=value;
            row.querySelector('[data-status="Ready for pickup"]').disabled=value==='Ready for pickup'||value==='Picked up';
            row.querySelector('[data-status="Picked up"]').disabled=value!=='Ready for pickup';
            document.querySelector('[data-filter-target="pickup-orders"]')?.dispatchEvent(new Event('change',{bubbles:true}));
        };
        new MutationObserver(update).observe(status,{childList:true,subtree:true,characterData:true}); update();
    });
})();
