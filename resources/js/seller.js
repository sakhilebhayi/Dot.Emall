/*
 * The seller desk's small behaviours (R-43 W6). Everything is driven by data attributes and works after a Livewire morph; every page
 * still works with this file switched off (forms post, links go, the fee sentence is printed by the server).
 */

const $$ = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel));
const money = (cents) => 'R' + (Math.abs(cents) / 100).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).replace(/ /g, ' ');

/* Fee preview: <form data-fee-percent="5"> with a price field [data-fee-price] and an output [data-fee-out]. The same arithmetic as the ledger:
   the fee is the percentage of the price, rounded to whole cents. */
function updateFee(form) {
    const input = form.querySelector('[data-fee-price]'); const out = form.querySelector('[data-fee-out]');
    if (!input || !out) return;
    const percent = parseFloat(form.dataset.feePercent || '0');
    const cents = Math.round(parseFloat((input.value || '0').replace(',', '.')) * 100);
    if (!Number.isFinite(cents) || cents <= 0) { out.textContent = out.dataset.empty || ''; return; }
    const fee = Math.round(cents * percent / 100); const receive = cents - fee;
    const pct = String(parseFloat(percent.toFixed(2)));
    out.textContent = percent <= 0
        ? 'Emall takes no fee on this sale at the moment: you receive ' + money(receive) + '.'
        : 'You receive ' + money(receive) + " after Emall's fee of " + money(fee) + ' (' + pct + '% of the price).';
}
document.addEventListener('input', (e) => { const f = e.target.closest && e.target.closest('[data-fee-percent]'); if (f && e.target.matches('[data-fee-price]')) updateFee(f); });
$$('[data-fee-percent]').forEach(updateFee);

/* Select all (bulk actions): a checkbox with data-select-all="name" ticks every checkbox called name inside the same form. */
document.addEventListener('change', (e) => {
    const all = e.target.closest && e.target.closest('[data-select-all]');
    if (all) { $$('input[type="checkbox"][name="' + all.dataset.selectAll + '"]', all.form || document).forEach((c) => { c.checked = all.checked; }); updateBulk(all.form); return; }
    if (e.target.matches('input[type="checkbox"][name="ids[]"]')) updateBulk(e.target.form);
});
function updateBulk(form) {
    if (!form) return;
    const n = $$('input[name="ids[]"]:checked', form).length; const out = form.querySelector('[data-bulk-count]');
    if (out) out.textContent = n === 0 ? 'Tick listings to change them together.' : n + (n === 1 ? ' listing ticked.' : ' listings ticked.');
    $$('[data-bulk-action]', form).forEach((b) => { b.disabled = n === 0; });
}
$$('form').forEach((f) => { if (f.querySelector('[data-bulk-count]')) updateBulk(f); });

/* Print (the packing slip). */
document.addEventListener('click', (e) => { if (e.target.closest('[data-print]')) window.print(); });

/* Photo drop zone: <div data-drop> around an <input type="file" multiple>. Drag files on, or choose them; each is checked (type, size) and listed
   before anything is sent. A form with data-upload sends the files with a progress bar and shows the server's messages in place. */
