/*
 * Dot.Emall interface behaviours. No framework: every behaviour is driven by data attributes in the ds components, through delegated
 * events, so it keeps working after Livewire morphs the page. Each block says which component it serves (DESIGN.md lists the attributes).
 */

import './seller.js';
import './dashboard.js';

const root = document.documentElement;
const $$ = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel));

/* Password reveal (ds.input with reveal): the button beside the field swaps type="password" and type="text". It keeps the caret, announces its state, and puts the field back on form submit. */
document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-reveal]');
    if (!b) return;
    const input = document.getElementById(b.getAttribute('data-reveal'));
    if (!input) return;
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    b.setAttribute('aria-pressed', show ? 'true' : 'false');
    b.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
});

/* Phone header (R-43 polish #6): once the page has scrolled the search row is gone, so a search icon appears in the first row; it goes back to the top and focuses the field. */
{
    let ticking = false;
    const mark = () => { ticking = false; root.classList.toggle('is-scrolled', window.scrollY > 72); };
    window.addEventListener('scroll', () => { if (!ticking) { ticking = true; requestAnimationFrame(mark); } }, { passive: true });
    mark();
    document.addEventListener('click', (e) => {
        if (!e.target.closest('[data-search-reveal]')) return;
        root.classList.remove('is-scrolled');
        window.scrollTo(0, 0);
        document.querySelector('.ds-header__search input[type="search"]')?.focus();
    });
}

/* Theme: class "light" or "dark" on <html>, remembered in localStorage ("emall-theme"). With nothing stored the system decides. */
const THEME_KEY = 'emall-theme';
function effectiveTheme() {
    if (root.classList.contains('dark')) return 'dark';
    if (root.classList.contains('light')) return 'light';
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}
function setTheme(mode) {
    root.classList.remove('light', 'dark');
    root.classList.add(mode);
    try { localStorage.setItem(THEME_KEY, mode); } catch (e) { /* private mode: the choice lasts for this page only */ }
    $$('[data-theme-toggle]').forEach((b) => b.setAttribute('aria-pressed', mode === 'dark' ? 'true' : 'false'));
}
// With no saved choice the class follows the system when it changes (the head script set it once at load).
window.matchMedia('(prefers-color-scheme: dark)').addEventListener?.('change', (e) => {
    let saved = null;
    try { saved = localStorage.getItem(THEME_KEY); } catch (err) { /* no storage */ }
    if (saved !== 'dark' && saved !== 'light') { root.classList.remove('light', 'dark'); root.classList.add(e.matches ? 'dark' : 'light'); }
});
document.addEventListener('click', (e) => {
    const t = e.target.closest('[data-theme-toggle]');
    if (t) setTheme(effectiveTheme() === 'dark' ? 'light' : 'dark');
});
$$('[data-theme-toggle]').forEach((b) => b.setAttribute('aria-pressed', effectiveTheme() === 'dark' ? 'true' : 'false'));

/* Dialog and drawer (ds.dialog, ds.confirm-dialog, the phone menu): <dialog> + showModal() gives the focus trap, Esc and an inert page. */
document.addEventListener('click', (e) => {
    const open = e.target.closest('[data-dialog-open]');
    if (open) {
        const d = document.getElementById(open.getAttribute('data-dialog-open'));
        if (d && typeof d.showModal === 'function' && !d.open) { d.showModal(); d.dataset.returnTo = ''; d._opener = open; }
        return;
    }
    const close = e.target.closest('[data-dialog-close]');
    if (close) { const d = close.closest('dialog'); if (d) d.close(); return; }
    if (e.target instanceof HTMLDialogElement && e.target.open && !e.target.hasAttribute('data-modal-lock')) {
        const r = e.target.getBoundingClientRect();
        if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) e.target.close();
    }
});
document.addEventListener('close', (e) => { const d = e.target; if (d instanceof HTMLDialogElement && d._opener) d._opener.focus(); }, true);
$$('dialog[data-open-on-load]').forEach((d) => { if (!d.open) d.showModal(); });

