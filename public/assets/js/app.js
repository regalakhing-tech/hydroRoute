'use strict';
(() => {
    const $ = (selector, root = document) => root.querySelector(selector);
    const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
    const identity = new URLSearchParams(location.search);
    identity.sort();
    const pageScope = location.pathname + (identity.size ? '?' + identity.toString() : '');
    let toastTimer;
    // SweetAlert2 (vendored) when present; plain fallbacks keep tests and no-JS-vendor loads working.
    const swal = options => window.Swal ? window.Swal.fire({buttonsStyling: false, reverseButtons: true, customClass: {confirmButton: 'btn btn-primary min-w-28', cancelButton: 'btn btn-ghost min-w-28'}, ...options}) : Promise.resolve({isConfirmed: true});
    const toast = (message, icon = 'info') => {
        const target = $('#toast');
        if (!target) return;
        target.textContent = message;
        if (window.Swal) { window.Swal.fire({toast: true, position: 'top-end', icon, title: message, showConfirmButton: false, timer: 4000, timerProgressBar: true}); return; }
        target.classList.remove('hidden');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => target.classList.add('hidden'), 6500);
    };
    // Server feedback after a redirect (login, sign-up, logout) arrives as a one-time flash toast.
    const flash = $('[data-flash]');
    if (flash) toast(flash.dataset.flashMessage, flash.dataset.flash);
    if (identity.has('logged_out')) {
        swal({icon: 'success', title: 'You’ve been logged out', text: 'Log in again any time to continue.', timer: 2800, timerProgressBar: true, showConfirmButton: false});
        history.replaceState(null, '', location.pathname);
    }
    if ('IntersectionObserver' in window && $('[data-reveal]')) {
        document.documentElement.classList.add('reveal-ready');
        const observer = new IntersectionObserver(entries => entries.forEach(entry => { if (entry.isIntersecting) { entry.target.classList.add('is-visible'); observer.unobserve(entry.target); } }), {threshold: .12});
        $$('[data-reveal]').forEach(element => observer.observe(element));
    }
    const read = key => { try { return JSON.parse(localStorage.getItem('hamtic-ui:' + key)); } catch { return null; } };
    const write = (key, value) => { try { localStorage.setItem('hamtic-ui:' + key, JSON.stringify(value)); return true; } catch { return false; } };
    const feedback = (form, message) => {
        let box = $('[data-form-feedback]', form);
        if (!box) { box = document.createElement('p'); box.dataset.formFeedback = ''; box.className = 'form-feedback'; box.setAttribute('role', 'status'); form.append(box); }
        box.textContent = message;
        box.scrollIntoView({block: 'nearest', behavior: 'smooth'});
    };
    const sensitive = name => /password|secret|token/i.test(name);
    const restoreForm = form => {
        const saved = read('form:' + form.dataset.persist);
        if (!saved || typeof saved !== 'object') return;
        for (const input of form.elements) {
            if (!input.name || sensitive(input.name) || input.type === 'file' || input.type === 'hidden' || !(input.name in saved)) continue;
            const value = saved[input.name];
            if (input.type === 'checkbox' || input.type === 'radio') input.checked = Array.isArray(value) ? value.includes(input.value) : value === input.value;
            else input.value = String(value);
        }
    };
    $$('form[data-persist]').forEach(restoreForm);
    const money = value => 'PHP ' + value.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    const updateBuilder = builder => {
        let total = 0;
        $$('input[data-unit-price]', builder).forEach(input => {
            const quantity = Number(input.value);
            if (Number.isFinite(quantity)) total += quantity * Number(input.dataset.unitPrice);
        });
        $$('[data-order-total]', builder).forEach(output => { output.textContent = money(total); });
        return total;
    };
    $$('[data-order-builder]').forEach(updateBuilder);
    const updateChange = () => {
        const input = $('#cash-received'), output = $('#cash-change');
        if (!input || !output) return;
        const amount = Number(input.dataset.amountDue), received = Number(input.value);
        const valid = input.value !== '' && Number.isFinite(received) && received >= amount;
        output.textContent = valid ? (received - amount).toFixed(2) : 'Insufficient cash';
        output.classList.toggle('text-rose-700', !valid);
        const delivery = $('[data-status="Delivered"]');
        if (delivery) delivery.disabled = !valid;
    };
    updateChange();
    const filter = id => {
        const target = document.getElementById(id);
        if (!target) return;
        const queries = $$('[data-search]').filter(input => input.dataset.search === id).map(input => input.value.trim().toLowerCase()).filter(Boolean);
        const selections = $$('[data-filter-target]').filter(input => input.dataset.filterTarget === id).map(input => ({value: input.value.toLowerCase(), key: input.dataset.filterKey})).filter(filter => filter.value && filter.value !== 'all');
        let visible = 0;
        $$('[data-search-row]', target).forEach(row => {
            const text = row.textContent.toLowerCase(), values = (row.dataset.filterValue || text).toLowerCase();
            const matches = queries.every(query => text.includes(query)) && selections.every(filter => filter.key ? (row.dataset[filter.key] || '').toLowerCase() === filter.value : (row.hasAttribute('data-filter-value') ? values === filter.value : text.includes(filter.value)));
            row.hidden = !matches;
            row.dataset.filtered = String(!matches);
            if (matches) visible++;
        });
        let wrapper = target;
        while (wrapper && !$('[data-empty]', wrapper)) wrapper = wrapper.parentElement;
        const empty = wrapper ? $('[data-empty]', wrapper) : null;
        if (empty) { empty.hidden = visible !== 0; empty.classList.toggle('hidden', visible !== 0); }
    };
    // Label each cell with its column header so phones can show rows as stacked cards.
    $$('table.data-table').forEach(table => {
        const heads = $$('thead th', table).map(th => th.textContent.trim());
        $$('tbody tr', table).forEach(row => [...row.cells].forEach((cell, i) => { if (!cell.dataset.label && heads[i]) cell.dataset.label = heads[i]; }));
        table.dataset.cards = '';
    });
    $$('[data-search],[data-filter-target]').forEach(input => filter(input.dataset.search || input.dataset.filterTarget));
    document.addEventListener('input', event => {
        const input = event.target;
        if (input.matches('[name="password"],[name="confirm_password"]')) $$('[name="password"],[name="confirm_password"]', input.form).forEach(field => field.setCustomValidity(''));
        if (input.matches('[name="starts"],[name="ends"]')) $$('[name="starts"],[name="ends"]', input.form).forEach(field => field.setCustomValidity(''));
        if (input.matches('[data-search]')) filter(input.dataset.search);
        const builder = input.closest('[data-order-builder]');
        if (builder) updateBuilder(builder);
        if (input.id === 'cash-received') updateChange();
    });
    document.addEventListener('change', event => {
        const input = event.target;
        if (input.matches('[data-filter-target]')) filter(input.dataset.filterTarget);
        if (input.matches('[name="delivery"]')) {
            const form = input.closest('form'), scheduled = $('[name="delivery"]:checked', form)?.value === 'scheduled';
            $$('[name="date"],[name="time"]', form).forEach(field => { field.disabled = !scheduled; field.required = scheduled; });
        }
    });
    $$('[name="delivery"]:checked').forEach(input => input.dispatchEvent(new Event('change', {bubbles:true})));
    let sidebarReturn;
    const closeSidebar = () => {
        const sidebar = $('[data-sidebar]');
        if (sidebar) { sidebar.dataset.open = 'false'; sidebar.inert = window.innerWidth < 1024; }
        $('[data-sidebar-overlay]')?.classList.add('hidden');
        $('[data-sidebar-toggle]')?.setAttribute('aria-expanded','false');
        document.body.style.overflow = '';
        sidebarReturn?.focus();
    };
    const sidebar = $('[data-sidebar]');
    if (sidebar) sidebar.inert = window.innerWidth < 1024;
    window.addEventListener('resize', () => { if (sidebar) sidebar.inert = window.innerWidth < 1024 && sidebar.dataset.open !== 'true'; });
    let pendingAction = null;
    const confirmDialog = $('#confirm-action');
    $('#confirm-button')?.addEventListener('click', () => { if (pendingAction) pendingAction(); pendingAction=null; confirmDialog.close(); });
    document.addEventListener('click', event => {
        const button = event.target.closest('button,a');
        if (!button) { if (event.target.matches('[data-sidebar-overlay]')) closeSidebar(); return; }
        if (button.matches('a[data-logout]') && window.Swal) {
            event.preventDefault();
            swal({icon: 'warning', title: 'Log out?', text: 'You will leave your ' + button.dataset.logout + ' workspace on this device.', showCancelButton: true, confirmButtonText: 'Yes, log out', cancelButtonText: 'Stay logged in', focusCancel: true})
                .then(result => { if (result.isConfirmed) location.href = button.href + (button.href.includes('?') ? '&' : '?') + 'logged_out=1'; });
            return;
        }
        if (button.matches('[data-sidebar-toggle]')) {
            sidebarReturn=button;
            if (sidebar.dataset.open==='true') { closeSidebar(); return; }
            sidebar.inert=false; sidebar.dataset.open='true';
            $('[data-sidebar-overlay]')?.classList.remove('hidden');
            button.setAttribute('aria-expanded','true'); document.body.style.overflow='hidden'; $('a',sidebar)?.focus();
        }
        if (button.dataset.togglePassword) {
            const input=document.getElementById(button.dataset.togglePassword), show=input.type==='password';
            input.type=show?'text':'password';
            button.setAttribute('aria-label',show?'Hide password':'Show password'); button.setAttribute('aria-pressed',String(show));
        }
        if (button.dataset.dialogOpen) document.getElementById(button.dataset.dialogOpen)?.showModal();
        if (button.hasAttribute('data-dialog-close')) button.closest('dialog')?.close();
        if (button.dataset.toast) { if(button.tagName==='A') event.preventDefault(); toast(button.dataset.toast); }
        if (button.dataset.counter) {
            const input=document.getElementById(button.dataset.counter);
            if(input){
                const min=input.min===''?0:Number(input.min), max=input.max===''?Infinity:Number(input.max);
                input.value=String(Math.min(max,Math.max(min,Number(input.value||0)+Number(button.dataset.step||1))));
                input.dispatchEvent(new Event('input',{bubbles:true})); input.dispatchEvent(new Event('change',{bubbles:true}));
            }
        }
        if(button.dataset.status && button.dataset.statusTarget){
            const target=document.getElementById(button.dataset.statusTarget);
            if(!target) return;
            const next=button.dataset.status;
            $('#confirm-message').textContent='Set this sample status to “'+next+'”? This changes the preview on this device only.';
            pendingAction=()=>{ target.textContent=next; target.classList.add('font-semibold','text-water'); write('status:'+pageScope+':'+target.id,next); toast('Status changed to '+next+' in this preview.'); };
            confirmDialog.showModal();
        }
        if(button.dataset.export){
            const element=document.getElementById(button.dataset.export), table=element?.matches('table')?element:$('table',element||document.createElement('div'));
            if(!table){ toast('No visible table is available to export.'); return; }
            const csvCell=text=>{ let value=text.trim().replace(/\s+/g,' '); if(/^[=+@-]/.test(value))value="'"+value; return '"'+value.replace(/"/g,'""')+'"'; };
            const csv=$$('tr',table).filter(row=>!row.hidden&&row.dataset.filtered!=='true').map(row=>$$('th,td',row).map(cell=>csvCell(cell.innerText)).join(',')).join('\r\n');
            const url=URL.createObjectURL(new Blob(['\uFEFF'+csv],{type:'text/csv;charset=utf-8'}));
            const link=document.createElement('a'); link.href=url; link.download='hamtic-'+button.dataset.export+'.csv'; link.click(); setTimeout(()=>URL.revokeObjectURL(url),1000); toast($('.preview-bar') ? 'Sample table exported as CSV.' : 'Table exported as CSV.');
        }
    });
    $$('[id]').forEach(element=>{
        const saved=read('status:'+pageScope+':'+element.id);
        if(typeof saved==='string'&&$('[data-status-target="'+CSS.escape(element.id)+'"]')){ element.textContent=saved; element.classList.add('font-semibold','text-water'); }
    });
    document.addEventListener('keydown',event=>{
        if(event.key==='Escape'&&sidebar?.dataset.open==='true')closeSidebar();
        if(event.key==='Tab'&&sidebar?.dataset.open==='true'&&window.innerWidth<1024){
            const focusable=$$('a,button,[tabindex="0"]',sidebar),first=focusable[0],last=focusable.at(-1);
            if(event.shiftKey&&document.activeElement===first){ event.preventDefault(); last?.focus(); }
            else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first?.focus();}
        }
    });
    document.addEventListener('submit',event=>{
        const form=event.target;
        if(form.matches('[data-order-builder]')&&updateBuilder(form)<=0){ event.preventDefault();toast('Choose at least one refill container to continue.');return; }
        const password=$('[name="password"]',form),confirm=$('[name="confirm_password"]',form);
        confirm?.setCustomValidity('');
        if(confirm&&password&&confirm.value!==password.value){ event.preventDefault();confirm.setCustomValidity('Passwords must match.');confirm.reportValidity();confirm.addEventListener('input',()=>confirm.setCustomValidity(''),{once:true});return; }
        if(form.matches('[data-auth-form]')){
            event.preventDefault();
            feedback(form,form.hasAttribute('data-auth-recovery')?'Reset flow preview complete. No email was sent.':form.hasAttribute('data-auth-signup')?'Account form preview complete. No account was created.':'This is a UI preview. Use the preview button below to explore your workspace.');
            if(password)password.value='';if(confirm)confirm.value='';
            const role=form.dataset.loginRole;
            if(role) swal({icon:'success',title:'Welcome back!',text:'Login preview complete. Opening your workspace…',timer:1800,timerProgressBar:true,showConfirmButton:false}).then(()=>{ if(window.Swal) location.href='/'+role; });
            else if(form.hasAttribute('data-auth-signup')) swal({icon:'success',title:'Account ready to preview',text:'No real account was created. Explore the customer workspace next.',confirmButtonText:'Explore preview'}).then(result=>{ if(window.Swal&&result.isConfirmed) location.href='/customer'; });
            else if(form.hasAttribute('data-auth-recovery')) swal({icon:'info',title:'Check your email',text:'Reset flow preview complete. No email was sent.',confirmButtonText:'Back to login'}).then(result=>{ if(window.Swal&&result.isConfirmed) location.href='/login/customer'; });
            return;
        }
        if(!form.matches('[data-local-form]'))return;
        event.preventDefault();if(!form.reportValidity())return;
        const starts=$('[name="starts"]',form),ends=$('[name="ends"]',form);
        if(starts&&ends&&ends.value<=starts.value){ends.setCustomValidity('End must be after the start.');ends.reportValidity();ends.addEventListener('input',()=>ends.setCustomValidity(''),{once:true});return;}
        const values={};
        for(const input of form.elements){
            if(!input.name||input.disabled||sensitive(input.name)||input.type==='file')continue;
            if(input.type==='checkbox'||input.type==='radio'){if(!Array.isArray(values[input.name]))values[input.name]=[];if(input.checked)values[input.name].push(input.value);}
            else values[input.name]=input.value;
        }
        const saved=write('form:'+(form.dataset.persist||location.pathname),values);
        const message=saved?(form.dataset.success||'Changes saved in this browser only.'):'This browser could not save the preview. Your changes remain on this page.';
        feedback(form,message);toast(message,saved?'success':'warning');
    });
})();

