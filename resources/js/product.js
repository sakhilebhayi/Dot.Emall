/*
 * Product page, wishlist and reviews behaviours (R-43 W4). Everything here is an enhancement: each control is a plain form or link that
 * works with scripting off, and this file only saves a page load. Driven by data attributes, through delegated events.
 *
 *   [data-pdp-buy]        the buy box; re-fetched on its own (header X-PDP-Fragment: buy) when a choice changes
 *   [data-pdp-choose]     the GET form holding the option pickers
 *   [data-pdp-add]        the add-to-cart form; refuses to send without a chosen variant and says what to choose
 *   [data-wish-toggle]    the wishlist heart (x-wish-toggle)
 *   [data-vote]           "Helpful" on a review
 *   [data-lightbox]       a review photo link that opens in the shared dialog
 *   [data-copy]           copies the text of the input named by its value
 */
const $ = (sel, ctx = document) => ctx.querySelector(sel);
const $$ = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel));
const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

$$('[data-js-only]').forEach((el) => { el.hidden = false; });

/* The buy box: choose a size or colour and the price, stock, delivery and add button follow, from the server. */
let inflight = 0;
async function refreshBuy(form, focus) {
    const box = $('[data-pdp-buy]');
    if (!box || !form) return;
    const params = new URLSearchParams(new FormData(form));
    const url = form.getAttribute('action').split('#')[0] + (params.toString() ? '?' + params.toString() : '');
    const ticket = ++inflight;
    box.setAttribute('aria-busy', 'true');
    try {
        const res = await fetch(url, { headers: { 'X-PDP-Fragment': 'buy', Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
        if (!res.ok) throw new Error('fragment');
        const tpl = document.createElement('template');
        tpl.innerHTML = (await res.text()).trim();
        const next = tpl.content.querySelector('[data-pdp-buy]');
        if (!next) throw new Error('fragment');
        if (ticket !== inflight) return; // a newer choice is already on its way
        box.replaceWith(next);
        history.replaceState(null, '', url);
        if (focus) {
            const el = $(`input[name="${CSS.escape(focus.name)}"][value="${CSS.escape(focus.value)}"]`, next);
            if (el) el.focus({ preventScroll: true });
        }
        showImageFor(next);
        syncBundle(next);
        announce(next);
        $$('.ds-stepper', next).forEach((w) => { const i = $('input', w); if (i) i.dispatchEvent(new Event('change', { bubbles: true })); });
    } catch (err) {
        window.location.href = url + '#buy'; // no script help needed: the same URL renders the same answer
    } finally {
        box.removeAttribute('aria-busy');
    }
}
document.addEventListener('change', (e) => {
    const form = e.target.closest && e.target.closest('[data-pdp-choose]');
    if (!form || !e.target.matches('input[type="radio"]')) return;
    refreshBuy(form, { name: e.target.name, value: e.target.value });
});
document.addEventListener('submit', (e) => {
    const form = e.target.closest && e.target.closest('[data-pdp-choose]');
    if (form) { e.preventDefault(); refreshBuy(form); }
});

function showImageFor(box) {
    const idx = box.dataset.imageIndex;
    if (idx === undefined) return;
    const thumb = $$('#pdp-gallery .ds-gallery__thumb')[parseInt(idx, 10)];
    if (thumb) thumb.click();
}
function syncBundle(box) {
    const field = $('[data-bundle-variant]');
    if (field) field.value = box.dataset.variantId || '';
}
function announce(box) {
    let live = $('#pdp-live');
    if (!live) {
        live = document.createElement('p');
        live.id = 'pdp-live'; live.className = 'ds-visually-hidden'; live.setAttribute('role', 'status');
        document.body.append(live);
    }
    const price = $('.ds-label__main .ds-visually-hidden', box);
    const stock = $('.pdp-stock .ds-badge', box);
    live.textContent = ['Updated.', price ? 'Price ' + price.textContent.trim() + '.' : '', stock ? stock.textContent.trim() + '.' : ''].join(' ').replace(/\s+/g, ' ');
}

/* Add to cart without a variant chosen: say what to choose instead of posting a form the server must refuse. */
document.addEventListener('submit', (e) => {
    const form = e.target.closest && e.target.closest('[data-pdp-add]');
    if (!form) return;
    const variant = $('input[name="variant_id"]', form);
    if (variant && variant.value === '' && form.dataset.needs !== undefined) {
        e.preventDefault(); e.stopImmediatePropagation();
        const needs = form.dataset.needs || 'an option';
        const box = $('#choose-error');
        if (box) { $('span', box).textContent = 'Choose ' + needs + ' first.'; box.hidden = false; }
        let first = null;
        $$('#options .ds-options').forEach((fs) => {
            const chosen = $('input:checked', fs);
            fs.classList.toggle('has-error', !chosen);
            if (!chosen && !first) first = $('input:not(:disabled)', fs);
        });
        const target = $('#options');
        if (target) target.scrollIntoView({ block: 'center', behavior: reduceMotion ? 'auto' : 'smooth' });
        if (first) first.focus({ preventScroll: true });
    }
}, true);

/* The wishlist heart: saves in place; with no answer it falls back to the plain form post. */
document.addEventListener('submit', async (e) => {
    const form = e.target.closest && e.target.closest('[data-wish-toggle]');
    if (!form) return;
    e.preventDefault();
    const btn = $('[data-wish-button]', form);
    if (btn) btn.disabled = true;
    try {
        const res = await fetch(form.action, { method: 'POST', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: new FormData(form), credentials: 'same-origin' });
        if (res.status === 401 || res.status === 419) { form.submit(); return; }
        const data = await res.json();
        if (!res.ok) { window.emallToast?.(data.message || 'That could not be saved.', 'crit'); return; }
        if (btn) {
            btn.setAttribute('aria-pressed', data.saved ? 'true' : 'false');
            const label = data.saved ? form.dataset.labelOn : form.dataset.labelOff;
            if (btn.hasAttribute('aria-label')) { btn.setAttribute('aria-label', label); btn.title = label; }
            const text = $('[data-wish-text]', btn);
            if (text) text.textContent = data.saved ? form.dataset.textOn : form.dataset.textOff;
        }
        window.emallToast?.(data.message);
    } catch (err) {
        form.submit();
    } finally {
        if (btn) btn.disabled = false;
    }
});

/* "Helpful" on a review. */
document.addEventListener('submit', async (e) => {
    const form = e.target.closest && e.target.closest('[data-vote]');
    if (!form) return;
    e.preventDefault();
    const btn = $('[data-vote-button]', form);
    if (btn) btn.disabled = true;
    try {
        const res = await fetch(form.action, { method: 'POST', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: new FormData(form), credentials: 'same-origin' });
        if (!res.ok) { form.submit(); return; }
        const data = await res.json();
        btn.setAttribute('aria-pressed', data.voted ? 'true' : 'false');
        const count = $('[data-vote-count]', btn);
        if (count) count.textContent = '(' + data.count + ')';
    } catch (err) {
        form.submit();
    } finally {
        if (btn) btn.disabled = false;
    }
});

/* Review photos in one shared dialog, with previous and next inside the same review. */
(() => {
    const dialog = document.getElementById('lightbox');
    if (!dialog) return;
    const img = $('[data-lightbox-img]', dialog);
    const caption = $('[data-lightbox-caption]', dialog);
    let group = []; let at = 0;
    const show = (n) => {
        at = (n + group.length) % group.length;
        img.src = group[at].href; img.alt = group[at].dataset.alt || '';
        caption.textContent = group[at].dataset.alt || '';
        $$('[data-lightbox-nav]', dialog).forEach((b) => { b.hidden = group.length < 2; });
    };
    document.addEventListener('click', (e) => {
        const link = e.target.closest && e.target.closest('a[data-lightbox]');
        if (link) {
            e.preventDefault();
            group = $$(`a[data-lightbox="${CSS.escape(link.dataset.lightbox)}"]`);
            show(group.indexOf(link));
            if (typeof dialog.showModal === 'function') { dialog._opener = link; dialog.showModal(); }
            return;
        }
        const nav = e.target.closest && e.target.closest('[data-lightbox-nav]');
        if (nav && group.length) show(at + parseInt(nav.dataset.lightboxNav, 10));
    });
    dialog.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowRight' && group.length) { show(at + 1); e.preventDefault(); }
        if (e.key === 'ArrowLeft' && group.length) { show(at - 1); e.preventDefault(); }
    });
})();

/* Copy a share link. */
document.addEventListener('click', async (e) => {
    const btn = e.target.closest && e.target.closest('[data-copy]');
    if (!btn) return;
    const input = document.getElementById(btn.dataset.copy);
    if (!input) return;
    try {
        await navigator.clipboard.writeText(input.value);
        window.emallToast?.('Link copied.');
    } catch (err) {
        input.select();
        window.emallToast?.('Press copy on your keyboard to copy the link.');
    }
});

/* A read-only share link selects itself when focused, so it can be copied by hand. */
document.addEventListener('focusin', (e) => { if (e.target.matches && e.target.matches('[data-select-on-focus]')) e.target.select(); });