/* Dropdown and mega panel (ds.dropdown, the category menu): a button with aria-controls toggles a panel; Esc and an outside click close it. */
function closeDisclosures(except) {
    $$('[data-disclosure][aria-expanded="true"]').forEach((b) => {
        if (b === except) return;
        b.setAttribute('aria-expanded', 'false');
        const p = document.getElementById(b.getAttribute('aria-controls'));
        if (p) p.hidden = true;
    });
}
document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-disclosure]');
    if (!b) { if (!e.target.closest('[data-disclosure-panel]')) closeDisclosures(); return; }
    const p = document.getElementById(b.getAttribute('aria-controls'));
    const open = b.getAttribute('aria-expanded') === 'true';
    closeDisclosures(b);
    b.setAttribute('aria-expanded', open ? 'false' : 'true');
    if (p) { p.hidden = open; if (!open) { const first = p.querySelector('a,button'); if (first && e.detail === 0) first.focus(); } }
});
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        const openBtn = document.querySelector('[data-disclosure][aria-expanded="true"]');
        if (openBtn) { closeDisclosures(); openBtn.focus(); }
    }
    const item = e.target.closest && e.target.closest('[data-disclosure-panel] [role="menuitem"]');
    if (item && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
        const items = $$('[role="menuitem"]', item.closest('[data-disclosure-panel]'));
        const i = items.indexOf(item) + (e.key === 'ArrowDown' ? 1 : -1);
        (items[(i + items.length) % items.length] || item).focus();
        e.preventDefault();
    }
});

/* Tabs (ds.tabs): roving tabindex, arrow keys, panels shown by aria-selected. */
document.addEventListener('click', (e) => {
    const tab = e.target.closest('[role="tab"]');
    if (!tab) return;
    selectTab(tab);
});
document.addEventListener('keydown', (e) => {
    const tab = e.target.closest && e.target.closest('[role="tab"]');
    if (!tab || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(e.key)) return;
    const tabs = $$('[role="tab"]', tab.closest('[role="tablist"]'));
    let i = tabs.indexOf(tab);
    if (e.key === 'ArrowRight') i = (i + 1) % tabs.length;
    if (e.key === 'ArrowLeft') i = (i - 1 + tabs.length) % tabs.length;
    if (e.key === 'Home') i = 0;
    if (e.key === 'End') i = tabs.length - 1;
    selectTab(tabs[i]); tabs[i].focus(); e.preventDefault();
});
function selectTab(tab) {
    const list = tab.closest('[role="tablist"]');
    $$('[role="tab"]', list).forEach((t) => {
        const on = t === tab;
        t.setAttribute('aria-selected', on ? 'true' : 'false');
        t.tabIndex = on ? 0 : -1;
        const p = document.getElementById(t.getAttribute('aria-controls'));
        if (p) p.hidden = !on;
    });
}

/* Toasts (ds.toasts): flash messages dismiss themselves after 6 seconds unless they are errors. */
function dismissToast(t) { t.classList.add('is-leaving'); setTimeout(() => t.remove(), 160); }
$$('.ds-toast[data-autodismiss]').forEach((t) => setTimeout(() => dismissToast(t), 6000));
document.addEventListener('click', (e) => { const c = e.target.closest('[data-toast-close]'); if (c) dismissToast(c.closest('.ds-toast')); });
window.emallToast = (message, tone = 'pos') => {
    const host = document.querySelector('.ds-toasts'); if (!host) return;
    const t = document.createElement('div'); t.className = 'ds-toast' + (tone === 'crit' ? ' ds-toast--crit' : ''); t.setAttribute('role', tone === 'crit' ? 'alert' : 'status');
    const span = document.createElement('span'); span.textContent = message; t.append(span);
    host.append(t); if (tone !== 'crit') setTimeout(() => dismissToast(t), 6000);
};

/* Quantity stepper (ds.quantity-stepper). */
document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-step]');
    if (!b) return;
    const wrap = b.closest('.ds-stepper'); const input = wrap.querySelector('input');
    const min = parseInt(input.min || '1', 10); const max = input.max ? parseInt(input.max, 10) : Infinity;
    let v = parseInt(input.value || String(min), 10); if (Number.isNaN(v)) v = min;
    v = Math.min(max, Math.max(min, v + parseInt(b.getAttribute('data-step'), 10)));
    input.value = String(v); syncStepper(wrap);
    input.dispatchEvent(new Event('input', { bubbles: true })); input.dispatchEvent(new Event('change', { bubbles: true }));
});
document.addEventListener('change', (e) => { const w = e.target.closest && e.target.closest('.ds-stepper'); if (w && e.target.matches('input')) { clampStepper(w); } });
function clampStepper(wrap) {
    const input = wrap.querySelector('input'); const min = parseInt(input.min || '1', 10); const max = input.max ? parseInt(input.max, 10) : Infinity;
    let v = parseInt(input.value, 10); if (Number.isNaN(v)) v = min; input.value = String(Math.min(max, Math.max(min, v))); syncStepper(wrap);
}
function syncStepper(wrap) {
    const input = wrap.querySelector('input'); const v = parseInt(input.value, 10);
    const dec = wrap.querySelector('[data-step="-1"]'); const inc = wrap.querySelector('[data-step="1"]');
    if (dec) dec.disabled = v <= parseInt(input.min || '1', 10);
    if (inc) inc.disabled = input.max ? v >= parseInt(input.max, 10) : false;
}
$$('.ds-stepper').forEach(syncStepper);