const MAX_BYTES = 4 * 1024 * 1024; const TYPES = ['image/jpeg', 'image/png', 'image/webp'];
function initDrop(zone) {
    if (zone.dataset.ready) return; zone.dataset.ready = '1';
    const input = zone.querySelector('input[type="file"]'); const list = zone.querySelector('[data-drop-files]'); const live = zone.querySelector('[data-drop-live]');
    const max = parseInt(zone.dataset.max || '6', 10);
    const render = () => {
        if (!list) return; list.replaceChildren();
        Array.from(input.files).forEach((f) => {
            const li = document.createElement('li'); const bad = !TYPES.includes(f.type) ? 'Not a JPEG, PNG or WebP picture.' : f.size > MAX_BYTES ? 'Larger than 4 MB.' : '';
            li.textContent = f.name + ' (' + Math.max(1, Math.round(f.size / 1024)) + ' KB)' + (bad ? ': ' + bad : '');
            if (bad) li.style.color = 'var(--crit)'; list.append(li);
        });
        if (live) live.textContent = input.files.length + (input.files.length === 1 ? ' picture chosen.' : ' pictures chosen.');
        if (input.files.length > max && live) live.textContent += ' Only ' + max + ' can be added at once.';
    };
    input.addEventListener('change', render);
    ['dragenter', 'dragover'].forEach((t) => zone.addEventListener(t, (e) => { e.preventDefault(); zone.classList.add('is-over'); }));
    ['dragleave', 'drop'].forEach((t) => zone.addEventListener(t, (e) => { e.preventDefault(); zone.classList.remove('is-over'); }));
    zone.addEventListener('drop', (e) => { if (e.dataTransfer && e.dataTransfer.files.length) { input.files = e.dataTransfer.files; render(); } });
}
$$('[data-drop]').forEach(initDrop);

document.addEventListener('submit', (e) => {
    const form = e.target; if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-upload')) return;
    const input = form.querySelector('input[type="file"]'); if (!input || !input.files.length || !window.XMLHttpRequest) return;
    e.preventDefault();
    const bar = form.querySelector('[data-progress]'); const msg = form.querySelector('[data-upload-message]'); const fill = bar && bar.firstElementChild;
    const xhr = new XMLHttpRequest(); xhr.open('POST', form.action); xhr.setRequestHeader('Accept', 'application/json');
    xhr.upload.addEventListener('progress', (ev) => { if (ev.lengthComputable && bar) { bar.hidden = false; bar.style.setProperty('--p', Math.round(ev.loaded / ev.total * 100) + '%'); if (fill) fill.setAttribute('aria-hidden', 'true'); } });
    xhr.addEventListener('load', () => {
        let body = {}; try { body = JSON.parse(xhr.responseText); } catch (err) { /* not JSON */ }
        if (xhr.status >= 200 && xhr.status < 300 && body.redirect) {
            // The same address with only a new #fragment would just scroll, and the page would still show the old photos: reload it instead.
            const target = new URL(body.redirect, window.location.href);
            if (target.pathname + target.search === window.location.pathname + window.location.search) { window.location.hash = target.hash; window.location.reload(); } else { window.location.assign(target); }
            return;
        }
        if (bar) bar.hidden = true;
        const errors = body.errors ? Object.values(body.errors).flat() : [body.message || 'The upload did not work. Try again.'];
        if (msg) { msg.textContent = errors.join(' '); msg.hidden = false; }
        form.querySelectorAll('[data-ds-lock]').forEach((b) => { b.disabled = false; });
    });
    xhr.addEventListener('error', () => { if (bar) bar.hidden = true; if (msg) { msg.textContent = 'The connection dropped before the pictures arrived. Try again.'; msg.hidden = false; } });
    if (msg) msg.hidden = true;
    xhr.send(new FormData(form));
});


/* Unsaved changes (D-195): a form with [data-dirty-form] gets [data-dirty] on the first edit, which shows its [data-dirty-status] and lets its save row float; a
   save (submit) clears it, and leaving the page with edits that were not saved asks first. Without script the form is just a form. */
(function dirtyForms() {
    const dirty = new Set();
    const mark = (form, on) => {
        form.toggleAttribute('data-dirty', on);
        form.querySelectorAll('[data-dirty-status]').forEach((el) => { el.hidden = !on; });
        if (on) dirty.add(form); else dirty.delete(form);
    };
    const edited = (e) => { const f = e.target.closest && e.target.closest('form[data-dirty-form]'); if (f && e.target.type !== 'hidden') mark(f, true); };
    document.addEventListener('input', edited);
    document.addEventListener('change', edited);
    document.addEventListener('submit', (e) => { const f = e.target; if (f instanceof HTMLFormElement && dirty.has(f)) { dirty.delete(f); } });
    window.addEventListener('beforeunload', (e) => { if (dirty.size) { e.preventDefault(); e.returnValue = ''; } });
})();