/* Auto-submit (ds.sort-select) and double-submit lock (every form with a data-ds-lock button). */
document.addEventListener('change', (e) => { const el = e.target.closest && e.target.closest('[data-autosubmit]'); if (el && el.form) el.form.requestSubmit(); });
document.addEventListener('submit', (e) => {
    const f = e.target; if (!(f instanceof HTMLFormElement) || f.dataset.submitted) return;
    const btn = f.querySelector('[data-ds-lock]'); if (!btn) return;
    f.dataset.submitted = '1'; setTimeout(() => { delete f.dataset.submitted; }, 4000);
});

/* Product gallery (ds.product-gallery): thumbnails, arrow keys, swipe, click or tap to zoom, hover moves the zoomed view. */
function initGallery(g) {
    if (g.dataset.ready) return; g.dataset.ready = '1';
    const slides = $$('.ds-gallery__slide', g); const thumbs = $$('.ds-gallery__thumb', g); const count = g.querySelector('.ds-gallery__count-now');
    const stage = g.querySelector('.ds-gallery__stage'); let i = Math.max(0, slides.findIndex((s) => s.classList.contains('is-active')));
    const show = (n) => {
        i = (n + slides.length) % slides.length;
        slides.forEach((s, k) => s.classList.toggle('is-active', k === i));
        thumbs.forEach((t, k) => { if (k === i) t.setAttribute('aria-current', 'true'); else t.removeAttribute('aria-current'); });
        if (count) count.textContent = String(i + 1);
        g.classList.remove('is-zoomed');
        const img = slides[i].querySelector('img[loading="lazy"]'); if (img) img.loading = 'eager';
    };
    thumbs.forEach((t, k) => t.addEventListener('click', () => show(k)));
    g.querySelector('[data-gallery="prev"]')?.addEventListener('click', () => show(i - 1));
    g.querySelector('[data-gallery="next"]')?.addEventListener('click', () => show(i + 1));
    g.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowLeft') { show(i - 1); e.preventDefault(); } else if (e.key === 'ArrowRight') { show(i + 1); e.preventDefault(); } else if (e.key === 'Escape') g.classList.remove('is-zoomed');
    });
    const zoom = () => g.classList.toggle('is-zoomed');
    g.querySelector('[data-gallery="zoom"]')?.addEventListener('click', (e) => { e.stopPropagation(); zoom(); });
    let sx = null; let moved = false;
    stage.addEventListener('pointerdown', (e) => { sx = e.clientX; moved = false; });
    stage.addEventListener('pointermove', (e) => {
        if (sx !== null && Math.abs(e.clientX - sx) > 8) moved = true;
        if (g.classList.contains('is-zoomed') && e.pointerType === 'mouse') { const r = stage.getBoundingClientRect(); g.style.setProperty('--zx', ((e.clientX - r.left) / r.width * 100) + '%'); g.style.setProperty('--zy', ((e.clientY - r.top) / r.height * 100) + '%'); }
    });
    stage.addEventListener('pointerup', (e) => {
        if (sx === null) return; const dx = e.clientX - sx; sx = null;
        if (!g.classList.contains('is-zoomed') && Math.abs(dx) > 48) { show(dx < 0 ? i + 1 : i - 1); return; }
        if (!moved && e.target.closest('[data-zoomable]') && !e.target.closest('button')) zoom();
    });
    stage.addEventListener('pointercancel', () => { sx = null; });
    show(i);
}
$$('.ds-gallery').forEach(initGallery);

/* Header search suggestions. The pill is the shared Dot UI search (<x-dot.search>, dot-ui/js/dot-search.js opens and closes it); this is the shop's own
   behaviour inside it: the ARIA 1.2 combobox over [data-suggest-list], fed by data-suggest-endpoint (JSON {"suggestions":[{label,url,meta}]}) or by server-rendered
   rows. The query, the endpoint and the results page are untouched. An empty list keeps the panel hidden (CSS :has). */
function initSearch(box) {
    if (box.dataset.ready) return; box.dataset.ready = '1';
    const input = box.querySelector('.dsr-input'); const list = box.querySelector('[data-suggest-list]');
    if (!input || !list) return;
    const endpoint = box.dataset.suggestEndpoint; let timer = null; let active = -1;
    const options = () => $$('[role="option"]', list);
    const reset = () => { active = -1; input.removeAttribute('aria-activedescendant'); options().forEach((el) => { el.classList.toggle('is-active', false); el.setAttribute('aria-selected', 'false'); }); };
    const mark = (n) => { const o = options(); o.forEach((el, k) => { el.classList.toggle('is-active', k === n); el.setAttribute('aria-selected', k === n ? 'true' : 'false'); }); active = n; if (n >= 0) { input.setAttribute('aria-activedescendant', o[n].id); o[n].scrollIntoView({ block: 'nearest' }); } else input.removeAttribute('aria-activedescendant'); };
    const render = (items) => {
        list.replaceChildren(); reset();
        items.forEach((s, k) => {
            const a = document.createElement('a'); a.className = 'dsr-item'; a.setAttribute('role', 'option'); a.id = box.id + '-opt-' + k; a.setAttribute('aria-selected', 'false'); a.href = s.url; a.tabIndex = -1;
            const t = document.createElement('span'); t.className = 'dsr-label'; t.textContent = s.label; a.append(t);
            if (s.meta) { const m = document.createElement('span'); m.className = 'dsr-tag'; m.textContent = s.meta; a.append(m); }
            list.append(a);
        });
    };
    input.addEventListener('input', () => {
        const q = input.value.trim();
        if (!endpoint) return;
        clearTimeout(timer); if (q.length < 2) { render([]); return; }
        timer = setTimeout(async () => { try { const r = await fetch(endpoint + (endpoint.includes('?') ? '&' : '?') + 'q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } }); if (r.ok) { const j = await r.json(); if (input.value.trim() === q) render(j.suggestions || []); } } catch (err) { render([]); } }, 160);
    });
    input.addEventListener('keydown', (e) => {
        const n = options().length;
        if (e.key === 'ArrowDown' && n) { mark((active + 1) % n); e.preventDefault(); }
        else if (e.key === 'ArrowUp' && n) { mark((active - 1 + n) % n); e.preventDefault(); }
        else if (e.key === 'Enter' && active >= 0) { const a = options()[active]; if (a && a.href) { window.location.href = a.href; e.preventDefault(); } }
        else if (e.key === 'Escape') { reset(); }
    });
}
$$('.dsr:has([data-suggest-list])').forEach(initSearch);

/* Livewire morphs add new nodes: set up galleries, searches and steppers that arrived after load. */
document.addEventListener('livewire:navigated', () => { $$('.ds-gallery').forEach(initGallery); $$('.dsr:has([data-suggest-list])').forEach(initSearch); $$('.ds-stepper').forEach(syncStepper); });

/* Alert dismiss (ds.alert with dismissible). */
document.addEventListener('click', (e) => { const c = e.target.closest('[data-alert-close]'); if (c) c.closest('.ds-alert')?.remove(); });

/* Confirm before a destructive form (data-confirm="Say what will happen"). Pages built on the kit use ds.confirm-dialog instead; this keeps the older forms working under a strict CSP. */
document.addEventListener('submit', (e) => {
    const f = e.target;
    if (f instanceof HTMLFormElement && f.dataset.confirm && !window.confirm(f.dataset.confirm)) { e.preventDefault(); e.stopImmediatePropagation(); }
}, true);

/* Listing filters (products/index): a <details> that is a toggle on a phone and the always-visible filter panel from 64rem, where its
   summary is hidden by CSS. A closed <details> hides its content, so on a wide screen it must be open whatever the server drew. */
(() => {
    const wide = window.matchMedia('(min-width: 64rem)');
    const sync = () => { if (wide.matches) $$('.ds-shop__filters-toggle').forEach((d) => { d.open = true; }); };
    sync();
    wide.addEventListener?.('change', sync);
    document.addEventListener('livewire:navigated', sync);
})();

import './discover.js';
/* The operator desk (R-43 W7). */
import "./operator.js";
import './checkout.js';
import "./product.js";
